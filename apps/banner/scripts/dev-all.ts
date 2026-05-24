/// <reference types="bun-types" />

/**
 * Run the Vite build-watcher and the fixture host server in parallel.
 * Prefixes output so you can tell them apart and forwards Ctrl-C so
 * `bun run dev` (and turbo's parent dev task) cleans both up.
 */

import { spawn, type Subprocess } from 'bun';

interface Task {
  readonly name: string;
  readonly cmd: readonly string[];
}

const TASKS: readonly Task[] = [
  { name: 'vite', cmd: ['vite', 'build', '--watch'] },
  { name: 'host', cmd: ['bun', 'run', 'scripts/serve-fixtures.ts'] },
];

const COLORS: Record<string, string> = {
  vite: '\x1b[32m', // green
  host: '\x1b[34m', // blue
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

for (const task of TASKS) {
  const child = spawn({
    cmd: [...task.cmd],
    stdout: 'pipe',
    stderr: 'pipe',
    stdin: 'inherit',
  });
  children.push(child);
  void pipeWithPrefix(child.stdout, task.name, process.stdout);
  void pipeWithPrefix(child.stderr, task.name, process.stderr);
}

const exitCode = await Promise.race(children.map(async (child): Promise<number> => child.exited));
cleanup();
process.exit(exitCode);
