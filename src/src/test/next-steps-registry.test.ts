import { describe, expect, it } from "vitest";
import en from "@/lib/i18n/locales/en.json";
import am from "@/lib/i18n/locales/am.json";
import {
  PROFILE_ITEM_HREF,
  PROFILE_ITEMS,
  STEP_DEFS,
  stepKey,
} from "@/features/next-steps/registry";

/**
 * Next Steps copy is read through computed keys, so the i18n gate cannot see a
 * missing one and the row would render the bare key. `STEP_DEFS` is typed by
 * the server's `NextStepId` union, so a step the API gains without an entry
 * fails the type check; this is what makes sure each entry has its words.
 */
const EN = en as Record<string, string>;
const AM = am as Record<string, string>;

describe.each([
  ["en", EN],
  ["am", AM],
])("next steps copy in %s", (_locale, strings) => {
  const ids = Object.keys(STEP_DEFS) as (keyof typeof STEP_DEFS)[];

  it.each(ids)("has a title, a reason and an action for %s", (id) => {
    expect(strings[stepKey(id, "title")], "title").toBeTruthy();
    expect(strings[stepKey(id, "reason")], "reason").toBeTruthy();
    expect(strings[stepKey(id, "cta")], "cta").toBeTruthy();
  });

  it("has the singular title of every step that counts things", () => {
    const missing = ids.filter(
      (id) => STEP_DEFS[id].plural && !strings[stepKey(id, "title_one")],
    );
    expect(missing).toEqual([]);
  });

  it("names every profile field a checklist can list", () => {
    const missing = PROFILE_ITEMS.filter(
      (item) => !strings[`next_steps.profile_item.${item}`],
    );
    expect(missing).toEqual([]);
  });

  it("names every record gap the employees list can be filtered by", () => {
    const missing = ["tin", "bank", "emergency_contact", "phone"].filter(
      (gap) => !strings[`employees.missing.${gap}`],
    );
    expect(missing).toEqual([]);
  });

  it("keeps the placeholders the card fills in, and no others", () => {
    const known = new Set([":count", ":period", ":done", ":total", ":gap"]);
    const stray = Object.entries(strings)
      .filter(([key]) => key.startsWith("next_steps."))
      .flatMap(([key, value]) =>
        (value.match(/:[a-z]+/g) ?? [])
          .filter((token) => !known.has(token))
          .map((token) => `${key}: ${token}`),
      );
    expect(stray).toEqual([]);
  });
});

describe("next steps in Amharic", () => {
  it("does not leave a step in English", () => {
    const sameAsEnglish = Object.keys(EN)
      .filter((key) => key.startsWith("next_steps.step."))
      .filter((key) => AM[key] === EN[key]);
    expect(sameAsEnglish).toEqual([]);
  });

  it("keeps the placeholders of every sentence in step with English", () => {
    const tokens = (s: string) => (s.match(/:[a-z]+/g) ?? []).sort();
    const drift = Object.keys(EN)
      .filter((key) => key.startsWith("next_steps."))
      .filter((key) => tokens(EN[key]).join() !== tokens(AM[key] ?? "").join());
    expect(drift).toEqual([]);
  });
});

describe("profile checklist links", () => {
  it("sends every field somewhere a person can fill it in", () => {
    for (const item of PROFILE_ITEMS) {
      expect(PROFILE_ITEM_HREF[item]).toMatch(/^\/profile/);
    }
  });
});
