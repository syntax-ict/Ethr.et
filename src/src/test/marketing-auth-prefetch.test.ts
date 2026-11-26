import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, resolve } from "node:path";
import { describe, expect, it } from "vitest";

/**
 * A public page never prefetches a page of the signed-in shells.
 *
 * `/register` and `/login` live in the `(auth)` group, whose layout renders
 * `AmharicDictionary` and so carries the whole of `am.json`. Next prefetches a
 * `<Link>` once it is on screen, so a "Start free trial" button on the home
 * page fetched that 604 KB chunk on every visit, English included: production
 * QA 2026-11-13, PERF-009, the regression #491 and #500 had closed. A public
 * page carries only `public-dictionary.ts`'s projection.
 */
const SRC = resolve(__dirname, "..");
const ROOTS = [
  "components/marketing",
  "components/layouts/marketing-header.tsx",
  "components/layouts/marketing-footer.tsx",
  "app/(marketing)",
  "app/(root)",
];

function files(path: string): string[] {
  const full = join(SRC, path);
  if (statSync(full).isFile()) return [full];
  return readdirSync(full).flatMap((name) => files(join(path, name)));
}

const sources = ROOTS.flatMap(files).filter((f) => f.endsWith(".tsx"));
const AUTH_LINK =
  /<Link\b[^>]*\bhref=["']\/(register|login)(\?[^"']*)?["'][^>]*>/g;

const links = sources.flatMap((file) =>
  [...readFileSync(file, "utf8").matchAll(AUTH_LINK)].map((m) => ({
    file: file.slice(SRC.length + 1),
    tag: m[0],
  })),
);

describe("marketing links into the signed-in shells", () => {
  it("reads the marketing source, not an empty walk", () => {
    expect(sources.length).toBeGreaterThan(20);
    expect(links.length).toBeGreaterThanOrEqual(10);
  });

  it("never prefetch the (auth) group", () => {
    expect(
      links
        .filter(({ tag }) => !/\bprefetch=\{false\}/.test(tag))
        .map(({ file, tag }) => `${file}: ${tag}`),
    ).toEqual([]);
  });
});
