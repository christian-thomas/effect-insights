import { env, createExecutionContext, waitOnExecutionContext } from 'cloudflare:test';
import { describe, it, expect, vi } from 'vitest';

// Stub axe-core's UMD bundle before the worker imports it. In production
// the Wrangler [[rules]] Text rule (wrangler.scanner.toml) resolves the
// .min.js file to a raw string at build time. vitest-pool-workers doesn't
// apply that rule, so without this mock the IIFE wrapper tries to set up
// DOM helpers in the workers runtime and explodes (elementsFromPoint).
vi.mock('axe-core/axe.min.js', () => ({
  default: '/* mocked axe-core source — replaced by Wrangler Text rule in prod */',
}));

// Stub @cloudflare/puppeteer before importing the worker. The Browser binding
// needs a real Chromium fleet to actually launch, so tests fake the launch +
// page surface and return pre-canned cookies / scripts / iframes / requests.
vi.mock('@cloudflare/puppeteer', () => {
  const handlers: Array<(req: { url: () => string }) => void> = [];
  const page = {
    on: (event: string, handler: (req: { url: () => string }) => void) => {
      if (event === 'request') handlers.push(handler);
    },
    goto: vi.fn().mockResolvedValue(undefined),
    cookies: vi.fn().mockResolvedValue([
      { name: '_ga_ABC', domain: '.example.test' },
      { name: '_fbp', domain: '.facebook.com' },
    ]),
    $$eval: vi.fn(async (selector: string) => {
      if (selector === 'script[src]') {
        // Fire a synthetic request capture so the page.on('request') path runs.
        handlers.forEach((handler) =>
          handler({ url: () => 'https://googletagmanager.com/gtm.js' }),
        );
        return ['https://googletagmanager.com/gtm.js', 'https://example.test/app.js'];
      }
      if (selector === 'iframe[src]') {
        return ['https://www.youtube.com/embed/abc'];
      }
      return [];
    }),
    addScriptTag: vi.fn().mockResolvedValue(undefined),
    // page.evaluate returns whatever the page-side function returns. For
    // tests we hand back a canned axe.run() shape — one violation.
    evaluate: vi.fn().mockResolvedValue({
      violations: [
        {
          id: 'color-contrast',
          impact: 'serious',
          description: 'Elements must have sufficient color contrast',
          help: 'Help text',
          helpUrl: 'https://dequeuniversity.com/rules/axe/4.x/color-contrast',
          tags: ['wcag2aa', 'wcag143'],
          nodes: [
            {
              target: ['#main > p'],
              html: '<p style="color: #ddd">low contrast</p>',
              failureSummary: 'Fix this',
            },
          ],
        },
      ],
    }),
    close: vi.fn().mockResolvedValue(undefined),
  };
  return {
    launch: vi.fn().mockResolvedValue({
      newPage: vi.fn().mockResolvedValue(page),
      close: vi.fn().mockResolvedValue(undefined),
    }),
  };
});

const { default: worker } = await import('../src/scanner-worker');

interface ScannerEnv {
  readonly BROWSER: Fetcher;
  readonly SCANNER_SECRET: string;
  readonly ENVIRONMENT: string;
}

const typedEnv = env as unknown as ScannerEnv;

const post = async (body: unknown, init?: RequestInit): Promise<Response> => {
  const ctx = createExecutionContext();
  const response = await worker.fetch(
    new Request('http://edge.test/scan', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', ...init?.headers },
      body: JSON.stringify(body),
    }),
    typedEnv,
    ctx,
  );
  await waitOnExecutionContext(ctx);
  return response;
};

const postA11y = async (body: unknown, init?: RequestInit): Promise<Response> => {
  const ctx = createExecutionContext();
  const response = await worker.fetch(
    new Request('http://edge.test/scan-a11y', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', ...init?.headers },
      body: JSON.stringify(body),
    }),
    typedEnv,
    ctx,
  );
  await waitOnExecutionContext(ctx);
  return response;
};

