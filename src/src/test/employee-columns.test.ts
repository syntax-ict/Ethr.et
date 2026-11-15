import { describe, expect, it } from "vitest";
import en from "@/lib/i18n/locales/en.json";
import am from "@/lib/i18n/locales/am.json";
import om from "@/lib/i18n/locales/om.json";
import {
  EMPLOYEE_COLUMNS,
  FALLBACK_TEMPLATE_KEYS,
  MAPPABLE_COLUMNS,
  columnHelp,
  columnLabel,
  columnWords,
} from "@/features/employees/employee-columns";

const dictionaries = {
  en: en as Record<string, string>,
  am: am as Record<string, string>,
  om: om as Record<string, string>,
};

const words = Object.entries(EMPLOYEE_COLUMNS).flatMap(([column, entry]) => [
  { column, kind: "label", words: entry.label },
  ...(entry.help ? [{ column, kind: "help", words: entry.help }] : []),
]);

describe("the employee column words", () => {
  it.each(Object.entries(dictionaries))(
    "every label and help key exists in %s",
    (_locale, dict) => {
      const missing = words
        .filter(({ words: [key] }) => !(key in dict))
        .map(({ column, kind, words: [key] }) => `${column} ${kind}: ${key}`);
      expect(missing).toEqual([]);
    },
  );

  it("every fallback is what en.json says", () => {
    const drift = words
      .filter(
        ({ words: [key, fallback] }) => en[key as keyof typeof en] !== fallback,
      )
      .map(({ column, kind }) => `${column} ${kind}`);
    expect(drift).toEqual([]);
  });

  it("every column has a help text, so the workbook's instructions have no blank row", () => {
    const blank = Object.entries(EMPLOYEE_COLUMNS)
      .filter(([, entry]) => !entry.help)
      .map(([column]) => column);
    expect(blank).toEqual([]);
  });

  it("the template spellings and salary_cents are the same columns", () => {
    expect(columnWords("department")).toBe(EMPLOYEE_COLUMNS.department_code);
    expect(columnWords("branch")).toBe(EMPLOYEE_COLUMNS.branch_code);
    expect(columnWords("position")).toBe(EMPLOYEE_COLUMNS.position_code);
    expect(columnWords("salary_cents")).toBe(EMPLOYEE_COLUMNS.salary);
  });

  it("lists the server's mappable columns first, name first, with the template's spellings", () => {
    expect(MAPPABLE_COLUMNS[0]).toBe("name");
    expect(FALLBACK_TEMPLATE_KEYS).toContain("department");
    expect(FALLBACK_TEMPLATE_KEYS).not.toContain("department_code");
    expect(FALLBACK_TEMPLATE_KEYS).toHaveLength(MAPPABLE_COLUMNS.length);
  });

  it("a column nobody has words for shows under its own key", () => {
    const t = (key: string, fallback?: string) => fallback ?? key;
    expect(columnLabel("unheard_of", t)).toBe("unheard_of");
    expect(columnHelp("unheard_of", t)).toBeUndefined();
    expect(columnLabel("salary_cents", t)).toBe("Salary");
  });
});
