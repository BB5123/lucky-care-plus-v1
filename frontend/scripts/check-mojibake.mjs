#!/usr/bin/env node
// Repo-wide mojibake guard.
//
// Why this exists: Thai text and even punctuation have arrived corrupted twice in
// this repository, both times after a file was round-tripped through a shell on
// Windows (a UTF-8 byte sequence decoded through a legacy code page and re-encoded).
// The result is valid UTF-8, so nothing fails: the build passes, the tests pass, and
// the user reads garbage.
//
// The high-signal marker is U+20AC (the euro sign). Real Thai source text contains
// U+0E40 and U+0E19 constantly, but essentially never a euro sign, so a euro sign in
// a tracked text file means a byte sequence was mis-decoded.
//
// One file is allowed to contain the sequence on purpose: AGENTS.md documents the
// failure mode with an example, so that line is the single exception.
//
// Usage: node scripts/check-mojibake.mjs [--fix]
//   --fix replaces the three-character sequence U+0E42 U+20AC U+201D with a plain "-"

import { readFileSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { extname, join } from 'node:path';

const TEXT_EXTENSIONS = new Set([
  '.md', '.php', '.ts', '.html', '.dart', '.json', '.yml', '.yaml',
  '.sh', '.txt', '.scss', '.css', '.mjs', '.js', '.sql',
]);

const SKIP_PREFIXES = [
  'backend/vendor/',
  'frontend/node_modules/',
  'mobile/build/',
  'mobile/.dart_tool/',
  'backend/database/knowledge/health_kb.sqlite',
];

// AGENTS.md and check-encoding.mjs document the corruption with a literal example.
const ALLOWED = new Set(['AGENTS.md', 'frontend/scripts/check-encoding.mjs']);

const EURO = '\u20AC';
const DASH_MOJIBAKE = '\u0E42\u20AC\u201D';
const fix = process.argv.includes('--fix');

// Always enumerate from the repository root so paths (and the allowlist) are stable
// no matter which directory the check is invoked from - CI runs it from `frontend/`.
const repoRoot = execFileSync('git', ['rev-parse', '--show-toplevel'], { encoding: 'utf8' }).trim();

const tracked = execFileSync('git', ['ls-files'], { cwd: repoRoot, encoding: 'utf8' })
  .split('\n')
  .filter(Boolean);

const problems = [];

for (const path of tracked) {
  if (SKIP_PREFIXES.some((prefix) => path.startsWith(prefix))) continue;
  if (!TEXT_EXTENSIONS.has(extname(path))) continue;
  if (ALLOWED.has(path)) continue;

  let text;
  try {
    text = readFileSync(join(repoRoot, path), 'utf8');
  } catch {
    problems.push({ path, reason: 'not readable as UTF-8' });
    continue;
  }

  if (!text.includes(EURO)) continue;

  const lines = text.split('\n');
  const hits = lines
    .map((line, index) => ({ line: index + 1, content: line }))
    .filter((entry) => entry.content.includes(EURO));

  problems.push({
    path,
    reason: `${hits.length} line(s) contain U+20AC`,
    lines: hits.slice(0, 5).map((entry) => ({
      number: entry.line,
      excerpt: entry.content.trim().slice(0, 80),
    })),
  });

  if (fix && text.includes(DASH_MOJIBAKE)) {
    writeFileSync(join(repoRoot, path), text.split(DASH_MOJIBAKE).join('-'), 'utf8');
  }
}

if (problems.length === 0) {
  console.log(`Mojibake check passed (${tracked.length} tracked files).`);
  process.exit(0);
}

console.error('Mojibake detected - a UTF-8 file was re-encoded through a legacy code page.');
console.error('Rule: never write Thai text (or em dashes) through a shell. Use the file editor.');
console.error('');
for (const problem of problems) {
  console.error(`  ${problem.path}: ${problem.reason}`);
  for (const line of problem.lines ?? []) {
    console.error(`    line ${line.number}: ${line.excerpt}`);
  }
}
console.error('');
console.error('Re-run with --fix to replace the dash sequence, then fix Thai text by hand.');

process.exit(1);
