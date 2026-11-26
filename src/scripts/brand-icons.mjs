#!/usr/bin/env node
//
// Rebuild every raster brand asset from the master SVGs in docs/brand/.
//
//   node scripts/brand-icons.mjs        (run from src/)
//
// Writes public/app-icons/{icon,maskable,apple-touch-icon,badge}*.png and
// public/favicon.ico. The SVG sources are never edited here; change the masters
// and rerun. calendar-96.png and checkin-96.png are shortcut icons, not the
// logo, and are left alone. Uses sharp, which is already a dependency of Next.

import { mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

import sharp from "sharp";

const here = dirname(fileURLToPath(import.meta.url));
const brandDir = join(here, "..", "..", "docs", "brand");
const iconsDir = join(here, "..", "public", "app-icons");

const BLUE = "#0F4C75";
const NS = 'xmlns="http://www.w3.org/2000/svg"';

const master = (name) => readFileSync(join(brandDir, name), "utf8");

// The e and dot of a symbol master, without its tile and wrapper.
function art(name) {
  const body = master(name)
    .replace(/^[\s\S]*?<\/title>/, "")
    .replace(/<\/svg>\s*$/, "");
  return body.replace(/\s*<rect[^>]*\/>/, "");
}

// The same art, scaled about the centre of the 64 grid.
const scaled = (inner, k) =>
  `<g transform="translate(32 32) scale(${k}) translate(-32 -32)">${inner}</g>`;

const standard = master("ethr-symbol.svg");
const small = master("ethr-symbol-small.svg");
const symbolArt = art("ethr-symbol.svg");

const fullBleed = (k) =>
  `<svg ${NS} viewBox="0 0 64 64"><rect width="64" height="64" fill="${BLUE}"/>${scaled(symbolArt, k)}</svg>`;

// White e and dot, no tile, the glyph (42 wide) filling 80% of the canvas.
// Android reads only the alpha channel, so the dot is white as well.
const badgeSvg = `<svg ${NS} viewBox="0 0 72 72"><g transform="translate(36 36) scale(${(72 * 0.8) / 42}) translate(-32 -32)">${symbolArt.replace(
  /#E8A838/gi,
  "#FFFFFF",
)}</g></svg>`;

const png = (svg, size) =>
  sharp(Buffer.from(svg), { density: 384 })
    .resize(size, size)
    .png({ compressionLevel: 9 })
    .toBuffer();

async function write(file, svg, size) {
  writeFileSync(join(iconsDir, file), await png(svg, size));
  console.log(`${file} ${size}x${size}`);
}

mkdirSync(iconsDir, { recursive: true });

for (const size of [72, 96, 128, 144, 152, 192, 384, 512]) {
  await write(`icon-${size}.png`, standard, size);
}
for (const size of [192, 512]) {
  await write(`maskable-${size}.png`, fullBleed(0.85), size);
}
await write("apple-touch-icon.png", fullBleed(0.9), 180);
await write("badge-72.png", badgeSvg, 72);

// PNG-in-ICO: ICONDIR, one ICONDIRENTRY per image, then the PNG payloads.
const entries = [
  [16, await png(small, 16)],
  [32, await png(small, 32)],
  [48, await png(standard, 48)],
];
const header = Buffer.alloc(6);
header.writeUInt16LE(0, 0); // reserved
header.writeUInt16LE(1, 2); // type: icon
header.writeUInt16LE(entries.length, 4);
let offset = 6 + 16 * entries.length;
const dir = entries.map(([size, data]) => {
  const e = Buffer.alloc(16);
  e.writeUInt8(size, 0); // width
  e.writeUInt8(size, 1); // height
  e.writeUInt8(0, 2); // palette
  e.writeUInt8(0, 3); // reserved
  e.writeUInt16LE(1, 4); // colour planes
  e.writeUInt16LE(32, 6); // bits per pixel
  e.writeUInt32LE(data.length, 8);
  e.writeUInt32LE(offset, 12);
  offset += data.length;
  return e;
});
const ico = Buffer.concat([header, ...dir, ...entries.map(([, d]) => d)]);
writeFileSync(join(here, "..", "public", "favicon.ico"), ico);
console.log(
  `favicon.ico ${ico.length} bytes, ${entries.map(([s]) => s).join("/")}`,
);
