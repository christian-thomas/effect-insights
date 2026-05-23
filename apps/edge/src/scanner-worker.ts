import { Hono } from 'hono';
import { cors } from 'hono/cors';
import { zValidator } from '@hono/zod-validator';
import { launch, type Browser, type Page } from '@cloudflare/puppeteer';
import { scannerRequestSchema, type ScannerResultInput } from '@effect-cmp/types/schemas/scanner';

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

export default app;
