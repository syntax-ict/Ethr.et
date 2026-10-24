#!/usr/bin/env node
/**
 * Marketing illustration generator.
 *
 * An authoring tool, run by a person once an API key exists. Nothing in the
 * product calls it: the landing page reads the seeded files, and the seeder
 * reads the manifest this script writes. No paid API is a runtime dependency.
 *
 *   node scripts/marketing-visuals/generate.mjs --dry-run --provider replicate
 *   node scripts/marketing-visuals/generate.mjs --provider replicate --slug hero
 *   node scripts/marketing-visuals/generate.mjs --provider gemini --slug all --force
 *   node scripts/marketing-visuals/generate.mjs --import ~/Downloads/hero.jpg --slug hero
 *
 * Keys come from the environment only (REPLICATE_API_TOKEN, GEMINI_API_KEY,
 * OPENAI_API_KEY, HIGGSFIELD_API_KEY). A key on the command line is refused,
 * because the command line lands in shell history and in process listings.
 *
 * Node 24, ESM, core modules only. No dependency is added for this script.
 */

import fs from "node:fs";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const HERE = path.dirname(fileURLToPath(import.meta.url));
export const ROOT = path.resolve(HERE, "..", "..");
export const ASSETS_DIR = path.join(ROOT, "api", "database", "seeders", "assets", "marketing");
export const PROMPTS_FILE = path.join(ASSETS_DIR, "prompts.json");
export const MANIFEST_FILE = path.join(ASSETS_DIR, "manifest.json");

export const PROVIDERS = {
  replicate: { module: "./adapters/replicate.mjs", envKey: "REPLICATE_API_TOKEN" },
  gemini: { module: "./adapters/gemini.mjs", envKey: "GEMINI_API_KEY" },
  openai: { module: "./adapters/openai.mjs", envKey: "OPENAI_API_KEY" },
  higgsfield: { module: "./adapters/higgsfield.mjs", envKey: "HIGGSFIELD_API_KEY" },
};

export const REVIEW_CHECKLIST = [
  "No distorted script: every screen, paper and sign is blank or abstract",
  "Hands have five fingers and faces are whole",
  "No real logos, brand marks or device models",
  "Palette holds: teal, navy, warm neutrals, at most one gold accent",
  "The focal subject sits on the left or right third, not centred",
  "The set reads as one artist when placed beside the other scenes",
];

export class UsageError extends Error {
  constructor(message) {
    super(message);
    this.exitCode = 2;
  }
}

export class GenerationError extends Error {
  constructor(message) {
    super(message);
    this.exitCode = 1;
  }
}

// ---------------------------------------------------------------- arguments

const KEY_FLAGS = new Set(["--key", "--api-key", "--token", "--api-token", "--secret", "--api-secret"]);
const KEY_SHAPES = [/^r8_[A-Za-z0-9]{10,}/, /^sk-[A-Za-z0-9_-]{10,}/, /^AIza[0-9A-Za-z_-]{20,}/];

export function parseArgs(argv) {
  const args = {
    provider: null,
    slug: "all",
    force: false,
    dryRun: false,
    import: null,
    model: null,
    tool: null,
    inputJson: null,
    help: false,
  };

  for (let i = 0; i < argv.length; i += 1) {
    const arg = argv[i];
    const [flag, inlineValue] = arg.includes("=") ? arg.split(/=(.*)/s) : [arg, undefined];

    if (KEY_FLAGS.has(flag) || /-(key|token|secret)$/.test(flag)) {
      throw new UsageError(
        `Refusing a credential on the command line (${flag}). Set it in the environment instead: `
          + Object.values(PROVIDERS).map((p) => p.envKey).join(", "),
      );
    }
    const refuseKeyLike = (value) => {
      if (KEY_SHAPES.some((shape) => shape.test(value))) {
        throw new UsageError("Refusing an argument that looks like an API key. Keys come from the environment only.");
      }
      return value;
    };
    refuseKeyLike(inlineValue ?? arg);

    const next = () => {
      if (inlineValue !== undefined) return inlineValue;
      i += 1;
      if (i >= argv.length) throw new UsageError(`${flag} needs a value`);
      return refuseKeyLike(argv[i]);
    };

    switch (flag) {
      case "--provider": args.provider = next(); break;
      case "--slug": args.slug = next(); break;
      case "--force": args.force = true; break;
      case "--dry-run": args.dryRun = true; break;
      case "--import": args.import = next(); break;
      case "--model": args.model = next(); break;
      case "--tool": args.tool = next(); break;
      case "--input-json": args.inputJson = next(); break;
      case "--help":
      case "-h": args.help = true; break;
      default: throw new UsageError(`Unknown argument ${arg}. Run with --help.`);
    }
  }

  if (args.inputJson !== null) {
    try {
      const parsed = JSON.parse(args.inputJson);
      if (parsed === null || typeof parsed !== "object" || Array.isArray(parsed)) throw new Error("not an object");
      args.inputJson = parsed;
    } catch (error) {
      throw new UsageError(`--input-json must be a JSON object: ${error.message}`);
    }
  }

  return args;
}

