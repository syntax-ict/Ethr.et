import { describe, it, expect } from "vitest";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";

/**
 * The Sentry SDK must cost nothing in a build without a DSN.
 *
 * The release build has no `NEXT_PUBLIC_SENTRY_DSN`, so Sentry never starts in
 * production, yet its SDK shipped on every page: 115 KB of brotli in a chunk
 * every visitor downloaded, about a quarter of each page's JavaScript
 * (2026-10-30). Because `NEXT_PUBLIC_*` is written into the bundle at build
 * time, a call guarded on the DSN is dead code when the DSN is unset, and the
 * bundler drops the whole SDK. One unguarded call anywhere in browser code
 * brings all of it back, which nothing would show, hence this sweep.
 *
 * `instrumentation.ts` runs on a server only, which the static export does not
 * have, and type-only imports emit nothing.
 */

const SRC = join(__dirname, "..");
const SERVER_ONLY = new Set(["instrumentation.ts"]);

function sources(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    if (statSync(path).isDirectory())
      return name === "test" ? [] : sources(path);
    return /\.(ts|tsx)$/.test(name) ? [path] : [];
  });
}

/** Files that import the SDK as a value, not only its types. */
function sentryImporters(): string[] {
  return sources(SRC).filter((path) => {
    if (SERVER_ONLY.has(relative(SRC, path).replace(/\\/g, "/"))) return false;
    return /^import\s+(?!type\b)[^;]*from\s+["']@sentry\//m.test(
      readFileSync(path, "utf8"),
    );
  });
}

/**
 * Each use of `Sentry.x` not inside an `if` that tests the DSN. A reference
 * keeps the SDK as surely as a call (`export const onRouterTransitionStart =
 * Sentry.captureRouterTransitionStart` did); a `typeof` is a type and emits
 * nothing.
 */
function unguardedCalls(code: string): string[] {
  const found: string[] = [];
  for (const match of code.matchAll(/Sentry\.(\w+)/g)) {
    const before = code.slice(0, match.index);
    if (/typeof\s+$/.test(before)) continue;
    const guard = before.lastIndexOf("if (");
    const condition =
      guard === -1 ? "" : before.slice(guard, before.indexOf(")", guard) + 1);
    const opened = before.slice(guard);
    const stillInside =
      (opened.match(/\{/g) ?? []).length > (opened.match(/\}/g) ?? []).length;
    if (
      guard === -1 ||
      !/NEXT_PUBLIC_SENTRY_DSN|\bdsn\b/.test(condition) ||
      !stillInside
    ) {
      found.push(match[1]);
    }
  }
  return found;
}

describe("Sentry in browser code", () => {
  it("finds the files it is meant to check", () => {
    const files = sentryImporters().map((p) =>
      relative(SRC, p).replace(/\\/g, "/"),
    );

    // A broken walk would pass by checking nothing.
    expect(files).toEqual(
      expect.arrayContaining([
        "instrumentation-client.ts",
        "app/error.tsx",
        "app/(dashboard)/error.tsx",
      ]),
    );
  });

  it("calls the SDK only behind the DSN", () => {
    const offenders = sentryImporters().flatMap((path) =>
      unguardedCalls(readFileSync(path, "utf8")).map(
        (call) => `${relative(SRC, path)}: Sentry.${call}`,
      ),
    );

    expect(offenders).toEqual([]);
  });

  it("has the build write the DSN in, even when there is none", () => {
    // The guards only fold away when the value is written in at build time.
    // Next does that for a set `NEXT_PUBLIC_*` variable and leaves an unset one
    // as a runtime lookup, so without this the guards survive and so does the SDK.
    const config = readFileSync(join(SRC, "..", "next.config.ts"), "utf8");

    expect(config).toMatch(
      /NEXT_PUBLIC_SENTRY_DSN:\s*process\.env\.NEXT_PUBLIC_SENTRY_DSN\s*\?\?\s*""/,
    );
  });

  it("tells a guarded call from an unguarded one", () => {
    expect(
      unguardedCalls("useEffect(() => { Sentry.captureException(e); });"),
    ).toEqual(["captureException"]);
    expect(
      unguardedCalls(
        "if (process.env.NEXT_PUBLIC_SENTRY_DSN) {\n  Sentry.captureException(e);\n}",
      ),
    ).toEqual([]);
    expect(
      unguardedCalls("if (dsn) { Sentry.init({}); }\nSentry.flush();"),
    ).toEqual(["flush"]);
    expect(unguardedCalls("if (ready) { Sentry.init({}); }")).toEqual(["init"]);
    expect(
      unguardedCalls(
        "export const start = Sentry.captureRouterTransitionStart;",
      ),
    ).toEqual(["captureRouterTransitionStart"]);
    expect(
      unguardedCalls(
        "(...args: Parameters<typeof Sentry.captureRouterTransitionStart>) => {}",
      ),
    ).toEqual([]);
  });
});
