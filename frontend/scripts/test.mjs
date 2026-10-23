#!/usr/bin/env node
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

/**
 * Tiny cross-platform shim around the Angular unit-test builder.
 *
 * Angular 22's `ng test` CLI has no `--run` flag (Vitest is run once by
 * default outside a TTY), but the project contract uses `npm test -- --run`.
 * This wrapper drops the `--run` flag and forwards everything else.
 */
const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const angularCli = path.join(projectRoot, 'node_modules', '@angular', 'cli', 'bin', 'ng.js');

const forwardedArgs = process.argv.slice(2).filter((argument) => argument !== '--run');

const result = spawnSync(
  process.execPath,
  [angularCli, 'test', '--watch=false', ...forwardedArgs],
  {
    cwd: projectRoot,
    stdio: 'inherit',
  },
);

process.exit(result.status ?? 1);
