#!/usr/bin/env node
/**
 * Fails when a source file contains a Unicode replacement character or the
 * classic mojibake artifacts that appear when Thai UTF-8 is re-encoded through
 * a legacy code page.
 *
 * This is not paranoia: it happened twice in this repository. A file written by
 * a Windows shell that assumed CP1252 turned "กำลังโหลด" into "เน€เธ�…", the build
 * still passed (it is only text), and the broken strings even matched each other
 * in tests. The defect is invisible until a user reads the screen.
 *
 * Rules:
 *  - U+FFFD (replacement character) is always a defect: the original byte was lost.
 *  - U+20AC (euro), U+201A, U+0192, U+02C6, U+2026, U+2020, U+2030 in *.ts/*.html
 *    are suspicious: they are the CP1252 stand-ins for UTF-8 continuation bytes.
 *    A euro sign in a Thai UI is almost certainly mojibake, so it fails.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { extname, join, relative } from 'node:path';

const ROOT = new URL('../src', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1');
const EXTENSIONS = new Set(['.ts', '.html', '.css']);
const FORBIDDEN = [
  { char: '\uFFFD', label: 'U+FFFD replacement character (data loss)' },
  { char: '\u20AC', label: 'U+20AC euro sign (CP1252 mojibake)' },
  { char: '\u201A', label: 'U+201A single low quote (CP1252 mojibake)' },
  { char: '\u0192', label: 'U+0192 florin (CP1252 mojibake)' },
  { char: '\u02C6', label: 'U+02C6 circumflex (CP1252 mojibake)' },
  { char: '\u2026', label: 'U+2026 ellipsis used as mojibake' },
  { char: '\u2020', label: 'U+2020 dagger (CP1252 mojibake)' },
];

function walk(dir) {
  const found = [];

  for (const entry of readdirSync(dir)) {
    const path = join(dir, entry);

    if (statSync(path).isDirectory()) {
      found.push(...walk(path));
    } else if (EXTENSIONS.has(extname(path))) {
      found.push(path);
    }
  }

  return found;
}

const failures = [];

for (const path of walk(ROOT)) {
  const text = readFileSync(path, 'utf8');
  const lines = text.split(/\r?\n/);

  lines.forEach((line, index) => {
    for (const { char, label } of FORBIDDEN) {
      if (line.includes(char)) {
        failures.push(`${relative(process.cwd(), path)}:${index + 1} ${label}`);
      }
    }
  });
}

if (failures.length > 0) {
  console.error('Encoding check failed:\n  ' + failures.join('\n  '));
  console.error('\nThai text must be written as UTF-8 by an editor, never through a legacy shell.');
  process.exit(1);
}

console.log('Encoding check passed.');
