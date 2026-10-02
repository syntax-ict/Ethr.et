import { describe, it, expect } from "vitest";
import { readdirSync, readFileSync } from "node:fs";
import { join, relative } from "node:path";

/**
 * A button whose only child is an icon has no accessible name unless it says
 * so itself: a screen reader announces "button" and nothing else. Nine such
 * buttons were found in one pass (audit N29) — remove-filter, delete-report,
 * the leave list/calendar toggle — so this reads every component and fails on
 * the next one rather than waiting for someone to tab through the app.
 *
 * The opening tag is read with brace-depth tracking: `onClick={() => x}`
 * contains a ">".
 */
const ROOT = join(__dirname, "..");

function tsxFiles(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    if (entry.name === "test" || entry.name === "node_modules") return [];
    const path = join(dir, entry.name);
    if (entry.isDirectory()) return tsxFiles(path);
    return entry.name.endsWith(".tsx") ? [path] : [];
  });
}

function openingTagEnd(source: string, from: number): number {
  let depth = 0;
  for (let i = from; i < source.length; i++) {
    const c = source[i];
    if (c === "{") depth++;
    else if (c === "}") depth--;
    else if (c === ">" && depth === 0) return i;
  }
  return -1;
}

function unnamedIconButtons(file: string): string[] {
  const source = readFileSync(file, "utf8");
  const found: string[] = [];
  for (const match of source.matchAll(/<(Button|button)\b/g)) {
    const tag = match[1];
    const start = match.index ?? 0;
    const end = openingTagEnd(source, start + 1);
    if (end < 0) continue;
    const attrs = source.slice(start, end);
    if (attrs.endsWith("/")) continue;
    const close = source.indexOf(`</${tag}>`, end);
    if (close < 0) continue;
    const inner = source.slice(end + 1, close).trim();
    if (!/^<([A-Z]\w*)\b[^<]*\/>$/.test(inner)) continue;
    if (/aria-label|aria-labelledby|\btitle=|asChild/.test(attrs)) continue;
    const line = source.slice(0, start).split("\n").length;
    found.push(`${relative(ROOT, file).replace(/\\/g, "/")}:${line}`);
  }
  return found;
}

describe("icon-only buttons", () => {
  it("every one has an accessible name", () => {
    const offenders = tsxFiles(ROOT).flatMap(unnamedIconButtons);
    expect(offenders).toEqual([]);
  });
});
