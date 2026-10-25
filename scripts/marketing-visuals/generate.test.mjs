/**
 * node --test scripts/marketing-visuals/generate.test.mjs
 *
 * No network: the adapters are never called. Fixtures are built in a
 * temporary directory from bytes this file writes itself.
 */

import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import zlib from "node:zlib";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";

import {
  PROMPTS_FILE,
  checkImage,
  composePrompt,
  detectFormat,
  loadManifest,
  loadPromptPack,
  parseArgs,
  parseImageHeader,
  runDryRun,
  runImport,
  upsertManifestEntry,
} from "./generate.mjs";

const SCRIPT = path.join(path.dirname(fileURLToPath(import.meta.url)), "generate.mjs");

// ------------------------------------------------------------------ fixtures

/** A structurally valid JPEG: SOI, APP0, SOF0 declaring the size, SOS, EOI. */
export function tinyJpeg(width, height, padTo = 0) {
  const sof = [
    0xff, 0xc0, 0x00, 0x11, 0x08,
    (height >> 8) & 0xff, height & 0xff,
    (width >> 8) & 0xff, width & 0xff,
    0x03, 0x01, 0x22, 0x00, 0x02, 0x11, 0x01, 0x03, 0x11, 0x01,
  ];
  const app0 = [0xff, 0xe0, 0x00, 0x10, 0x4a, 0x46, 0x49, 0x46, 0x00, 0x01, 0x01, 0x00, 0x00, 0x01, 0x00, 0x01, 0x00, 0x00];
  const head = Buffer.from([0xff, 0xd8, ...app0, ...sof, 0xff, 0xda, 0x00, 0x08, 0x01, 0x01, 0x00, 0x00, 0x3f, 0x00]);
  const padding = padTo > head.length + 2 ? Buffer.alloc(padTo - head.length - 2, 0x55) : Buffer.alloc(0);
  return Buffer.concat([head, padding, Buffer.from([0xff, 0xd9])]);
}

/** A structurally valid PNG: signature, IHDR with CRC, an empty IDAT, IEND. */
export function tinyPng(width, height) {
  const chunk = (type, data) => {
    const typeBytes = Buffer.from(type, "ascii");
    const length = Buffer.alloc(4);
    length.writeUInt32BE(data.length);
    const crc = Buffer.alloc(4);
    crc.writeUInt32BE(zlib.crc32(Buffer.concat([typeBytes, data])));
    return Buffer.concat([length, typeBytes, data, crc]);
  };
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(width, 0);
  ihdr.writeUInt32BE(height, 4);
  ihdr[8] = 8; ihdr[9] = 2; ihdr[10] = 0; ihdr[11] = 0; ihdr[12] = 0;
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk("IHDR", ihdr),
    chunk("IDAT", zlib.deflateSync(Buffer.alloc(0))),
    chunk("IEND", Buffer.alloc(0)),
  ]);
}

function tempDir() {
  return fs.mkdtempSync(path.join(os.tmpdir(), "ethr-marketing-visuals-"));
}

const pack = loadPromptPack(PROMPTS_FILE);

// --------------------------------------------------------------- prompt pack

test("the prompt pack holds every scene the landing page needs", () => {
  const slugs = pack.scenes.map((s) => `${s.kind}/${s.slug}`);
  const expected = [
    "hero/hero",
    "problems/paper_attendance", "problems/manual_payroll", "problems/leave_spreadsheets",
    "problems/branches_offline", "problems/bilingual_staff", "problems/contracts_expiring",
    "capabilities/records", "capabilities/time", "capabilities/payroll",
    "capabilities/self_service", "capabilities/analytics",
  ];
  assert.deepEqual(slugs, expected);
  for (const scene of pack.scenes) {
    assert.match(scene.alt_am, /[ሀ-፿]/, `${scene.slug} alt_am is Ethiopic`);
    for (const text of [scene.alt, scene.alt_am]) {
      assert.doesNotMatch(text, /[—–!]|[\u{1F300}-\u{1FAFF}]/u, `${scene.slug} alt follows house style`);
    }
    assert.ok(scene.width >= 1920, `${scene.slug} targets 1920 wide`);
  }
});

