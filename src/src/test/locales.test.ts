import { describe, it, expect } from "vitest";
import { supportedLocales, preloadLocale, t } from "@/lib/i18n/translations";

describe("locale registry", () => {
  it("offers all six supported Ethiopian locales", () => {
    const codes = supportedLocales.map((l) => l.code);
    expect(codes).toEqual(["en", "am", "om", "ti", "so", "sid"]);
  });

  it("labels each locale with its endonym", () => {
    const byCode = Object.fromEntries(
      supportedLocales.map((l) => [l.code, l.nativeName]),
    );
    expect(byCode.om).toBe("Afaan Oromoo");
    expect(byCode.ti).toBe("ትግርኛ");
    expect(byCode.so).toBe("Soomaali");
    expect(byCode.sid).toBe("Sidaamu Afoo");
  });

  it.each(["om", "ti", "so", "sid"])(
    "loads the %s stub without throwing and falls back gracefully",
    async (code) => {
      await preloadLocale(code);
      // Stub locales carry no UI keys yet; an unknown key resolves to itself,
      // never undefined, so the UI can never render a blank string.
      expect(t("common.save", code)).toBeTypeOf("string");
      expect(t("__nonexistent_key__", code)).toBe("__nonexistent_key__");
    },
  );

  it.each(["om", "ti", "so", "sid"])(
    "does not silently substitute Amharic for a key missing from the %s stub",
    async (code) => {
      await preloadLocale(code);
      // "common.save" is a real key, translated in `am`. A stub locale must
      // not fall through to the Amharic text for it — that was the actual
      // reported defect: a user who chose Oromo/Tigrinya/Somali/Sidaama saw
      // Amharic UI throughout. It must resolve to the raw key instead, so
      // `useT()`'s caller-supplied English fallback string takes over.
      expect(t("common.save", code)).toBe("common.save");
    },
  );
});
