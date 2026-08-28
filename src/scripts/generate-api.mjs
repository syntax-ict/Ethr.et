#!/usr/bin/env node
//
// Regenerate the API contract: `api/openapi.json` and `src/api/generated.ts`.
//
// This replaces an inline npm script that ran `php artisan scramble:export`
// directly. On the documented Windows/Docker Desktop setup there is no native
// `php` — it lives only in the api container — so `npm run generate:api` failed
// with "php: command not found". That mattered more than a broken convenience
// script: it is the command `scripts/api-types-check.sh` tells you to run when
// the gate fails, so the advertised fix for contract drift did not work either,
// and the contract silently drifted by 14 endpoint groups.
//
// Falls back to the container only when there is no native php, so a normal
// Linux/macOS checkout keeps working unchanged.

import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import path from "node:path";

const WEB_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const REPO_ROOT = path.resolve(WEB_DIR, "..");
const API_DIR = path.join(REPO_ROOT, "api");
const CONTAINER = process.env.ETHR_API_CONTAINER ?? "et-api-1";

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

const containerRunning = () => {
  try {
    return execFileSync("docker", ["ps", "--format", "{{.Names}}"], {
      encoding: "utf8",
    })
      .split("\n")
      .some((n) => n.trim() === CONTAINER);
  } catch {
    return false;
  }
};

// Step 1 — export the OpenAPI spec to api/openapi.json.
if (has("php", ["-v"])) {
  run("php", ["-d", "memory_limit=512M", "artisan", "scramble:export", "--path=openapi.json"], {
    cwd: API_DIR,
  });
} else if (containerRunning()) {
  console.log(`No native php; exporting inside ${CONTAINER}.`);
  // The container bind-mounts api/, so writing openapi.json there lands in the repo.
  run("docker", [
    "exec",
    CONTAINER,
    "sh",
    "-c",
    "cd /var/www/api && php -d memory_limit=512M artisan scramble:export --path=openapi.json",
  ]);
} else {
  console.error(
    `✗ no way to run the export: no native php, and container ${CONTAINER} is not running.\n` +
      `  Start the stack with:  docker compose up -d`,
  );
  process.exit(1);
}

// Step 2 — compile the spec into the TypeScript contract the frontend imports.
run(
  process.execPath,
  ["node_modules/openapi-typescript/bin/cli.js", "../api/openapi.json", "-o", "src/api/generated.ts"],
  { cwd: WEB_DIR },
);

console.log("API contract regenerated: api/openapi.json, src/api/generated.ts");
