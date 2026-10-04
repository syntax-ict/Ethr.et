import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import { registerLocale, t } from "@/lib/i18n/translations";
import { publicDictionary } from "@/lib/i18n/public-dictionary";
import { isPublicKey } from "@/lib/i18n/public-keys";

// The public site used to carry the whole Amharic dictionary — 56.6 KB gzipped,
// on English pages too — because `translations.ts` imported it eagerly. These
// pin the three things the replacement depends on.

describe("the shared translations module", () => {
  it("imports no dictionary statically, so none rides along to every page", () => {
    const source = fs.readFileSync(
      path.join(__dirname, "../lib/i18n/translations.ts"),
      "utf8",
    );

    // `import x from "./locales/am.json"` puts the file in every bundle that
    // imports t(). The lazy `import("./locales/am.json")` does not.
    expect(source).not.toMatch(
      /^\s*import\s+\w+\s+from\s+["'][^"']*locales\//m,
    );
  });
});

describe("registerLocale", () => {
  // A locale code of its own, so the suite's global en/am registrations are
  // not disturbed.
  const full = { "x.one": "one", "x.two": "two", "x.three": "three" };
  const projection = { "x.one": "one" };

  it("keeps the full dictionary when a projection registers after it", () => {
    // dashboard (full) → public page (projection) → dashboard again, where the
    // app shell's module does not evaluate a second time.
    registerLocale("zz-a", full);
    registerLocale("zz-a", projection);

    expect(t("x.three", "zz-a")).toBe("three");
  });

  it("ends up with the full dictionary when it registers after a projection", () => {
    registerLocale("zz-b", projection);
    registerLocale("zz-b", full);

    expect(t("x.two", "zz-b")).toBe("two");
    expect(t("x.one", "zz-b")).toBe("one");
  });
});

describe("the public Amharic projection", () => {
  it("exists — it used to be null for the default locale", () => {
    const dictionary = publicDictionary("am");

    expect(dictionary).not.toBeNull();
    expect(dictionary!["marketing.product_flow.net"]).toBe("ተጣሪ");
  });

  it("carries public keys only, so it stays a fraction of the file", () => {
    const dictionary = publicDictionary("am")!;
    const keys = Object.keys(dictionary);

    expect(keys.every(isPublicKey)).toBe(true);
    // A dashboard string is in am.json and must not be in the projection.
    expect(dictionary["payroll.net"]).toBeUndefined();
  });
});
