#!/usr/bin/env node
// Write a brotli copy, at the strongest level, beside each named file:
//
//   node scripts/shared-hosting/precompress.mjs <document-root> <path>...
//
// Each <path> is relative to <document-root>; a directory means every .js and
// .css file under it. The host's nginx compresses as it sends, at a level that
// left the dashboard's scripts about 10% larger than this (measured 2026-11-02),
// and .htaccess serves the .br copy instead where it exists and the browser
// accepts brotli. It fails rather than skipping when a named path is missing,
// so a renamed file cannot quietly turn the copy off. A file the copy would not
// make smaller gets none, and .htaccess then serves the file itself.
import { readFileSync, writeFileSync, statSync, readdirSync } from "node:fs";
import { join, relative } from "node:path";
import { brotliCompressSync, constants } from "node:zlib";

const [root, ...paths] = process.argv.slice(2);
if (!root || paths.length === 0) {
  console.error("usage: precompress.mjs <document-root> <path>...");
  process.exit(2);
}

function* files(path) {
  if (statSync(path).isDirectory()) {
    for (const entry of readdirSync(path)) yield* files(join(path, entry));
  } else if (/\.(js|css)$/.test(path)) {
    yield path;
  }
}

let count = 0;
let written = 0;
let before = 0;
let after = 0;
for (const path of paths) {
  const full = join(root, path);
  try {
    statSync(full);
  } catch {
    console.error(`precompress: ${path} is not in ${root}`);
    process.exit(1);
  }
  for (const file of files(full)) {
    const source = readFileSync(file);
    const packed = brotliCompressSync(source, {
      params: {
        [constants.BROTLI_PARAM_QUALITY]: constants.BROTLI_MAX_QUALITY,
        [constants.BROTLI_PARAM_SIZE_HINT]: source.length,
      },
    });
    count += 1;
    if (packed.length >= source.length) continue;
    writeFileSync(`${file}.br`, packed);
    written += 1;
    before += source.length;
    after += packed.length;
    if (written <= 3) console.log(`  ${relative(root, file)}: ${source.length} -> ${packed.length} bytes`);
  }
}
if (count === 0) {
  console.error("precompress: no .js or .css file under the named paths");
  process.exit(1);
}
console.log(`  ${written} of ${count} file(s) copied, ${before} -> ${after} bytes brotli`);