describe('scanner worker', () => {
  const validBody = { urls: ['https://example.test/'], domainFqdn: 'example.test' };

  it('rejects requests missing the scanner secret', async () => {
    const response = await post(validBody);
    expect(response.status).toBe(401);
  });

  it('rejects requests with a wrong secret', async () => {
    const response = await post(validBody, {
      headers: { 'X-Effect-Scanner-Secret': 'wrong' },
    });
    expect(response.status).toBe(401);
  });

  it('rejects malformed payloads with 400', async () => {
    const response = await post(
      { urls: 'not-an-array' },
      { headers: { 'X-Effect-Scanner-Secret': 'test_scanner_secret' } },
    );
    expect(response.status).toBe(400);
  });

  it('returns a ScannerResult shape with cookies, scripts, iframes, requests', async () => {
    const response = await post(validBody, {
      headers: { 'X-Effect-Scanner-Secret': 'test_scanner_secret' },
    });
    expect(response.status).toBe(200);

    const body = (await response.json()) as {
      urlsDiscovered: number;
      urlsScanned: number;
      cookies: Array<{ name: string; party: string }>;
      scripts: Array<{ name: string; party: string }>;
      iframes: Array<{ name: string }>;
      requests: Array<{ name: string }>;
    };

    expect(body.urlsDiscovered).toBe(1);
    expect(body.urlsScanned).toBe(1);

    const cookieNames = body.cookies.map((cookie) => cookie.name);
    expect(cookieNames).toContain('_ga_ABC');
    expect(cookieNames).toContain('_fbp');

    // Party classification: _ga_ABC on .example.test is first-party for
    // example.test; _fbp on .facebook.com is third-party.
    const ga = body.cookies.find((cookie) => cookie.name === '_ga_ABC');
    const fbp = body.cookies.find((cookie) => cookie.name === '_fbp');
    expect(ga?.party).toBe('first');
    expect(fbp?.party).toBe('third');

    expect(body.scripts.map((s) => s.name)).toEqual(
      expect.arrayContaining(['https://googletagmanager.com/gtm.js']),
    );
    expect(body.iframes.map((i) => i.name)).toEqual(['https://www.youtube.com/embed/abc']);
    expect(body.requests.length).toBeGreaterThan(0);
  });
});

describe('scanner worker — /scan-a11y', () => {
  const validBody = { urls: ['https://example.test/'], domainFqdn: 'example.test' };

  it('rejects requests missing the scanner secret', async () => {
    const response = await postA11y(validBody);
    expect(response.status).toBe(401);
  });

  it('rejects malformed payloads with 400', async () => {
    const response = await postA11y(
      { urls: 'not-an-array' },
      { headers: { 'X-Effect-Scanner-Secret': 'test_scanner_secret' } },
    );
    expect(response.status).toBe(400);
  });

  it('returns an axe-violation shape per finding', async () => {
    const response = await postA11y(validBody, {
      headers: { 'X-Effect-Scanner-Secret': 'test_scanner_secret' },
    });
    expect(response.status).toBe(200);

    const body = (await response.json()) as {
      urlsDiscovered: number;
      urlsScanned: number;
      findings: Array<{
        ruleId: string;
        impact: string;
        description: string;
        helpUrl: string;
        url: string;
        tags: Array<string>;
        nodes: Array<{ target?: Array<string>; html?: string; failureSummary?: string }>;
      }>;
    };

    expect(body.urlsDiscovered).toBe(1);
    expect(body.urlsScanned).toBe(1);
    expect(body.findings).toHaveLength(1);

    const finding = body.findings[0]!;
    expect(finding.ruleId).toBe('color-contrast');
    expect(finding.impact).toBe('serious');
    expect(finding.url).toBe('https://example.test/');
    expect(finding.tags).toContain('wcag2aa');
    expect(finding.nodes[0]?.target).toEqual(['#main > p']);
  });
});
