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

const CEILINGS = {
  sub12pxText: 99,
  nativeConfirm: 0,
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
});
