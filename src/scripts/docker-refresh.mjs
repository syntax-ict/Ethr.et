#!/usr/bin/env node
/**
 * Rebuild and restart the frontend container, guaranteeing fresh source.
 *
 * `docker compose build frontend` alone is not reliable here: Docker keys the
 * `COPY . .` layer on file metadata, and Docker Desktop for Windows serves that
 * metadata through a filesystem bridge that has twice reported a cache hit for
 * a source tree that had actually changed. The result is a container serving
 * the previous build while every check run against it reports failures that
 * were already fixed — which sends you off editing code that was correct.
 *
 * Passing a unique CACHEBUST invalidates only the source-copy layer, so the
 * `npm ci` layer above it is still reused and the rebuild stays quick.
 * `--force-recreate` then guarantees the running container picks up the new
 * image rather than being left in place as "already up to date".
 *
 *     npm run docker:refresh
 */
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), "..", "..");

function run(args) {
  const label = `docker ${args.join(" ")}`;
  process.stdout.write(`→ ${label}\n`);

  // Deliberately not `shell: true`. Spawning through the shell resolves via
  // ComSpec/SHELL, which is not guaranteed to point at a shell — on this
  // project's Windows setup it resolves to an unrelated interpreter and the
  // spawn fails with a null exit code. Invoking the binary directly avoids
  // depending on that entirely.
  const result = spawnSync(
    process.platform === "win32" ? "docker.exe" : "docker",
    args,
    { cwd: repoRoot, stdio: "inherit" },
  );

  if (result.error) {
    process.stderr.write(
      `\n✗ could not run \`${label}\`: ${result.error.message}\n` +
        `  Is Docker installed and on PATH?\n`,
    );
    process.exit(1);
  }

  if (result.status !== 0) {
    process.stderr.write(`\n✗ ${label} failed (exit ${result.status})\n`);
    process.exit(result.status ?? 1);
  }
}

run([
  "compose",
  "build",
  "--build-arg",
  `CACHEBUST=${Date.now()}`,
  "frontend",
]);
run(["compose", "up", "-d", "--force-recreate", "frontend"]);

process.stdout.write("\n✓ frontend rebuilt from current source and restarted\n");