export function usage() {
  return [
    "Usage:",
    "  generate.mjs --provider <replicate|gemini|openai|higgsfield> [--slug all|<slug>] [--force] [--model <id>] [--input-json '{...}']",
    "  generate.mjs --dry-run [--provider <name>] [--slug all|<slug>]",
    "  generate.mjs --import <file> --slug <slug> [--tool <name>] [--model <id>] [--force]",
    "",
    "The provider may also come from MARKETING_IMAGE_PROVIDER. Keys come from the environment:",
    ...Object.entries(PROVIDERS).map(([name, p]) => `  ${name.padEnd(11)} ${p.envKey}`),
    "",
    "--input-json merges extra fields into the provider's request (a size field a model needs, for example).",
    "Exit codes: 0 done, 1 generation or check failed, 2 usage, missing key or unfinished adapter.",
  ].join("\n");
}

// ------------------------------------------------------------ prompt pack

export function loadPromptPack(file = PROMPTS_FILE) {
  const pack = JSON.parse(fs.readFileSync(file, "utf8"));
  const required = ["style", "negative", "scenes"];
  for (const key of required) {
    if (!(key in pack)) throw new GenerationError(`${file} has no "${key}"`);
  }
  const slugs = new Set();
  for (const scene of pack.scenes) {
    for (const key of ["slug", "kind", "aspect", "width", "height", "prompt", "alt", "alt_am"]) {
      if (!(key in scene)) throw new GenerationError(`scene ${scene.slug ?? "?"} has no "${key}"`);
    }
    if (slugs.has(scene.slug)) throw new GenerationError(`duplicate scene slug ${scene.slug}`);
    slugs.add(scene.slug);
  }
  return pack;
}

export function composePrompt(pack, scene) {
  return `${pack.style.trim()}\n\nScene: ${scene.prompt.trim()}\n\nAvoid: ${pack.negative.trim()}.`;
}

export function selectScenes(pack, slug) {
  if (slug === "all") return pack.scenes;
  const scene = pack.scenes.find((s) => s.slug === slug);
  if (!scene) {
    throw new UsageError(`No scene "${slug}". Known: ${pack.scenes.map((s) => s.slug).join(", ")}`);
  }
  return [scene];
}

// ------------------------------------------------------------ image checks

const JPEG_MAGIC = [0xff, 0xd8, 0xff];
const PNG_MAGIC = [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a];

function startsWith(bytes, magic) {
  if (bytes.length < magic.length) return false;
  return magic.every((byte, i) => bytes[i] === byte);
}

/** Returns "jpeg", "png" or null from the first bytes alone. */
export function detectFormat(bytes) {
  if (startsWith(bytes, JPEG_MAGIC)) return "jpeg";
  if (startsWith(bytes, PNG_MAGIC)) return "png";
  return null;
}

function readUInt16BE(bytes, offset) {
  return (bytes[offset] << 8) | bytes[offset + 1];
}

function readUInt32BE(bytes, offset) {
  return ((bytes[offset] << 24) >>> 0) + (bytes[offset + 1] << 16) + (bytes[offset + 2] << 8) + bytes[offset + 3];
}

