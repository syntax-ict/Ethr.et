import { describe, it, expect } from "vitest";
import { ROUTE_META, routeI18nKey } from "@/lib/route-meta";
import en from "@/lib/i18n/locales/en.json";
import am from "@/lib/i18n/locales/am.json";

/**
 * Review 2026-10-30, D2: 12 pages had no title key, so the header showed
 * "Add Employee" and eleven others in English on the Amharic interface. The
 * i18n gate cannot see these keys: `routeI18nKey` builds them at runtime from
 * the route table. This reads the table and checks both dictionaries.
 */
const dictionaries = { en, am } as Record<string, Record<string, string>>;

describe("route titles", () => {
  it.each(Object.keys(dictionaries))(
    "every page in the route table has a %s title and description",
    (locale) => {
      const dict = dictionaries[locale];
      const missing = Object.entries(ROUTE_META).flatMap(([path, meta]) => [
        ...(routeI18nKey(path, "label") in dict ? [] : [`${path} label`]),
        ...(meta.description && !(routeI18nKey(path, "desc") in dict)
          ? [`${path} desc`]
          : []),
      ]);
      expect(missing).toEqual([]);
    },
  );
});
