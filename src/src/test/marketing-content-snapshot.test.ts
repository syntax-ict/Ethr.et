import { describe, expect, it } from "vitest";
import snapshot from "@/lib/marketing/content-snapshot.json";
import { MARKETING_ICONS } from "@/lib/marketing/icons";

/**
 * The committed landing-page snapshot is what `/en` and `/am` prerender, so
 * it is held to the same rules as the copy it replaced: none of the claims the
 * capability audit of 2026-10-17 took off the site, the house style, and only
 * icons the page can draw. Its values are not pinned: the platform operator
 * edits the page, and the snapshot is regenerated from production before a
 * release.
 */
const FORBIDDEN = [
  /performance review|appraisal|training|certif|goals|OKR/i,
  /Hikvision|ZKTeco|Suprema/i,
  /biometric|fingerprint/i,
  /Excel|multi-level|delegat/i,
  /full compliance/i,
  /No data leaves/i,
  /Your Data, Your Servers/i,
  /Every action is logged/i,
  /No cross-tenant access is possible/i,
  /99\.9%|500\+|50,000\+|1M\+/,
];
const EMOJI =
  /[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}\u{2B50}\u{2705}\u{274C}\u{FE0F}]/u;

type Row = Record<string, unknown>;

function strings(): Array<[string, string]> {
  const out: Array<[string, string]> = [];
  for (const section of snapshot.data.sections as Row[]) {
    for (const f of ["heading", "heading_am", "subheading", "subheading_am"]) {
      if (typeof section[f] === "string")
        out.push([`${section.kind}.${f}`, section[f] as string]);
    }
    for (const item of section.items as Row[]) {
      for (const f of [
        "title",
        "title_am",
        "body",
        "body_am",
        "detail",
        "detail_am",
      ]) {
        if (typeof item[f] === "string")
          out.push([`${section.kind}.${item.slug}.${f}`, item[f] as string]);
      }
    }
  }
  return out;
}

describe("the committed landing-page snapshot", () => {
  it("has a hero and at least the problems and capabilities sections", () => {
    const kinds = snapshot.data.sections.map((s) => s.kind);
    expect(kinds).toContain("hero");
    expect(kinds).toContain("problems");
    expect(kinds).toContain("capabilities");
  });

  it("makes none of the claims the audit took off the site", () => {
    const offending = strings().filter(([, text]) =>
      FORBIDDEN.some((re) => re.test(text)),
    );
    expect(offending).toEqual([]);
  });

  it("keeps the house style: no long dash, no emoji, no exclamation ending", () => {
    const offending = strings().filter(
      ([, text]) =>
        text.includes("—") || EMOJI.test(text) || /!\s*$/.test(text),
    );
    expect(offending).toEqual([]);
  });

  it("names only icons the page can draw", () => {
    const unknown: string[] = [];
    for (const section of snapshot.data.sections as Row[]) {
      for (const item of section.items as Row[]) {
        const icon = item.icon;
        if (
          typeof icon === "string" &&
          !(MARKETING_ICONS as readonly string[]).includes(icon)
        ) {
          unknown.push(`${section.kind}.${item.slug}: ${icon}`);
        }
      }
    }
    expect(unknown).toEqual([]);
  });

  it("gives every picture a size and alt text in both languages", () => {
    const bad: string[] = [];
    const check = (where: string, image: unknown) => {
      if (!image) return;
      const img = image as Row;
      if (!img.width || !img.height || !img.alt || !img.alt_am || !img.srcset)
        bad.push(where);
    };
    for (const section of snapshot.data.sections as Row[]) {
      check(section.kind as string, section.image);
      for (const item of section.items as Row[])
        check(`${section.kind}.${item.slug}`, item.image);
    }
    expect(bad).toEqual([]);
  });
});
