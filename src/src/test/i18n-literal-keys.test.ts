import { readdirSync, readFileSync } from "node:fs";
import { join, resolve } from "node:path";
import { describe, expect, it } from "vitest";
import en from "@/lib/i18n/locales/en.json";
import am from "@/lib/i18n/locales/am.json";

/**
 * A key written as a plain string, not inside `t()`, is invisible to the i18n
 * gate: a form's validation message such as "employee.history.step_range" is
 * handed to `t()` only when the error shows, and a missing one shows the raw
 * key to the reader in both languages. Two did, on the History dialog, until
 * 2026-11-01.
 *
 * Any string in the source shaped like a key of three or more parts, whose
 * group (everything before the last dot) already exists in the dictionary,
 * must itself exist in both. Two-part names are left out: they are
 * permissions, events and operation ids ("device.update", "payroll.paid").
 */
const SRC = resolve(__dirname, "..");
const SKIP = new Set(["test", "api", "node_modules"]);

function sources(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) return SKIP.has(entry.name) ? [] : sources(path);
    return /\.(ts|tsx)$/.test(entry.name) ? [path] : [];
  });
}

const dictionaries = {
  en: en as Record<string, string>,
  am: am as Record<string, string>,
};
const groups = new Set(
  Object.keys(dictionaries.en).map((k) => k.slice(0, k.lastIndexOf("."))),
);
const KEY = /["'`]([a-z][a-z0-9_]*(?:\.[a-z0-9_]+){2,})["'`]/g;

const literals = new Map<string, string>();
for (const file of sources(SRC)) {
  for (const [, key] of readFileSync(file, "utf8").matchAll(KEY)) {
    if (groups.has(key.slice(0, key.lastIndexOf("."))))
      literals.set(key, file.slice(SRC.length + 1));
  }
}

describe("keys written as plain strings", () => {
  it("finds the literals it checks", () => {
    expect(literals.has("employee.history.step_range")).toBe(true);
  });

  it.each(Object.entries(dictionaries))("all exist in %s", (_locale, dict) => {
    const missing = [...literals]
      .filter(([key]) => !(key in dict))
      .map(([key, file]) => `${key} (${file})`);
    expect(missing).toEqual([]);
  });
});
