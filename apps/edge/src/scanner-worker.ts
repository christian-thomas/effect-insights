import { Hono } from 'hono';
import { cors } from 'hono/cors';
import { zValidator } from '@hono/zod-validator';
import { launch, type Browser, type Page } from '@cloudflare/puppeteer';
import {
  scannerRequestSchema,
  type AccessibilityScannerResultInput,
  type ScannerResultInput,
} from '@tracewatch/types/schemas/scanner';

// axe-core is loaded into each crawled page via CDN. Bundling the
// ~500KB axe.min.js source into the worker bundle isn't worth the
// build complexity right now — jsdelivr is reliable and the Browser
// Rendering context has internet egress. If we ever want offline
// guarantees, switch to a Wrangler [[rules]] text-bundle import.
const AXE_CDN_URL = 'https://cdn.jsdelivr.net/npm/axe-core@4.11.4/axe.min.js';

type Bindings = {
  readonly BROWSER: Fetcher;
  readonly SCANNER_SECRET: string;
  readonly ENVIRONMENT: string;
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
  const { urls } = c.req.valid('json');

  const browser = await launch(c.env.BROWSER);

  try {
    const result = await runAxeScans(browser, urls);
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

  for (const url of urls) {
    const page = await browser.newPage();
    try {
      registerRequestCapture(page, requests, domainFqdn, url);

      await page.goto(url, { waitUntil: 'networkidle0', timeout: 30_000 });

      await captureCookies(page, cookies, domainFqdn, url);
      await captureAssets(page, scripts, 'script[src]', 'src', domainFqdn, url);
      await captureAssets(page, iframes, 'iframe[src]', 'src', domainFqdn, url);

      urlsScanned++;
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
): Promise<AccessibilityScannerResultInput> => {
  const findings: AccessibilityScannerResultInput['findings'] = [];
  let urlsScanned = 0;

  for (const url of urls) {
    const page = await browser.newPage();
    try {
      await page.goto(url, { waitUntil: 'networkidle0', timeout: 30_000 });

      // Inject axe-core from CDN, wait for it to register, then run.
      // page.evaluate runs in the page context so `window.axe` is the
      // globally-installed object after the script tag loads.
      await page.addScriptTag({ url: AXE_CDN_URL });

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
        const nodes: AccessibilityScannerResultInput['findings'][number]['nodes'] =
          violation.nodes.map((node) => {
            const result: AccessibilityScannerResultInput['findings'][number]['nodes'][number] = {};
            if (node.target !== undefined) result.target = [...node.target];
            if (node.html !== undefined) result.html = node.html;
            if (node.failureSummary !== undefined) result.failureSummary = node.failureSummary;
            return result;
          });

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
    } finally {
      await page.close();
    }
  }

  return {
    urlsDiscovered: urls.length,
    urlsScanned,
    findings,
  };
};

export default app;
