import { describe, it, expect } from "vitest";
import en from "@/lib/i18n/locales/en.json";
import am from "@/lib/i18n/locales/am.json";
import { auditActionLabel, auditEntityLabel } from "@/lib/audit/labels";

// The console printed stored codes (`user.mfa_verified`) and PHP class names
// (`AttendanceIntegrityFlag`) as the audit trail's text, in Amharic as in
// English (production, 2026-10-28).

function translator(dict: Record<string, string>) {
  return (key: string, fallback?: string) => dict[key] ?? fallback ?? key;
}

const english = translator(en as Record<string, string>);
const amharic = translator(am as Record<string, string>);

describe("audit action labels", () => {
  it("names a sign-in in words, in both languages", () => {
    expect(auditActionLabel("user.login", english)).toBe("Signed in");
    expect(auditActionLabel("user.login", amharic)).toBe(
      (am as Record<string, string>)["audit.action.user.login"],
    );
    expect(auditActionLabel("user.login", amharic)).not.toMatch(/[a-z]/);
  });

  it("names the actions the platform feed showed as codes", () => {
    for (const action of [
      "user.device_trusted",
      "user.mfa_verified",
      "user.mfa_failed",
      "attendance.integrity_accepted",
      "attendance.conflict_flagged",
      "attendance.conflict_deduped",
      "branch.updated",
      "attendance.site_created",
      "profile.preferences_updated",
    ]) {
      const label = auditActionLabel(action, english);
      expect(label).not.toContain(".");
      expect(label).not.toContain("_");
      expect(label).not.toContain("›");
    }
  });

  it("reads an action added later as words rather than a code", () => {
    expect(auditActionLabel("payroll.something_new", english)).toBe(
      "Payroll · something new",
    );
    expect(auditActionLabel("brand_new.thing_done", english)).toBe(
      "Brand new · thing done",
    );
  });

  it("has an Amharic label for every English one", () => {
    const actionKeys = Object.keys(en).filter((k) =>
      k.startsWith("audit.action."),
    );
    expect(actionKeys.length).toBeGreaterThan(300);
    for (const key of actionKeys) {
      expect((am as Record<string, string>)[key], key).toBeTruthy();
      expect((am as Record<string, string>)[key], key).not.toBe(
        (en as Record<string, string>)[key],
      );
    }
  });
});

describe("audit entity labels", () => {
  it("names a model by what it is, not by its class", () => {
    expect(
      auditEntityLabel("App\\Models\\AttendanceIntegrityFlag", english),
    ).toBe("Attendance flag");
    expect(auditEntityLabel("App\\Models\\ApiKey", english)).toBe("API key");
  });

  it("humanises a class it has no label for, and says nothing for none", () => {
    expect(auditEntityLabel("App\\Models\\SomethingNew", english)).toBe(
      "Something new",
    );
    expect(auditEntityLabel(null, english)).toBeNull();
  });
});