test("dry run composes one prompt per scene with style, scene and negative cues", async () => {
  const lines = [];
  const count = await runDryRun(parseArgs(["--dry-run", "--provider", "replicate"]), { pack, log: (l) => lines.push(l) });
  assert.equal(count, pack.scenes.length);
  const output = lines.join("\n");
  for (const scene of pack.scenes) {
    assert.ok(output.includes(`=== ${scene.slug} (`), `prints ${scene.slug}`);
    const composed = composePrompt(pack, scene);
    assert.ok(composed.startsWith(pack.style.trim()));
    assert.ok(composed.includes(scene.prompt.trim()));
    assert.ok(composed.includes(`Avoid: ${pack.negative.trim()}`));
    assert.ok(output.includes(composed));
  }
  assert.equal((output.match(/^=== /gm) ?? []).length, pack.scenes.length);
});

test("the dry run needs no key and no network from the command line", () => {
  const env = { ...process.env };
  for (const key of ["REPLICATE_API_TOKEN", "GEMINI_API_KEY", "OPENAI_API_KEY", "HIGGSFIELD_API_KEY"]) delete env[key];
  const run = spawnSync(process.execPath, [SCRIPT, "--dry-run", "--provider", "replicate"], { env, encoding: "utf8" });
  assert.equal(run.status, 0, run.stderr);
  assert.ok(run.stdout.includes(`Dry run: ${pack.scenes.length} prompt(s)`));
});

// ---------------------------------------------------------------- arguments

test("a key on the command line is refused", () => {
  assert.throws(() => parseArgs(["--provider", "replicate", "--token", "r8_abc"]), /Refusing a credential/);
  assert.throws(() => parseArgs(["--api-key=sk-whatever"]), /Refusing a credential/);
  assert.throws(() => parseArgs(["--slug", "r8_1234567890abcdef"]), /looks like an API key/);
  assert.throws(() => parseArgs(["--provider", "replicate", "--replicate-key", "x"]), /Refusing a credential/);
});

test("generating without the provider's key exits 2 with one line", () => {
  const env = { ...process.env };
  delete env.REPLICATE_API_TOKEN;
  delete env.MARKETING_IMAGE_PROVIDER;
  const run = spawnSync(process.execPath, [SCRIPT, "--provider", "replicate", "--slug", "hero"], { env, encoding: "utf8" });
  assert.equal(run.status, 2);
  assert.match(run.stderr.trim(), /^REPLICATE_API_TOKEN is not set/);
  assert.equal(run.stderr.trim().split("\n").length, 1);
});

// ------------------------------------------------------------ header parsing

test("header parsing reads width and height from a JPEG and a PNG written to disk", () => {
  const dir = tempDir();
  const jpeg = path.join(dir, "a.jpg");
  const png = path.join(dir, "b.png");
  fs.writeFileSync(jpeg, tinyJpeg(1920, 1200));
  fs.writeFileSync(png, tinyPng(1920, 1080));

  assert.deepEqual(parseImageHeader(fs.readFileSync(jpeg)), { format: "jpeg", width: 1920, height: 1200 });
  assert.deepEqual(parseImageHeader(fs.readFileSync(png)), { format: "png", width: 1920, height: 1080 });
  assert.equal(detectFormat(fs.readFileSync(jpeg)), "jpeg");
  assert.equal(detectFormat(fs.readFileSync(png)), "png");
  fs.rmSync(dir, { recursive: true, force: true });
});

// ----------------------------------------------------------------- checks

const hero = pack.scenes.find((s) => s.slug === "hero");
const problem = pack.scenes.find((s) => s.slug === "paper_attendance");

test("the magic-byte check rejects anything that is not JPEG or PNG", () => {
  assert.throws(() => checkImage(Buffer.from("<svg xmlns='http://www.w3.org/2000/svg'/>"), hero, pack), /Not a JPEG or PNG/);
  assert.throws(() => checkImage(Buffer.from([0x47, 0x49, 0x46, 0x38, 0x39, 0x61]), hero, pack), /Not a JPEG or PNG/);
  assert.throws(() => checkImage(Buffer.alloc(0), hero, pack), /Not a JPEG or PNG/);
});

test("the 1 MB cap fails loudly and does not resize", () => {
  const overCap = tinyJpeg(1920, 1080, 1048576 + 1);
  assert.ok(overCap.length > 1048576);
  assert.throws(() => checkImage(overCap, hero, pack), /over the 1048576 byte cap/);
  const atCap = tinyJpeg(1920, 1080, 1048576);
  assert.equal(atCap.length, 1048576);
  assert.equal(checkImage(atCap, hero, pack).bytes, 1048576);
});

