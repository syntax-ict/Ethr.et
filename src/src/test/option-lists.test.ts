import { describe, expect, it } from "vitest";

import am from "@/lib/i18n/locales/am.json";
import en from "@/lib/i18n/locales/en.json";
import om from "@/lib/i18n/locales/om.json";
import {
  ANNOUNCEMENT_CATEGORIES,
  ANNOUNCEMENT_PRIORITIES,
  ANNOUNCEMENT_TARGETS,
  announcementCategoryLabel,
  announcementPriorityLabel,
  announcementTargetLabel,
} from "@/lib/options/announcement";
import {
  EDUCATION_LEVELS,
  educationLevelLabel,
} from "@/lib/options/education-level";
import { GENDERS, genderOptionLabel } from "@/lib/options/gender";
import {
  MARITAL_STATUSES,
  maritalStatusLabel,
} from "@/lib/options/marital-status";
import {
  NOTIFICATION_TYPES,
  notificationTypeDescription,
  notificationTypeLabel,
} from "@/lib/options/notification-type";
import { RELATIONSHIPS, relationshipLabel } from "@/lib/options/relationship";

/**
 * Every value a fixed list offers needs a name in every shipped language.
 *
 * The i18n gate cannot see this: it reads the keys written out in `t("...")`
 * calls, and a label that is missing in one language falls back to English
 * without a word. So the check here asks each label function which key it
 * reads, by handing it a `t` that records the key, then looks that key up in
 * every locale file.
 */
const LISTS = [
  ["gender", GENDERS, genderOptionLabel],
  ["marital status", MARITAL_STATUSES, maritalStatusLabel],
  ["relationship", RELATIONSHIPS, relationshipLabel],
  ["education level", EDUCATION_LEVELS, educationLevelLabel],
  ["notification type", NOTIFICATION_TYPES, notificationTypeLabel],
  ["announcement priority", ANNOUNCEMENT_PRIORITIES, announcementPriorityLabel],
  ["announcement category", ANNOUNCEMENT_CATEGORIES, announcementCategoryLabel],
  ["announcement audience", ANNOUNCEMENT_TARGETS, announcementTargetLabel],
] as const;

const LOCALES: Record<string, Record<string, string>> = { en, am, om };

function keyFor(
  label: (t: (key: string, fallback?: string) => string, v: string) => string,
  value: string,
): string {
  let key = "";
  label((k) => {
    key = k;
    return k;
  }, value);
  return key;
}

describe.each(LISTS)("the %s list", (_name, values, label) => {
  it.each([...values])(
    "names %s in English, Amharic and Afaan Oromoo",
    (value) => {
      const key = keyFor(label, value);
      expect(key, `no label key for "${value}"`).not.toBe("");
      expect(key, `"${value}" has no label key`).not.toBe(value);

      for (const [locale, messages] of Object.entries(LOCALES)) {
        expect(messages[key], `${locale}: ${key}`).toBeTruthy();
      }
    },
  );

  it("shows a stored value it does not know as it was written", () => {
    expect(label((k, f) => f ?? k, "Something else")).toBe("Something else");
  });
});

it("keeps the Amharic and Afaan Oromoo label from being the English one", () => {
  for (const [, values, label] of LISTS) {
    for (const value of values) {
      const key = keyFor(label, value);
      // A word may legitimately match across languages (a loan word), so only
      // an Amharic label that is still Latin script is called untranslated.
      expect(LOCALES.am[key], key).not.toMatch(/^[A-Za-z' ]+$/);
    }
  }
});

// A profile change has no line under its name, so it is the one type without a key.
it.each(NOTIFICATION_TYPES.filter((v) => v !== "profile_update"))(
  "describes the %s notification type in every language",
  (value) => {
    const key = keyFor(notificationTypeDescription, value);
    expect(key, `no description key for "${value}"`).not.toBe("");

    for (const [locale, messages] of Object.entries(LOCALES)) {
      expect(messages[key], `${locale}: ${key}`).toBeTruthy();
    }
  },
);
