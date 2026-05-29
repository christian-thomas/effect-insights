import { Hono } from 'hono';
import { cors } from 'hono/cors';
import { zValidator } from '@hono/zod-validator';
import { launch, type Browser, type Page } from '@cloudflare/puppeteer';
import {
  scannerRequestSchema,
  type AccessibilityScannerResultInput,
  type ScannerResultInput,
} from '@effectinsight/types/schemas/scanner';

// axe-core's UMD bundle, imported as a string at build time via the
// Wrangler [[rules]] Text rule in wrangler.scanner.toml. Bundling it
// directly into the worker (~565KB) removes the runtime dependency on
// jsdelivr — scans are now offline-safe relative to third-party CDN
// availability, and the version we run is pinned to package.json
// rather than whatever the CDN happens to serve at request time.
import axeSource from 'axe-core/axe.min.js';

type Bindings = {
  readonly BROWSER: Fetcher;
  readonly SCANNER_SECRET: string;
  readonly ENVIRONMENT: string;
  // Cloudflare R2 bucket for accessibility-scan element screenshots.
  // The worker writes PNG bytes directly here; Laravel serves them back
  // to the admin via the s3-driver `a11y_screenshots` disk pointing at
  // the same bucket.
  readonly A11Y_SCREENSHOTS: R2Bucket;
};

const app = new Hono<{ Bindings: Bindings }>();

app.use(
  '*',
  cors({
    origin: '*',
    allowMethods: ['POST', 'OPTIONS'],
    allowHeaders: ['Content-Type', 'X-Effect-Scanner-Secret'],
  }),
);

// Shared-secret guard. The Laravel side sends X-Effect-Scanner-Secret and the
// worker rejects anything missing or mismatched. Constant-time compare to keep
// timing attacks off the table.
app.use('*', async (c, next) => {
  if (c.req.method === 'OPTIONS') {
    return next();
  }
  const provided = c.req.header('X-Effect-Scanner-Secret') ?? '';
  const expected = c.env.SCANNER_SECRET ?? '';
  if (expected === '' || !timingSafeEqual(provided, expected)) {
    return c.json({ error: 'unauthorized' }, 401);
  }
  return next();
});

const timingSafeEqual = (a: string, b: string): boolean => {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let index = 0; index < a.length; index++) {
    diff |= a.charCodeAt(index) ^ b.charCodeAt(index);
  }
  return diff === 0;
};

app.post('/scan', zValidator('json', scannerRequestSchema), async (c) => {
  // organisationId/scanId are validated by the schema but the cookie
  // scanner doesn't need them — only /scan-a11y uses them for R2 keys.
  const { urls, domainFqdn } = c.req.valid('json');

  const browser = await launch(c.env.BROWSER);

  try {
    const result = await crawl(browser, urls, domainFqdn);
    return c.json(result satisfies ScannerResultInput);
  } catch (error: unknown) {
    return c.json(
      {
        error: 'scan_failed',
        message: error instanceof Error ? error.message : String(error),
      },
      500,
    );
  } finally {
    await browser.close();
  }
});

// Accessibility (axe-core) scan. Same shared-secret auth + same
// request body shape as /scan but injects axe-core after navigation
// and returns violations in a flat list. Heavier per-URL (axe.run
// is 2-5s) so the runner side gives this a longer HTTP timeout.
app.post('/scan-a11y', zValidator('json', scannerRequestSchema), async (c) => {
  const { urls, organisationId, scanId } = c.req.valid('json');

  const browser = await launch(c.env.BROWSER);

  try {
    const result = await runAxeScans(
      browser,
      urls,
      c.env.A11Y_SCREENSHOTS,
      organisationId,
      scanId,
    );
    return c.json(result satisfies AccessibilityScannerResultInput);
  } catch (error: unknown) {
    return c.json(
      {
        error: 'scan_failed',
        message: error instanceof Error ? error.message : String(error),
      },
      500,
    );
  } finally {
    await browser.close();
  }
});

