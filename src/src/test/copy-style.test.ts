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