/**
 * Width and height from the file header, with no decoder.
 * PNG: the IHDR chunk that must follow the signature.
 * JPEG: the first start-of-frame marker (SOF0 to SOF15, excluding DHT, JPG and DAC).
 */
export function parseImageHeader(bytes) {
  const format = detectFormat(bytes);
  if (format === "png") {
    if (bytes.length < 24) throw new GenerationError("PNG is truncated before IHDR");
    if (String.fromCharCode(...bytes.subarray(12, 16)) !== "IHDR") throw new GenerationError("PNG has no IHDR chunk first");
    return { format, width: readUInt32BE(bytes, 16), height: readUInt32BE(bytes, 20) };
  }
  if (format === "jpeg") {
    let offset = 2;
    while (offset + 4 <= bytes.length) {
      if (bytes[offset] !== 0xff) throw new GenerationError(`JPEG marker expected at byte ${offset}`);
      const marker = bytes[offset + 1];
      if (marker === 0xff) { offset += 1; continue; }
      if (marker === 0xd8 || (marker >= 0xd0 && marker <= 0xd7) || marker === 0x01) { offset += 2; continue; }
      if (marker === 0xd9 || marker === 0xda) break;
      const length = readUInt16BE(bytes, offset + 2);
      const isSof = marker >= 0xc0 && marker <= 0xcf && marker !== 0xc4 && marker !== 0xc8 && marker !== 0xcc;
      if (isSof) {
        if (offset + 9 > bytes.length) throw new GenerationError("JPEG is truncated inside the frame header");
        return { format, height: readUInt16BE(bytes, offset + 5), width: readUInt16BE(bytes, offset + 7) };
      }
      offset += 2 + length;
    }
    throw new GenerationError("JPEG has no start-of-frame marker");
  }
  throw new GenerationError("Not a JPEG or PNG: the first bytes match neither magic number");
}

function aspectOf(text) {
  const [w, h] = text.split(":").map(Number);
  if (!(w > 0) || !(h > 0)) throw new GenerationError(`Bad aspect "${text}"`);
  return w / h;
}

/**
 * Every check an image must pass before it is written. Runs on the bytes in
 * memory, so a failing image never reaches the disk.
 */
export function checkImage(bytes, scene, pack) {
  const maxBytes = pack.max_bytes ?? 1048576;
  const minWidth = pack.min_width ?? 1024;
  const tolerance = pack.aspect_tolerance ?? 0.15;

  const header = parseImageHeader(bytes);
  if (bytes.length > maxBytes) {
    throw new GenerationError(
      `${scene.slug}: ${bytes.length} bytes is over the ${maxBytes} byte cap. Export at a lower JPEG quality; the script does not resize.`,
    );
  }
  if (header.width < minWidth) {
    throw new GenerationError(`${scene.slug}: ${header.width}px wide is below the ${minWidth}px minimum`);
  }
  if (header.height >= header.width) {
    throw new GenerationError(`${scene.slug}: ${header.width}x${header.height} is not landscape`);
  }
  const wanted = aspectOf(scene.aspect);
  const actual = header.width / header.height;
  const deviation = Math.abs(actual - wanted) / wanted;
  if (deviation > tolerance) {
    throw new GenerationError(
      `${scene.slug}: ${header.width}x${header.height} (${actual.toFixed(2)}) is too far from ${scene.aspect} (${wanted.toFixed(2)})`,
    );
  }
  return { ...header, bytes: bytes.length, deviation };
}

// --------------------------------------------------------------- manifest

export function loadManifest(file = MANIFEST_FILE) {
  if (!fs.existsSync(file)) return { images: [] };
  const manifest = JSON.parse(fs.readFileSync(file, "utf8"));
  if (!Array.isArray(manifest.images)) throw new GenerationError(`${file} has no "images" array`);
  return manifest;
}

export function upsertManifestEntry(manifest, entry) {
  const images = manifest.images.filter((e) => !(e.kind === entry.kind && e.slug === entry.slug));
  images.push(entry);
  images.sort((a, b) => a.kind.localeCompare(b.kind) || a.slug.localeCompare(b.slug));
  return { ...manifest, images };
}

