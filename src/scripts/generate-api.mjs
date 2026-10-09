#!/usr/bin/env node
//
// Regenerate the API contract: `api/openapi.json` and `src/api/generated.ts`.
//
// It is the command `scripts/api-types-check.sh` tells you to run when the
// contract gate fails. Native PHP only: a Docker-container fallback lived here
// until 2026-09-30, when the Docker development stack was removed.

import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import path from "node:path";

const WEB_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const REPO_ROOT = path.resolve(WEB_DIR, "..");
const API_DIR = path.join(REPO_ROOT, "api");

// Everything here uses execFileSync with no shell, deliberately. `execSync` (and
// any `shell: true`) spawns `process.env.ComSpec` on Windows, and a dev machine
// in this project had ComSpec pointing at a non-existent `C:\xampp\php` — which
// makes every shell-based call die with a spawn ENOENT naming a path that has
// nothing to do with the command being run. Probing binaries directly sidesteps
// the broken shell and gives an honest error when a tool is genuinely missing.
const run = (cmd, args, opts = {}) =>
  execFileSync(cmd, args, { stdio: "inherit", ...opts });

// `where`/`command -v` would need a shell; just try to run the thing instead.
const has = (cmd, probeArgs) => {
  try {
    execFileSync(cmd, probeArgs, { stdio: "ignore" });
    return true;
  } catch {
    return false;
  }
};

// Step 1 — export the OpenAPI spec to api/openapi.json.
if (has("php", ["-v"])) {
  run("php", ["-d", "memory_limit=512M", "artisan", "scramble:export", "--path=openapi.json"], {
    cwd: API_DIR,
  });
} else {
  console.error("✗ no php on PATH — install PHP 8.2+ natively (on Windows, XAMPP).");
  process.exit(1);
}

// Step 2 — compile the spec into the TypeScript contract the frontend imports.
run(
  process.execPath,
  ["node_modules/openapi-typescript/bin/cli.js", "../api/openapi.json", "-o", "src/api/generated.ts"],
  { cwd: WEB_DIR },
);

console.log("API contract regenerated: api/openapi.json, src/api/generated.ts");
