import { describe, it, expect, beforeEach } from "vitest";
import { act, render, screen } from "@testing-library/react";
import om from "@/lib/i18n/locales/om.json";
import en from "@/lib/i18n/locales/en.json";
import {
  AVAILABLE_LOCALES,
  intlLocale,
  isAvailableLocale,
  nativeLanguageName,
  supportedLocales,
} from "@/lib/i18n/config";
import { getLocale, registerLocale, setLocale } from "@/lib/i18n/translations";
import { useT } from "@/lib/i18n/useT";
import { formatEthiopian, ethiopianMonthName } from "@/lib/calendar/ethiopian";
import { formatClock, resolveClock } from "@/lib/calendar/ethiopian-time";
import {
  formatCalendarDate,
  formatCalendarDateTime,
  formatCalendarWeekday,
} from "@/lib/utils/date";
import { toLetterLanguage } from "@/features/letters/api";

/**
 * Afaan Oromoo, promoted from a stub to a shipped language on 2026-11-02.
 *
 * The dictionary's completeness is the i18n gate's job (`scripts/i18n-check.js`
 * holds om.json to every rule am.json is held to). This covers what the gate
 * cannot see: that the app actually offers the language, and that dates and
 * times are written the way an Oromo reader writes them rather than falling
 * through to English.
 */
const ADDIS = "Africa/Addis_Ababa";

describe("Oromo is a shipped language", () => {
  beforeEach(() => localStorage.clear());

  it("is available, so routes, negotiation and the switchers offer it", () => {
    expect(AVAILABLE_LOCALES).toContain("om");
    expect(isAvailableLocale("om")).toBe(true);
    expect(supportedLocales.find((l) => l.code === "om")?.status).toBe(
      "available",
    );
    expect(nativeLanguageName("om")).toBe("Afaan Oromoo");
  });

  it("has every key English has", () => {
    const dictionary = om as Record<string, string>;
    const missing = Object.keys(en).filter((k) => dictionary[k] === undefined);
    expect(missing).toEqual([]);
  });

  it("persists a choice of Oromo instead of refusing it", () => {
    act(() => setLocale("om"));
    expect(localStorage.getItem("locale")).toBe("om");
    expect(getLocale()).toBe("om");
    expect(document.documentElement.lang).toBe("om");
  });

  it("renders the Oromo string once the dictionary is registered", () => {
    registerLocale("om", om as Record<string, string>);
    localStorage.setItem("locale", "om");
    function Probe() {
      const { t } = useT();
      return <p>{t("settings.oromo", "Afaan Oromo")}</p>;
    }
    render(<Probe />);
    expect(screen.getByText("Afaan Oromoo")).toBeInTheDocument();
  });

  it("writes letters in Oromo, and anything unknown in English", () => {
    expect(toLetterLanguage("om")).toBe("om");
    expect(toLetterLanguage("ti")).toBe("en");
  });
});

describe("dates and times for an Oromo reader", () => {
  it("names the Ethiopian months in Oromo", () => {
    expect(ethiopianMonthName(1, "om")).toBe("Fulbaana");
    expect(ethiopianMonthName(13, "om")).toBe("Qaammee");
    expect(formatEthiopian({ year: 2019, month: 2, day: 22 }, "om")).toBe(
      "Onkololeessa 22, 2019",
    );
  });

  it("formats an Ethiopian-calendar date in Oromo", () => {
    // 1 Nov 2026 is Tikimt 22, 2019.
    expect(formatCalendarDate("2026-11-01", ADDIS, "ethiopian", "om")).toBe(
      "Onkololeessa 22, 2019",
    );
  });

  it("counts the day from dawn by default, as Amharic does", () => {
    expect(resolveClock("auto", "ethiopian", "om")).toBe("ethiopian");
    expect(resolveClock("auto", "gregorian", "om")).toBe("24h");
    expect(resolveClock("24h", "ethiopian", "om")).toBe("24h");
    expect(formatClock(7, 30, "ethiopian", "om")).toBe("1:30 ganama");
    expect(formatClock(13, 0, "ethiopian", "om")).toBe("7:00 waaree booda");
    expect(formatClock(19, 15, "ethiopian", "om")).toBe("1:15 galgala");
    expect(formatClock(0, 0, "ethiopian", "om")).toBe("6:00 halkan");
  });

  it("writes the time of an instant on the Ethiopian clock", () => {
    expect(
      formatCalendarDateTime(
        "2026-11-01T04:30:00Z",
        ADDIS,
        "ethiopian",
        "om",
        "ethiopian",
      ),
    ).toBe("Onkololeessa 22, 2019, 1:30 ganama");
  });

  it("uses Oromo names on the Gregorian calendar where Intl has them", () => {
    if (intlLocale("om") !== "om-ET") return; // a reduced-ICU runtime
    expect(formatCalendarWeekday("2026-11-02", ADDIS, "om", "long")).toBe(
      "Wiixata",
    );
    expect(formatCalendarDate("2026-11-02", ADDIS, "gregorian", "om")).toMatch(
      /Sad/,
    );
  });

  it("falls back to English rather than the device's language", () => {
    expect(intlLocale("en")).toBe("en-US");
    expect(intlLocale("en", "en-GB")).toBe("en-GB");
    expect(intlLocale("ti", "en-GB")).toBe("en-GB");
    expect(["om-ET", "en-US"]).toContain(intlLocale("om"));
  });
});
