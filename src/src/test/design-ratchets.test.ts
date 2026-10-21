import { describe, it, expect } from "vitest";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";

/**
 * Two ratchets from docs/FRONTEND_REDESIGN_PLAN.md (Phase 1, 2026-10-20).
 *
 * Each counts something the plan removes over several phases and fails only
 * when the count GROWS. The ceilings are today's measurements; lower them in
 * the same PR that removes instances, never raise them. When a ceiling reaches
 * zero the rule is absolute and the ceiling stays at zero.
 *
 * 1. Text below 12px (`text-[10px]`, `text-[11px]`). The type scale's floor is
 *    12px; density comes from row height and padding, not smaller type.
 * 2. Native `confirm()`. It cannot be translated, styled or keyboard-tested,
 *    and six of today's calls are literal English. `ConfirmDialog` (and the
 *    `useConfirm` hook the plan introduces) is the replacement.
 * 3. An `onError` that takes no argument. It can only show a canned message,
 *    so a 403, a 409 or a field's reason never reaches the person. Pass the
 *    error to `apiErrorMessage(err, fallback)` instead. 79 on 2026-10-20, 0 after.
 * 4. A screen that reads `isLoading` and never `isError`. When its request
 *    fails it falls through to its empty branch and states something false
 *    ("Not Checked In", "No attendance this month"). Render `ErrorState`, use
 *    `QueryBoundary`, or, where silence is right, say why in a
 *    `quiet-on-error:` comment. 41 files on 2026-10-21, 0 the same day.
 */

const SRC = join(process.cwd(), "src");

const SUB_12PX = /\btext-\[1[01]px\]/g;

// `window.confirm(` or a bare `confirm(` call. Not a method (`.confirm(`), not
// a declaration (`function confirm(`), not a comment line, and not the
// component-style names (`onConfirm`, `useConfirm`, `ConfirmDialog`), which the
// look-behind on a word character already excludes.
const NATIVE_CONFIRM = /(?:\bwindow\.|(?<![\w.$]))confirm\(/;
const COMMENT_LINE = /^\s*(?:\/\/|\*|\/\*)/;
const DECLARATION = /\bfunction\s+confirm\s*\(/;
const DISCARDED_ERROR = /\bonError:\s*\(\s*\)\s*=>/g;

const CEILINGS = {
  sub12pxText: 96,
  nativeConfirm: 0,
  discardedErrors: 0,
  silentLoadFailures: 0,
};

function walk(dir: string): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir)) {
    if (entry === "node_modules" || entry === ".next") continue;
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      if (entry === "test") continue;
      out.push(...walk(full));
    } else if (/\.(tsx?|css)$/.test(entry)) {
      out.push(full);
    }
  }
  return out;
}

function tally(matcher: (line: string) => number): {
  total: number;
  byFile: string[];
} {
  const byFile: string[] = [];
  let total = 0;
  for (const file of walk(SRC)) {
    const count = readFileSync(file, "utf8")
      .split("\n")
      .reduce((n, line) => n + matcher(line), 0);
    if (count > 0) {
      total += count;
      byFile.push(`${relative(SRC, file).replace(/\\/g, "/")}: ${count}`);
    }
  }
  return { total, byFile: byFile.sort() };
}

describe("design ratchets", () => {
  it(`has no more than ${CEILINGS.sub12pxText} text classes below 12px`, () => {
    const { total, byFile } = tally(
      (line) => (line.match(SUB_12PX) ?? []).length,
    );
    expect(
      total,
      `Text below 12px grew past the ceiling of ${CEILINGS.sub12pxText}. Use the type scale (text-xs is the 12px floor) and row height for density. Files:\n${byFile.join("\n")}`,
    ).toBeLessThanOrEqual(CEILINGS.sub12pxText);
  });

  it(`has no more than ${CEILINGS.nativeConfirm} native confirm() calls`, () => {
    const { total, byFile } = tally((line) =>
      !COMMENT_LINE.test(line) &&
      !DECLARATION.test(line) &&
      NATIVE_CONFIRM.test(line)
        ? 1
        : 0,
    );
    expect(
      total,
      `Native confirm() grew past the ceiling of ${CEILINGS.nativeConfirm}. Use ConfirmDialog, which is translated and keyboard-operable. Files:\n${byFile.join("\n")}`,
    ).toBeLessThanOrEqual(CEILINGS.nativeConfirm);
  });

  it(`has no more than ${CEILINGS.discardedErrors} onError handlers that drop the error`, () => {
    const { total, byFile } = tally(
      (line) => (line.match(DISCARDED_ERROR) ?? []).length,
    );
    expect(
      total,
      `An onError that takes no argument cannot show the server's reason. Use (err) => toast.error(apiErrorMessage(err, fallback)). Files:\n${byFile.join("\n")}`,
    ).toBeLessThanOrEqual(CEILINGS.discardedErrors);
  });

  it(`has no more than ${CEILINGS.silentLoadFailures} screens that hide a failed load`, () => {
    // Whole-file rule, over the signed-in app only: the public site and the
    // shared primitives load nothing of their own.
    const roots = ["app/(dashboard)", "features", "components"].map((d) =>
      join(SRC, d),
    );
    const silent = roots
      .flatMap((root) => walk(root))
      .filter((file) => file.endsWith(".tsx"))
      .filter((file) => {
        const text = readFileSync(file, "utf8");
        return (
          text.includes("isLoading") &&
          !/\bisError\b|QueryBoundary|quiet-on-error:/.test(text)
        );
      })
      .map((file) => relative(SRC, file).replace(/\\/g, "/"))
      .sort();
    expect(
      silent.length,
      `A screen that reads isLoading must handle isError too: render <ErrorState onRetry={refetch} />, use QueryBoundary, or explain silence with a quiet-on-error: comment. Files:\n${silent.join("\n")}`,
    ).toBeLessThanOrEqual(CEILINGS.silentLoadFailures);
  });
});
