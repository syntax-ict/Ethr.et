import { describe, it, expect } from "vitest";
import { genderLabel, namedGenders } from "@/lib/i18n/genderLabel";
import en from "@/lib/i18n/locales/en.json";
import am from "@/lib/i18n/locales/am.json";
import om from "@/lib/i18n/locales/om.json";

/**
 * Employees with no gender on file are grouped under the empty string by the
 * executive dashboard's `groupBy('gender')`. In the Gender Distribution
 * charts that group had no name at all (UAT 2026-11-17, D-1).
 */
const dictionaries: Record<string, Record<string, string>> = { en, am, om };
const translator = (locale: string) => (key: string, fallback?: string) =>
  dictionaries[locale][key] ?? fallback ?? key;

describe("genderLabel", () => {
  it.each(["en", "am", "om"])(
    "names the group with no gender on file in %s",
    (locale) => {
      const t = translator(locale);
      const named = genderLabel(t, "");

      expect(named).not.toBe("");
      expect(named).toBe(
        dictionaries[locale]["executive_dashboard.gender_unrecorded"],
      );
      expect(genderLabel(t, null)).toBe(named);
      expect(genderLabel(t, undefined)).toBe(named);
    },
  );

  it("says Not recorded in English, and a different word in the other two", () => {
    expect(genderLabel(translator("en"), "")).toBe("Not recorded");
    expect(genderLabel(translator("am"), "")).not.toBe("Not recorded");
    expect(genderLabel(translator("om"), "")).not.toBe("Not recorded");
  });

  it("translates the two stored values and passes anything else through", () => {
    const t = translator("am");

    expect(genderLabel(t, "male")).toBe(am["common.male"]);
    expect(genderLabel(t, "female")).toBe(am["common.female"]);
    expect(genderLabel(t, "other")).toBe("other");
  });
});

describe("namedGenders", () => {
  it("adds NULL and empty-string groups together under one name", () => {
    const rows = [
      { gender: null, count: 2 },
      { gender: "female", count: 5 },
      { gender: "", count: 1 },
      { gender: "male", count: 3 },
    ];

    expect(namedGenders(translator("en"), rows)).toEqual([
      { gender: "Not recorded", count: 3 },
      { gender: "Female", count: 5 },
      { gender: "Male", count: 3 },
    ]);
  });
});
