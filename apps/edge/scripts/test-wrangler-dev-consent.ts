/// <reference types="bun-types" />

/**
 * End-to-end smoke test against a real `wrangler dev` instance of the
 * consent worker. Complements the vitest-pool-workers unit tests by
 * exercising the actual wrangler runtime + toml config, catching
 * misconfigurations that the in-process miniflare doesn't see.
 *
 * Boots `wrangler dev --config wrangler.consent.toml --port 8788 --local`
 * itself; polls until the worker responds; runs the inline assertions;
 * tears wrangler down on exit. Exit code 0 on pass, 1 on any failure.
 *
 * Usage:
 *   bun run test:e2e:wrangler
 *
 * Limitations: --local mode emulates KV / D1 / R2 / Durable Objects via
 * miniflare. Bindings without local emulation (e.g. the ratelimit
 * binding declared in wrangler.consent.toml at time of writing) are
 * non-functional in this harness — those code paths are exercised by
 * the in-process unit tests with a stubbed binding instead.
 */

import { spawn, type Subprocess } from 'bun';

const PORT = 8788;
const BASE_URL = `http://127.0.0.1:${PORT}`;
const READY_TIMEOUT_MS = 30_000;
const READY_POLL_INTERVAL_MS = 500;

interface TestCase {
  readonly name: string;
  readonly run: () => Promise<void>;
}

const expect = (condition: unknown, message: string): void => {
  if (!condition) {
    throw new Error(`assertion failed: ${message}`);
  }
};

const tests: readonly TestCase[] = [
  {
    name: 'POST /consent with a malformed payload returns 400 (zod validation)',
    run: async (): Promise<void> => {
      // We deliberately don't smoke-test the success path because the local
      // wrangler.consent.toml ships with the D1 binding commented out so
      // the operator can paste in their own database_id. Without D1 bound
      // the valid path returns 500 (persist_failed). The unit tests cover
      // the successful write via the in-process miniflare D1.
      const response = await fetch(`${BASE_URL}/consent`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: 'not-a-uuid' }),
      });
      expect(response.status === 400, `expected 400, got ${response.status}`);
    },
  },
  {
    name: 'POST /rum with a valid beacon returns 204',
    run: async (): Promise<void> => {
      const response = await fetch(`${BASE_URL}/rum`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          domainPublicKey: 'pk_test_smoke',
          paintMs: 12,
          hadStoredConsent: false,
        }),
      });
      expect(response.status === 204, `expected 204, got ${response.status}`);
    },
  },
  {
    name: 'POST /rum with a malformed payload returns 400',
    run: async (): Promise<void> => {
      const response = await fetch(`${BASE_URL}/rum`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ paintMs: 'not-a-number' }),
      });
      expect(response.status === 400, `expected 400, got ${response.status}`);
    },
  },
  {
    name: 'GET /nonsense returns 404',
    run: async (): Promise<void> => {
      const response = await fetch(`${BASE_URL}/nonsense`);
      expect(response.status === 404, `expected 404, got ${response.status}`);
    },
  },
  {
    name: 'OPTIONS / returns a CORS preflight response',
    run: async (): Promise<void> => {
      const response = await fetch(`${BASE_URL}/consent`, {
        method: 'OPTIONS',
        headers: {
          Origin: 'https://example.test',
          'Access-Control-Request-Method': 'POST',
          'Access-Control-Request-Headers': 'Content-Type',
        },
      });
      expect(
        response.status === 204 || response.status === 200,
        `unexpected status ${response.status}`,
      );
      expect(
        response.headers.get('access-control-allow-origin') !== null,
        'missing access-control-allow-origin header',
      );
    },
  },
];

const waitForWorker = async (): Promise<void> => {
  const start = Date.now();
  while (Date.now() - start < READY_TIMEOUT_MS) {
    try {
      // Any reachable response counts as "ready" — even 404 / 400.
      // eslint-disable-next-line no-await-in-loop -- polling loop, sequential by design
      const response = await fetch(`${BASE_URL}/__ready__`);
      if (response.status > 0) {
        return;
      }
    } catch {
      // wrangler not listening yet
    }
    // eslint-disable-next-line no-await-in-loop -- intentional backoff between polls
    await Bun.sleep(READY_POLL_INTERVAL_MS);
  }
  throw new Error(`wrangler dev did not become ready within ${READY_TIMEOUT_MS}ms`);
};

let child: Subprocess | null = null;

const teardown = (): void => {
  if (child && !child.killed) {
    child.kill('SIGTERM');
  }
};

process.on('SIGINT', (): void => {
  teardown();
  process.exit(130);
});
process.on('SIGTERM', (): void => {
  teardown();
  process.exit(143);
});

const main = async (): Promise<number> => {
  console.info(`[wrangler-dev] spawning wrangler dev on :${PORT}…`);
  child = spawn({
    cmd: [
      'wrangler',
      'dev',
      '--config',
      'wrangler.consent.toml',
      '--port',
      String(PORT),
      '--local',
    ],
    stdout: 'ignore',
    stderr: 'inherit',
    stdin: 'inherit',
  });

  try {
    await waitForWorker();
    console.info(`[wrangler-dev] worker is up, running ${tests.length} smoke tests`);

    let failed = 0;
    for (const test of tests) {
      try {
        // eslint-disable-next-line no-await-in-loop -- run tests sequentially so output stays readable
        await test.run();
        console.info(`  ✓ ${test.name}`);
      } catch (caught) {
        failed += 1;
        const message = caught instanceof Error ? caught.message : String(caught);
        console.error(`  ✗ ${test.name}: ${message}`);
      }
    }

    if (failed > 0) {
      console.error(`[wrangler-dev] ${failed} test(s) failed`);
      return 1;
    }
    console.info(`[wrangler-dev] all ${tests.length} tests passed`);
    return 0;
  } finally {
    teardown();
  }
};

const exitCode = await main();
process.exit(exitCode);
