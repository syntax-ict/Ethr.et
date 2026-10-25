import { describe, it, expect } from "vitest";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";

/**
 * CLAUDE.md convention #12: components reference semantic CSS variables, never
 * raw Tailwind palette values. This guard fails the suite the moment a raw
 * palette class (or a hardcoded `text-white` over a themed surface) reappears.
 *
 * See DESIGN_SYSTEM.md → "Status Soft Containers" for the replacement utilities.
 *
 * The tree is read once and each file matched whole, with the line worked out
 * from the match offset. It used to be walked and split into lines once per
 * pattern, which on a loaded Windows disk under the full suite crossed the
 * 20 s test timeout (2026-10-25) while reporting nothing wrong.
 */

const SRC = join(process.cwd(), "src");

const PALETTES = [
  "slate",
  "gray",
  "zinc",
  "neutral",
  "stone",
  "red",
  "orange",
  "amber",
  "yellow",
  "lime",
  "green",
  "emerald",
  "teal",
  "cyan",
  "sky",
  "blue",
  "indigo",
  "violet",
  "purple",
  "fuchsia",
  "pink",
  "rose",
].join("|");

const PROPS =
  "text|bg|border|ring|from|to|via|fill|stroke|decoration|divide|outline|shadow|accent|caret";

// e.g. `bg-amber-50`, `dark:text-red-400`, `bg-red-950/30`
const RAW_PALETTE = new RegExp(
  `\\b(?:${PROPS})-(?:${PALETTES})-\\d{2,3}(?:/\\d{1,3})?\\b`,
  "g",
);

// A `dark:` variant on a semantic token means the token is not theme-aware —
// the whole point of the soft-container set is that it repaints on its own.
const REDUNDANT_DARK = new RegExp(
  `\\bdark:(?:bg|text|border)-(?:success|warning|destructive|info|brand|neutral|muted|primary|secondary)\\b`,
  "g",
);

function walk(dir: string): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir)) {
    if (entry === "node_modules" || entry === ".next") continue;
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      out.push(...walk(full));
    } else if (/\.(tsx|ts)$/.test(entry)) {
      out.push(full);
    }
  }
  return out;
}

let sources: Array<{ file: string; text: string }> | null = null;

function readSources(): Array<{ file: string; text: string }> {
  sources ??= walk(SRC)
    // This spec quotes the banned patterns in its own source; skip itself.
    .filter((file) => !file.endsWith("semantic-color-tokens.test.ts"))
    .map((file) => ({ file, text: readFileSync(file, "utf8") }));
  return sources;
}

function lineOf(text: string, index: number): number {
  let line = 1;
  for (let i = 0; i < index; i++) if (text.charCodeAt(i) === 10) line++;
  return line;
}

function findViolations(pattern: RegExp): string[] {
  const hits: string[] = [];
  for (const { file, text } of readSources()) {
    for (const match of text.matchAll(pattern)) {
      hits.push(
        `${file.replace(SRC, "src")}:${lineOf(text, match.index)}  ${match[0]}`,
      );
    }
  }
  return hits;
}

describe("semantic color tokens", () => {
  it("uses no raw Tailwind palette classes", () => {
    expect(findViolations(RAW_PALETTE)).toEqual([]);
  });

  it("uses no redundant dark: variants on semantic tokens", () => {
    expect(findViolations(REDUNDANT_DARK)).toEqual([]);
  });
});
