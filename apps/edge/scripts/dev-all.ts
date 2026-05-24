/// <reference types="bun-types" />

/**
 * Spawn all three wrangler-dev workers in parallel, prefix their stdout
 * with the worker name, and forward signals so Ctrl-C cleans everything
 * up. Used by `bun run dev` (also pulled in by turbo from the workspace
 * root) so the whole local edge stack starts with one command.
 *
 * Run a single worker on its own (more readable logs for debugging) via
 * `bun run dev:config` / `dev:consent` / `dev:scanner` instead.
 */

import { spawn, type Subprocess } from 'bun';

interface Worker {
  readonly name: string;
  readonly config: string;
  readonly port: number;
  // wrangler dev attaches a V8 inspector to debug.port 9229 by default —
  // running three instances on the same machine collides, so each worker
  // gets its own inspector port too.
  readonly inspectorPort: number;
}

const WORKERS: readonly Worker[] = [
  { name: 'config', config: 'wrangler.config.toml', port: 8787, inspectorPort: 9229 },
  { name: 'consent', config: 'wrangler.consent.toml', port: 8788, inspectorPort: 9230 },
  { name: 'scanner', config: 'wrangler.scanner.toml', port: 8789, inspectorPort: 9231 },
];

const COLORS: Record<string, string> = {
  config: '\x1b[36m', // cyan
  consent: '\x1b[33m', // yellow
  scanner: '\x1b[35m', // magenta
};
const RESET = '\x1b[0m';

const pipeWithPrefix = async (
  stream: ReadableStream<Uint8Array>,
  name: string,
  out: typeof process.stdout,
): Promise<void> => {
  const color = COLORS[name] ?? '';
  const prefix = `${color}[${name}]${RESET} `;
  const decoder = new TextDecoder();
  let buffer = '';
  for await (const chunk of stream as unknown as AsyncIterable<Uint8Array>) {
    buffer += decoder.decode(chunk, { stream: true });
    const lines = buffer.split('\n');
    buffer = lines.pop() ?? '';
    for (const line of lines) {
      out.write(`${prefix}${line}\n`);
    }
  }
  if (buffer.length > 0) {
    out.write(`${prefix}${buffer}\n`);
  }
};

const children: Subprocess[] = [];

const cleanup = (): void => {
  for (const child of children) {
    if (!child.killed) {
      child.kill('SIGTERM');
    }
  }
};

process.on('SIGINT', (): void => {
  cleanup();
  process.exit(130);
});
process.on('SIGTERM', (): void => {
  cleanup();
  process.exit(143);
});

for (const worker of WORKERS) {
  const child = spawn({
    cmd: [
      'wrangler',
      'dev',
      '--config',
      worker.config,
      '--port',
      String(worker.port),
      '--inspector-port',
      String(worker.inspectorPort),
    ],
    stdout: 'pipe',
    stderr: 'pipe',
    stdin: 'inherit',
  });
  children.push(child);
  void pipeWithPrefix(child.stdout, worker.name, process.stdout);
  void pipeWithPrefix(child.stderr, worker.name, process.stderr);
}

// Wait for any worker to exit; if one dies, take the others with it so the
// turbo task fails-loud rather than silently losing a worker.
const exitCode = await Promise.race(children.map(async (child): Promise<number> => child.exited));
cleanup();
process.exit(exitCode);
