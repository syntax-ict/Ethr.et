import { describe, it, expect } from "vitest";
import { approvalSummary } from "@/features/approvals/summary";

/**
 * The queue line is built from the request's calendar dates through the
 * page's own formatter, so it reads in the reader's calendar and language;
 * an answer without the dates falls back to the server's summary.
 */
const t = (
  key: string,
  fallback?: string,
  replacements?: Record<string, string | number>,
) =>
  Object.entries(replacements ?? {}).reduce(
    (text, [name, value]) => text.replace(`:${name}`, String(value)),
    fallback ?? key,
  );

// Stands in for useDateFormatters().formatDate with the Ethiopian calendar on.
const formatDate = (date: string) => `ET(${date})`;

describe("approvalSummary", () => {
  it("names a leave request's type and dates in the reader's calendar", () => {
    const line = approvalSummary(
      {
        type: "leave",
        summary: "Annual Leave: Nov 09 - Nov 12",
        leave_type: { name: "Annual Leave", name_am: "ዓመታዊ ፈቃድ" },
        from: "2026-11-09",
        to: "2026-11-12",
      },
      t,
      formatDate,
      "am",
    );

    expect(line).toBe("ዓመታዊ ፈቃድ: ET(2026-11-09) - ET(2026-11-12)");
  });

  it("names a correction's and an overtime request's date", () => {
    expect(
      approvalSummary(
        { type: "correction", summary: "x", date: "2026-11-03" },
        t,
        formatDate,
        "en",
      ),
    ).toBe("Attendance correction for ET(2026-11-03)");
    expect(
      approvalSummary(
        { type: "overtime", summary: "x", date: "2026-11-03", hours: 2.5 },
        t,
        formatDate,
        "en",
      ),
    ).toBe("Overtime on ET(2026-11-03), 2.5 hours");
  });

  it("falls back to the server's summary when the dates did not travel", () => {
    expect(
      approvalSummary(
        { type: "leave", summary: "Annual Leave: Nov 09 - Nov 12" },
        t,
        formatDate,
        "am",
      ),
    ).toBe("Annual Leave: Nov 09 - Nov 12");
    expect(
      approvalSummary(
        { type: "profile_update", summary: "phone: a → b" },
        t,
        formatDate,
        "en",
      ),
    ).toBe("phone: a → b");
  });
});
