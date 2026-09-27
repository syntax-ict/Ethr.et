#!/usr/bin/env node
//
// Build the Bronze shared-hosting artifact, then assert it is one.
//
// `ETHR_TARGET=shared-hosting npm run build` is the documented incantation and it
// does not work on Windows, which is this repository's primary development
// platform: neither cmd.exe nor PowerShell accepts a `VAR=value command` prefix.
// A developer who runs it there gets a `standalone` build with no warning — the
// same silent-wrong-target failure `verify-static-export.mjs` exists to catch,
// arriving one step earlier.
//
// So the env var is set here, in Node, where it behaves identically everywhere.
// No `cross-env` dependency: this is a handful of lines, and adding a package to
// the production frontend's tree to spell one variable is not a trade worth
// taking.
//
// Verification runs in the same command deliberately. A build that emits the
// wrong thing and a build that emits nothing look alike from an exit code, and
// the two were separable only as long as somebody remembered to run step two.

import fs from "node:fs";
import { spawnSync } from "node:child_process";
import path from "node:path";
import { fileURLToPath } from "node:url";

const WEB_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");

/**
 * The value `src/lib/build-target.ts` compares `ETHR_TARGET` against.
 *
 * Duplicated as a literal rather than imported: importing a `.ts` module from a
 * `.mjs` script depends on Node's type-stripping, which is version-gated and
 * experimental, and a gate must not break on a Node upgrade. The duplication is
 * then checked below instead of trusted — two readers that disagree fail
 * silently, which is the reason `build-target.ts` and `EmployeeRelations::MAP`
 * both exist as single definitions in the first place.
 */
const TARGET = "shared-hosting";

const buildTarget = fs.readFileSync(
  path.join(WEB_DIR, "src", "lib", "build-target.ts"),
  "utf8",
);

if (!buildTarget.includes(`SHARED_HOSTING_TARGET = "${TARGET}"`)) {
  console.error(
    `build-shared-hosting: src/lib/build-target.ts no longer declares\n` +
      `  export const SHARED_HOSTING_TARGET = "${TARGET}";\n` +
      "so this script would set an ETHR_TARGET the config does not recognise and\n" +
      "silently produce a standalone build. Update both, or make this read the value.",
  );
  process.exit(2);
}

/**
 * Run a child process to completion, propagating its exit status.
 *
 * Everything is spawned as `node <js entry point>`, never through `npx` and never
 * with `shell: true`. Three measurements on 2026-09-27 produced that rule:
 *
 *   `shell: true` + process.execPath  → `'C:\Program' is not recognized`, because
 *                                       a shell splits the path at the space in
 *                                       `C:\Program Files\nodejs\node.exe`. npm
 *                                       still reported success, since the failure
 *                                       was in the step after the build.
 *   `shell: true` + args              → Node 24 DEP0190: args beside a shell are
 *                                       concatenated, not escaped.
 *   `npx.cmd` without a shell         → `spawnSync npx.cmd EINVAL`. Node refuses
 *                                       to spawn `.cmd`/`.bat` directly since the
 *                                       CVE-2024-27980 fix.
 *
 * Calling the package's own JS entry point sidesteps all three, and is the idiom
 * `scripts/gates.sh` already uses in five places for exactly this reason.
 */
function run(command, args) {
  const result = spawnSync(command, args, {
    cwd: WEB_DIR,
    stdio: "inherit",
    env: { ...process.env, ETHR_TARGET: TARGET },
  });

  if (result.error) {
    console.error(`build-shared-hosting: could not run ${command}: ${result.error.message}`);
    process.exit(1);
  }

  if (result.status !== 0) process.exit(result.status ?? 1);
}

run(process.execPath, [path.join(WEB_DIR, "node_modules", "next", "dist", "bin", "next"), "build"]);
run(process.execPath, [path.join(WEB_DIR, "scripts", "verify-static-export.mjs")]);
