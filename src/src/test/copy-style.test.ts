import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import en from "@/lib/i18n/locales/en.json";
import am from "@/lib/i18n/locales/am.json";

/**
 * House style for interface text, in both languages: no long dashes, no
 * emoji, no exclamation-mark endings. A sentence takes a colon, a comma or a
 * full stop (`።`, `፦`, `፣`, `፤` in Amharic); an empty value is said in words.
 * Set on 2026-10-14 after a pass that rewrote 65 strings in each language;
 * this keeps the next one from creeping back in unnoticed.
 */
const EMOJI =
  /[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}\u{2B50}\u{2705}\u{274C}\u{FE0F}]/u;

describe.each([
  ["en", en as Record<string, string>],
  ["am", am as Record<string, string>],
])("%s interface text", (_locale, strings) => {
  const entries = Object.entries(strings);

  it("has no long dash", () => {
    expect(entries.filter(([, v]) => v.includes("—")).map(([k]) => k)).toEqual(
      [],
    );
  });

  it("has no emoji", () => {
    expect(entries.filter(([, v]) => EMOJI.test(v)).map(([k]) => k)).toEqual(
      [],
    );
  });

  it("ends no string with an exclamation mark", () => {
    expect(entries.filter(([, v]) => /!\s*$/.test(v)).map(([k]) => k)).toEqual(
      [],
    );
  });
});

/**
 * The web app manifest is interface text too: a phone shows its name when the
 * app is installed and its shortcuts on a long press. It read "ETHR — Ethiopian
 * Workforce OS" (production QA 2026-10-28, F-06).
 */
describe("installed app text", () => {
  const manifest = JSON.parse(
    readFileSync(resolve(__dirname, "../../public/manifest.json"), "utf8"),
  ) as {
    name: string;
    short_name: string;
    description: string;
    shortcuts?: Array<{
      name: string;
      short_name?: string;
      description?: string;
    }>;
  };
  const strings = [
    manifest.name,
    manifest.short_name,
    manifest.description,
    ...(manifest.shortcuts ?? []).flatMap((s) => [
      s.name,
      s.short_name ?? "",
      s.description ?? "",
    ]),
  ];

  it("has no long dash, emoji or exclamation-mark ending", () => {
    expect(
      strings.filter(
        (v) => v.includes("—") || EMOJI.test(v) || /!\s*$/.test(v),
      ),
    ).toEqual([]);
  });
});
