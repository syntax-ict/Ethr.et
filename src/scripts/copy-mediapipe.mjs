// Copy MediaPipe's vision WASM into public/ so on-device liveness loads it
// from this site, never a CDN: the static export serves it like any asset and
// the service worker can cache it for offline punching. Only the SIMD and the
// no-SIMD builds are copied; FilesetResolver picks one for the device.
//
// Runs on postinstall, so `npm ci` in CI, in the release build and locally all
// produce the same public/mediapipe. The folder is gitignored: the package
// version in package-lock.json is the source of truth.
import { copyFileSync, existsSync, mkdirSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const from = path.join(
  root,
  "node_modules",
  "@mediapipe",
  "tasks-vision",
  "wasm",
);
const to = path.join(root, "public", "mediapipe");

if (!existsSync(from)) {
  // A production-only install without the package: liveness falls back to
  // "unavailable", which the server treats as neutral.
  process.exit(0);
}

mkdirSync(to, { recursive: true });
for (const name of [
  "vision_wasm_internal.js",
  "vision_wasm_internal.wasm",
  "vision_wasm_nosimd_internal.js",
  "vision_wasm_nosimd_internal.wasm",
]) {
  copyFileSync(path.join(from, name), path.join(to, name));
}
