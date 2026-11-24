import { describe, expect, it } from "vitest";
import { readdirSync, readFileSync } from "node:fs";
import { join, relative } from "node:path";

/**
 * A story is drawn in one place (the news upgrade, N2). Two things would put
 * the second copy back, and this fails on each:
 *
 * 1. `NewsMeta` (the date, category and priority word above a title) imported
 *    outside `features/news/`. Every screen that lists a story draws it
 *    through `NewsCard`, which is how the app, the dashboard and the public
 *    pages came to say the same priority three ways before 2026-11-22.
 * 2. The link attributes `noopener noreferrer nofollow ugc` in more than one
 *    source file. They belong to `PlainText`, the one renderer of a story's
 *    text and links; a second copy is a second place to forget `nofollow`.
 *
 * Tests are not scanned: they name both, to pin them.
 */
const SRC = join(process.cwd(), "src");

function sources(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const path = join(dir, entry.name);
    if (entry.isDirectory())
      return entry.name === "node_modules" ? [] : sources(path);
    return /\.tsx?$/.test(entry.name) ? [path] : [];
  });
}

const files = sources(SRC)
  .map((path) => ({
    path: relative(SRC, path).split("\\").join("/"),
    text: readFileSync(path, "utf8"),
  }))
  .filter(
    ({ path }) => !path.startsWith("test/") && !/\.test\.tsx?$/.test(path),
  );

describe("one story, one renderer", () => {
  it("scanned the whole source tree, not a corner of it", () => {
    expect(files.length).toBeGreaterThan(300);
    expect(
      files.some((f) => f.path === "features/news/components/news-card.tsx"),
    ).toBe(true);
  });

  it("imports NewsMeta only inside features/news", () => {
    const IMPORT = /import\s*(?:type\s*)?\{[^}]*\bNewsMeta\b[^}]*\}\s*from/;
    const outside = files
      .filter(({ path }) => !path.startsWith("features/news/"))
      .filter(({ text }) => IMPORT.test(text))
      .map(({ path }) => path);

    expect(outside).toEqual([]);
  });

  it("writes the link attributes of a story's text in one file", () => {
    const holders = files
      .filter(({ text }) => text.includes("noopener noreferrer nofollow ugc"))
      .map(({ path }) => path);

    expect(holders).toEqual(["features/news/components/plain-text.tsx"]);
  });
});