export function findManifestEntry(manifest, scene) {
  return manifest.images.find((e) => e.kind === scene.kind && e.slug === scene.slug) ?? null;
}

/** Write a file in full or not at all: a temporary name, then an atomic rename. */
export function writeAtomic(file, data) {
  const tmp = `${file}.${process.pid}.tmp`;
  try {
    fs.writeFileSync(tmp, data);
    fs.renameSync(tmp, file);
  } finally {
    if (fs.existsSync(tmp)) fs.rmSync(tmp, { force: true });
  }
}

export function saveManifest(manifest, file = MANIFEST_FILE) {
  writeAtomic(file, `${JSON.stringify(manifest, null, 2)}\n`);
}

/**
 * Check, place and record one image. Shared by generation and --import so a
 * hand-made file passes exactly the checks a generated one does.
 */
export function placeImage({ bytes, scene, pack, provenance, assetsDir = ASSETS_DIR, manifestFile = MANIFEST_FILE, force = false, log = console.log }) {
  const checked = checkImage(bytes, scene, pack);
  const fileName = `${scene.slug}.${checked.format === "png" ? "png" : "jpg"}`;
  const target = path.join(assetsDir, fileName);
  const manifest = loadManifest(manifestFile);
  const existing = findManifestEntry(manifest, scene);

  if (existing && !force && fs.existsSync(path.join(assetsDir, existing.file))) {
    throw new GenerationError(`${scene.slug}: ${existing.file} exists. Pass --force to replace it.`);
  }

  // A format change leaves the old extension behind; remove it so two files
  // never claim one slug.
  if (existing && existing.file !== fileName) {
    fs.rmSync(path.join(assetsDir, existing.file), { force: true });
  }

  writeAtomic(target, bytes);

  const entry = {
    file: fileName,
    kind: scene.kind,
    slug: scene.slug,
    width: checked.width,
    height: checked.height,
    bytes: checked.bytes,
    alt: scene.alt,
    alt_am: scene.alt_am,
    provenance: {
      tool: provenance.tool,
      model: provenance.model ?? null,
      prompt_id: scene.slug,
      prompt_version: pack.version ?? null,
      generated_at: provenance.generated_at ?? new Date().toISOString(),
      ...(provenance.source ? { source: provenance.source } : {}),
    },
  };
  saveManifest(upsertManifestEntry(manifest, entry), manifestFile);

  log(`  wrote ${path.relative(ROOT, target)} (${checked.width}x${checked.height}, ${checked.bytes} bytes)`);
  if (checked.deviation > 0.02) {
    log(`  note: ${checked.width}x${checked.height} is ${(checked.deviation * 100).toFixed(0)}% off ${scene.aspect}; the page crops with the focal third kept`);
  }
  if (checked.width < (pack.target_width ?? 1920)) {
    log(`  note: ${checked.width}px wide is below the ${pack.target_width ?? 1920}px target; fine for the page, replace with a larger export when one exists`);
  }
  return entry;
}

export function printChecklist(log = console.log) {
  log("  Review before committing:");
  for (const item of REVIEW_CHECKLIST) log(`    [ ] ${item}`);
}

// -------------------------------------------------------------- providers

export function resolveProviderName(args, env = process.env) {
  const name = args.provider ?? env.MARKETING_IMAGE_PROVIDER ?? null;
  if (name === null) {
    throw new UsageError(`No provider. Pass --provider or set MARKETING_IMAGE_PROVIDER to one of ${Object.keys(PROVIDERS).join(", ")}`);
  }
  if (!(name in PROVIDERS)) {
    throw new UsageError(`Unknown provider "${name}". Known: ${Object.keys(PROVIDERS).join(", ")}`);
  }
  return name;
}

export async function loadAdapter(name) {
  const spec = PROVIDERS[name];
  const adapter = await import(new URL(spec.module, import.meta.url));
  return { name, envKey: spec.envKey, ...adapter };
}