test("dimension checks: minimum width, landscape, aspect within tolerance", () => {
  assert.throws(() => checkImage(tinyJpeg(800, 500), hero, pack), /below the 1024px minimum/);
  assert.throws(() => checkImage(tinyJpeg(1200, 1920), hero, pack), /not landscape/);
  assert.throws(() => checkImage(tinyJpeg(1920, 1920 - 1), hero, pack), /not landscape|too far from/);
  assert.throws(() => checkImage(tinyPng(2000, 1500), hero, pack), /too far from 16:9/);
  const ok = checkImage(tinyJpeg(1440, 896), problem, pack);
  assert.equal(ok.width, 1440);
  assert.ok(ok.deviation < pack.aspect_tolerance);
});

// ---------------------------------------------------------------- manifest

test("manifest upsert replaces by kind and slug and keeps a stable order", () => {
  const manifest = { images: [
    { kind: "problems", slug: "manual_payroll", file: "manual_payroll.jpg" },
    { kind: "hero", slug: "hero", file: "hero.png" },
  ] };
  const next = upsertManifestEntry(manifest, { kind: "hero", slug: "hero", file: "hero.jpg" });
  assert.deepEqual(next.images.map((e) => e.file), ["hero.jpg", "manual_payroll.jpg"]);
  assert.equal(manifest.images[1].file, "hero.png", "the input is not mutated");
});

test("--import places a hand-made file through the same checks and upserts the manifest", async () => {
  const dir = tempDir();
  const assetsDir = path.join(dir, "assets");
  fs.mkdirSync(assetsDir);
  const manifestFile = path.join(assetsDir, "manifest.json");
  const source = path.join(dir, "export from the web ui.jpg");
  fs.writeFileSync(source, tinyJpeg(1920, 1080));
  const lines = [];
  const log = (l) => lines.push(l);

  const entry = await runImport(parseArgs(["--import", source, "--slug", "hero", "--tool", "higgsfield-web"]), { pack, assetsDir, manifestFile, log });
  assert.equal(entry.file, "hero.jpg");
  assert.equal(entry.width, 1920);
  assert.equal(entry.provenance.tool, "higgsfield-web");
  assert.equal(entry.provenance.prompt_id, "hero");
  assert.equal(entry.provenance.source, "export from the web ui.jpg");
  assert.match(entry.provenance.generated_at, /^\d{4}-\d{2}-\d{2}T/);
  assert.equal(entry.alt_am, hero.alt_am);
  assert.ok(fs.existsSync(path.join(assetsDir, "hero.jpg")));
  assert.equal(loadManifest(manifestFile).images.length, 1);
  assert.ok(lines.some((l) => l.includes("Review before committing")));

  await assert.rejects(
    runImport(parseArgs(["--import", source, "--slug", "hero"]), { pack, assetsDir, manifestFile, log }),
    /hero\.jpg exists\. Pass --force/,
  );

  fs.writeFileSync(source, tinyPng(1920, 1080));
  const replaced = await runImport(parseArgs(["--import", source, "--slug", "hero", "--force"]), { pack, assetsDir, manifestFile, log });
  assert.equal(replaced.file, "hero.png");
  assert.ok(!fs.existsSync(path.join(assetsDir, "hero.jpg")), "the old extension is removed");
  assert.equal(loadManifest(manifestFile).images.length, 1, "still one entry for the slug");

  const bad = path.join(dir, "bad.jpg");
  fs.writeFileSync(bad, Buffer.from("not an image"));
  await assert.rejects(
    runImport(parseArgs(["--import", bad, "--slug", "payroll"]), { pack, assetsDir, manifestFile, log }),
    /Not a JPEG or PNG/,
  );
  assert.ok(!fs.existsSync(path.join(assetsDir, "payroll.jpg")), "a failing file is never written");
  assert.equal(fs.readdirSync(assetsDir).filter((f) => f.endsWith(".tmp")).length, 0, "no temporary file is left behind");

  await assert.rejects(runImport(parseArgs(["--import", source]), { pack, assetsDir, manifestFile, log }), /needs --slug/);
  fs.rmSync(dir, { recursive: true, force: true });
});