const crawl = async (
  browser: Browser,
  urls: ReadonlyArray<string>,
  domainFqdn: string,
): Promise<ScannerResultInput> => {
  const cookies = new Map<string, ScannerResultInput['cookies'][number]>();
  const scripts = new Map<string, ScannerResultInput['scripts'][number]>();
  const iframes = new Map<string, ScannerResultInput['iframes'][number]>();
  const requests = new Map<string, ScannerResultInput['requests'][number]>();

  let urlsScanned = 0;
  const failedUrls: string[] = [];

  for (const url of urls) {
    const page = await browser.newPage();
    try {
      registerRequestCapture(page, requests, domainFqdn, url);

      await page.goto(url, { waitUntil: 'networkidle0', timeout: 30_000 });

      await captureCookies(page, cookies, domainFqdn, url);
      await captureAssets(page, scripts, 'script[src]', 'src', domainFqdn, url);
      await captureAssets(page, iframes, 'iframe[src]', 'src', domainFqdn, url);

      urlsScanned++;
    } catch (error: unknown) {
      // Per-URL failures are isolated — one bad page must not poison
      // the other URLs in the chunk. The chunk endpoint stays 200 OK
      // and Laravel records the URL in scans.failed_urls.
      failedUrls.push(url);
      console.warn(
        `[scanner] failed to scan ${url}: ${error instanceof Error ? error.message : String(error)}`,
      );
    } finally {
      await page.close();
    }
  }

  return {
    urlsDiscovered: urls.length,
    urlsScanned,
    cookies: [...cookies.values()],
    scripts: [...scripts.values()],
    iframes: [...iframes.values()],
    requests: [...requests.values()],
    failedUrls,
  };
};

const registerRequestCapture = (
  page: Page,
  bucket: Map<string, ScannerResultInput['requests'][number]>,
  domainFqdn: string,
  firstSeenUrl: string,
): void => {
  page.on('request', (request) => {
    const url = request.url();
    if (bucket.has(url)) return;
    bucket.set(url, {
      name: url,
      party: classifyParty(url, domainFqdn),
      firstSeenUrl,
    });
  });
};

const captureCookies = async (
  page: Page,
  bucket: Map<string, ScannerResultInput['cookies'][number]>,
  domainFqdn: string,
  firstSeenUrl: string,
): Promise<void> => {
  const pageCookies = await page.cookies();
  for (const cookie of pageCookies) {
    const key = `${cookie.name}|${cookie.domain}`;
    if (bucket.has(key)) continue;
    bucket.set(key, {
      name: cookie.name,
      domain: cookie.domain ?? null,
      party: classifyParty(`https://${cookie.domain ?? ''}`, domainFqdn),
      firstSeenUrl,
    });
  }
};

const captureAssets = async (
  page: Page,
  bucket: Map<string, ScannerResultInput['scripts'][number]>,
  selector: string,
  attribute: string,
  domainFqdn: string,
  firstSeenUrl: string,
): Promise<void> => {
  const sources = await page.$$eval(
    selector,
    (elements, attr) =>
      (elements as Element[])
        .map((el) => el.getAttribute(attr))
        .filter((value): value is string => value !== null && value !== ''),
    attribute,
  );

  for (const source of sources) {
    if (bucket.has(source)) continue;
    bucket.set(source, {
      name: source,
      party: classifyParty(source, domainFqdn),
      firstSeenUrl,
    });
  }
};

const classifyParty = (url: string, domainFqdn: string): 'first' | 'third' => {
  try {
    const parsed = new URL(url);
    const host = parsed.hostname.toLowerCase();
    const apex = domainFqdn.toLowerCase();
    return host === apex || host.endsWith(`.${apex}`) ? 'first' : 'third';
  } catch {
    return 'third';
  }
};

// --- accessibility -----------------------------------------------------------

interface AxeRunResult {
  violations: Array<AxeViolation>;
}

interface AxeViolation {
  id: string;
  impact: 'critical' | 'serious' | 'moderate' | 'minor' | null;
  description: string;
  help: string;
  helpUrl: string;
  tags: Array<string>;
  nodes: Array<{
    target?: Array<string>;
    html?: string;
    failureSummary?: string;
  }>;
}