export function requireKey(envKey, env = process.env) {
  const value = env[envKey];
  if (!value || value.trim() === "") {
    throw new UsageError(`${envKey} is not set. Export it in the shell that runs this script; nothing is read from the command line or a file.`);
  }
  return value.trim();
}

// ---------------------------------------------------------------- commands

export async function runDryRun(args, { pack = loadPromptPack(), log = console.log } = {}) {
  const scenes = selectScenes(pack, args.slug);
  const provider = args.provider ?? process.env.MARKETING_IMAGE_PROVIDER ?? "(none)";
  log(`Dry run: ${scenes.length} prompt(s) for provider ${provider}, pack version ${pack.version ?? "unversioned"}. No network.`);
  for (const scene of scenes) {
    log("");
    log(`=== ${scene.slug} (${scene.kind}, ${scene.aspect}, ${scene.width}x${scene.height}) ===`);
    log(composePrompt(pack, scene));
    log(`--- alt: ${scene.alt}`);
    log(`--- alt_am: ${scene.alt_am}`);
  }
  return scenes.length;
}

export async function runImport(args, { pack = loadPromptPack(), assetsDir = ASSETS_DIR, manifestFile = MANIFEST_FILE, log = console.log } = {}) {
  if (!args.slug || args.slug === "all") throw new UsageError("--import needs --slug <slug>, one scene at a time");
  const scene = selectScenes(pack, args.slug)[0];
  const source = path.resolve(args.import);
  if (!fs.existsSync(source)) throw new UsageError(`${source} does not exist`);
  const bytes = fs.readFileSync(source);
  log(`Importing ${source} as ${scene.slug}`);
  const entry = placeImage({
    bytes,
    scene,
    pack,
    assetsDir,
    manifestFile,
    force: args.force,
    log,
    provenance: { tool: args.tool ?? "import", model: args.model ?? null, source: path.basename(source) },
  });
  printChecklist(log);
  return entry;
}

export async function runGenerate(args, { pack = loadPromptPack(), assetsDir = ASSETS_DIR, manifestFile = MANIFEST_FILE, log = console.log, env = process.env, fetchImpl = globalThis.fetch } = {}) {
  const name = resolveProviderName(args, env);
  const adapter = await loadAdapter(name);
  const apiKey = requireKey(adapter.envKey, env);
  const scenes = selectScenes(pack, args.slug);
  const model = args.model ?? adapter.defaultModel;
  const manifest = loadManifest(manifestFile);

  let written = 0;
  let skipped = 0;
  for (const scene of scenes) {
    const existing = findManifestEntry(manifest, scene);
    if (existing && !args.force && fs.existsSync(path.join(assetsDir, existing.file))) {
      log(`${scene.slug}: ${existing.file} exists, skipping (--force to regenerate)`);
      skipped += 1;
      continue;
    }
    log(`${scene.slug}: generating with ${name} (${model}) at ${scene.aspect}`);
    const result = await adapter.generate({
      prompt: composePrompt(pack, scene),
      aspect: scene.aspect,
      width: scene.width,
      height: scene.height,
      model,
      apiKey,
      env,
      extraInput: args.inputJson ?? {},
      fetch: fetchImpl,
      log,
    });
    placeImage({
      bytes: result.bytes,
      scene,
      pack,
      assetsDir,
      manifestFile,
      force: args.force,
      log,
      provenance: { tool: name, model: result.model ?? model },
    });
    printChecklist(log);
    written += 1;
  }
  log(`Done: ${written} written, ${skipped} skipped.`);
  return { written, skipped };
}

export async function main(argv = process.argv.slice(2)) {
  const args = parseArgs(argv);
  if (args.help) {
    console.log(usage());
    return 0;
  }
  if (args.dryRun) {
    await runDryRun(args);
    return 0;
  }
  if (args.import !== null) {
    await runImport(args);
    return 0;
  }
  await runGenerate(args);
  return 0;
}

const invokedDirectly = process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href;
if (invokedDirectly) {
  main().then(
    (code) => process.exit(code),
    (error) => {
      console.error(`${error.message}`);
      process.exit(error.exitCode ?? 1);
    },
  );
}
