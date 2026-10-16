import { describe, expect, it } from "vitest";
import en from "@/lib/i18n/locales/en.json";
import am from "@/lib/i18n/locales/am.json";

/**
 * Amharic glossary: one concept, one word.
 *
 * The 2026-10-15 frontend audit found "attendance" rendered as "ቅጥር"
 * (hiring) across 63 keys while other keys already said "መገኘት" (presence),
 * so the attendance page, the navigation and the dashboard disagreed with
 * each other, and the two tabs on the employee page (Attendance, Employment)
 * carried the identical label. A key-parity gate cannot see this, and a
 * machine translation pass is exactly how it slipped in, so the glossary is
 * pinned here: a key whose English source says attendance must not use the
 * hiring word in Amharic. Employment, hire and premises keys keep "ቅጥር",
 * which is correct for them, and nothing here asserts a positive wording:
 * that stays the native reviewer's call.
 */
const EN = en as Record<string, string>;
const AM = am as Record<string, string>;

describe("Amharic glossary consistency", () => {
  it("never renders attendance with the hiring word", () => {
    const offenders = Object.entries(AM)
      .filter(
        ([key, value]) =>
          value.includes("ቅጥር") && /attendance/i.test(EN[key] ?? ""),
      )
      .map(([key]) => key);

    expect(offenders).toEqual([]);
  });

  it("keeps the employee page's attendance and employment tabs distinct", () => {
    expect(AM["employee.detail.tab.attendance"]).not.toBe(
      AM["employee.detail.tab.employment"],
    );
  });
});

describe("approvals never borrow the leave word", () => {
  // "ፈቃዶች" is the plural of Leave's "ፈቃድ"; with Approvals beside Leave in
  // the navigation the two read as "Leave / Leaves". Approvals keeps its own
  // word (ማጽደቂያዎች). roles_page.permissions legitimately uses ፈቃዶች for
  // "permissions" and is not a navigation label.
  it("keeps distinct words for the Leave and Approvals labels", () => {
    expect(AM["nav.approvals"]).not.toBe(AM["nav.leave"]);
    expect(AM["nav.approvals"]).not.toBe("ፈቃዶች");
    expect(AM["route.approvals.label"]).not.toBe("ፈቃዶች");
  });
});