const runAxeScans = async (
  browser: Browser,
  urls: ReadonlyArray<string>,
  screenshotBucket: R2Bucket,
  organisationId: string,
  scanId: string,
): Promise<AccessibilityScannerResultInput> => {
  const findings: AccessibilityScannerResultInput['findings'] = [];
  let urlsScanned = 0;
  const failedUrls: string[] = [];

  for (const url of urls) {
    const page = await browser.newPage();
    try {
      await page.goto(url, { waitUntil: 'networkidle0', timeout: 30_000 });

      // Inject the bundled axe-core source into the page. content:
      // hands the script tag the raw JS string we imported at build
      // time, no network round-trip. page.evaluate runs in the page
      // context so `window.axe` is the globally-installed object
      // once the script tag has executed.
      await page.addScriptTag({ content: axeSource });

      const result = (await page.evaluate(async () => {
        // Inside page.evaluate `window` resolves to the page's window.
        // The TS types for @cloudflare/puppeteer's page context don't
        // expose a DOM global, so we cast through unknown.
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const axe = (globalThis as unknown as { axe?: any }).axe;
        if (!axe || typeof axe.run !== 'function') {
          return { violations: [] };
        }
        return await axe.run();
      })) as AxeRunResult;

      for (const violation of result.violations) {
        const nodes: AccessibilityScannerResultInput['findings'][number]['nodes'] = [];
        for (let nodeIndex = 0; nodeIndex < violation.nodes.length; nodeIndex++) {
          const node = violation.nodes[nodeIndex];
          if (node === undefined) continue;
          const finding: AccessibilityScannerResultInput['findings'][number]['nodes'][number] = {};
          if (node.target !== undefined) finding.target = [...node.target];
          if (node.html !== undefined) finding.html = node.html;
          if (node.failureSummary !== undefined) finding.failureSummary = node.failureSummary;

          // Only screenshot the first node per violation. axe sometimes
          // returns dozens of nodes per rule; one image is enough.
          const selector = node.target?.[0];
          if (nodeIndex === 0 && selector !== undefined) {
            const screenshotKey = await captureAndStoreElementScreenshot(
              page,
              selector,
              screenshotBucket,
              organisationId,
              scanId,
            );
            if (screenshotKey !== undefined) finding.screenshotKey = screenshotKey;
          }
          nodes.push(finding);
        }

        findings.push({
          ruleId: violation.id,
          // axe occasionally emits null impact on advisory rules. Default
          // to 'minor' so the Laravel side has a valid enum value.
          impact: violation.impact ?? 'minor',
          description: violation.description,
          help: violation.help,
          helpUrl: violation.helpUrl,
          url,
          tags: [...violation.tags],
          nodes,
        });
      }

      urlsScanned++;
    } catch (error: unknown) {
      // Per-URL isolation — same shape as crawl().
      failedUrls.push(url);
      console.warn(
        `[scanner-a11y] failed to scan ${url}: ${error instanceof Error ? error.message : String(error)}`,
      );
    } finally {
      await page.close();
    }
  }

  return {
    urlsDiscovered: urls.length,
    urlsScanned,
    findings,
    failedUrls,
  };
};

/**
 * Resolves a CSS selector to an element, screenshots it, uploads the
 * PNG to R2, and returns the bucket key. Best-effort: any failure
 * (unresolved selector, hidden element, zero-size box, puppeteer
 * error, R2 put error) returns undefined so the scan continues without
 * a screenshot for that violation. The handle is always disposed so we
 * don't leak browser-side memory between pages.
 *
 * Uploading directly to R2 here (rather than base64-inlining the bytes
 * in the worker→Laravel response) avoids blowing PHP's memory limit
 * during json_decode on dense pages.
 */
const captureAndStoreElementScreenshot = async (
  page: Page,
  selector: string,
  bucket: R2Bucket,
  organisationId: string,
  scanId: string,
): Promise<string | undefined> => {
  try {
    const handle = await page.$(selector);
    if (handle === null) return undefined;
    try {
      const bbox = await handle.boundingBox();
      if (bbox === null || bbox.width <= 0 || bbox.height <= 0) return undefined;
      // Default `encoding` ('binary') returns a Uint8Array we can hand
      // straight to R2.put without round-tripping through base64.
      const result = await handle.screenshot({ type: 'png' });
      if (!(result instanceof Uint8Array)) return undefined;

      const key = `${organisationId}/${scanId}/${crypto.randomUUID()}.png`;
      await bucket.put(key, result, { httpMetadata: { contentType: 'image/png' } });
      return key;
    } finally {
      await handle.dispose();
    }
  } catch {
    return undefined;
  }
};

export default app;
