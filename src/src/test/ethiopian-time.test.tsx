import { describe, it, expect, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import {
  formatClock,
  formatTimeOfDay,
  fromEthiopianClock,
  resolveClock,
  toClockPreference,
  toEthiopianClock,
  type EthiopianPeriod,
} from "@/lib/calendar/ethiopian-time";
import { formatCalendarDateTime, formatClockTime } from "@/lib/utils/date";
import { CalendarProvider, useCalendar } from "@/lib/calendar/calendar-context";
import { CalendarPreferenceSync } from "@/components/shared/calendar-preference-sync";

/**
 * Ethiopian time of day: the clock starts at dawn, so 06:00 is 12:00 ጠዋት and
 * 18:00 is 12:00 ምሽት. Nothing in the product wrote a time this way until
 * 2026-11-01; every time was 24-hour whatever the reader's language.
 */
const ADDIS = "Africa/Addis_Ababa";

// [24-hour, Ethiopian hour, period, Amharic]
const READINGS: [string, number, EthiopianPeriod, string][] = [
  ["06:00", 12, "morning", "12:00 ጠዋት"],
  ["07:30", 1, "morning", "1:30 ጠዋት"],
  ["11:59", 5, "morning", "5:59 ጠዋት"],
  ["12:00", 6, "day", "6:00 ቀን"],
  ["13:15", 7, "day", "7:15 ቀን"],
  ["17:45", 11, "day", "11:45 ቀን"],
  ["18:00", 12, "evening", "12:00 ምሽት"],
  ["21:05", 3, "evening", "3:05 ምሽት"],
  ["23:59", 5, "evening", "5:59 ምሽት"],
  ["00:00", 6, "night", "6:00 ሌሊት"],
  ["03:20", 9, "night", "9:20 ሌሊት"],
  ["05:59", 11, "night", "11:59 ሌሊት"],
];

describe("the Ethiopian clock", () => {
  it.each(READINGS)("%s is %i in the %s", (time, hour, period, amharic) => {
    const [h, m] = time.split(":").map(Number);
    expect(toEthiopianClock(h, m)).toEqual({ hour, minute: m, period });
    expect(formatClock(h, m, "ethiopian", "am")).toBe(amharic);
  });

  it("reads every minute of the day back to the time it came from", () => {
    for (let h = 0; h < 24; h++) {
      for (const m of [0, 1, 30, 59]) {
        expect(fromEthiopianClock(toEthiopianClock(h, m))).toEqual({
          hour: h,
          minute: m,
        });
      }
    }
  });

  it("names the period in English for an English reader", () => {
    expect(formatClock(7, 30, "ethiopian", "en")).toBe("1:30 morning");
    expect(formatClock(13, 0, "ethiopian", "en")).toBe("7:00 afternoon");
  });

  it("leaves 24-hour times exactly as they were", () => {
    expect(formatClock(7, 5, "24h", "am")).toBe("07:05");
    expect(formatTimeOfDay("18:00:00", "24h", "en")).toBe("18:00");
  });

  it("writes a stored shift time on either clock, and leaves anything else alone", () => {
    expect(formatTimeOfDay("06:00:00", "ethiopian", "am")).toBe("12:00 ጠዋት");
    expect(formatTimeOfDay("not a time", "ethiopian", "am")).toBe("not a time");
    expect(formatTimeOfDay("25:00", "ethiopian", "am")).toBe("25:00");
  });
});

describe("which clock a reader gets", () => {
  it("is Ethiopian time on auto only for Amharic on the Ethiopian calendar", () => {
    expect(resolveClock("auto", "ethiopian", "am")).toBe("ethiopian");
    expect(resolveClock("auto", "ethiopian", "en")).toBe("24h");
    expect(resolveClock("auto", "gregorian", "am")).toBe("24h");
  });

  it("is whatever the person chose, when they chose", () => {
    expect(resolveClock("ethiopian", "gregorian", "en")).toBe("ethiopian");
    expect(resolveClock("24h", "ethiopian", "am")).toBe("24h");
  });

  it("reads anything unknown as auto", () => {
    expect(toClockPreference("12h")).toBe("auto");
    expect(toClockPreference(null)).toBe("auto");
    expect(toClockPreference("24h")).toBe("24h");
  });
});

describe("instants on the Ethiopian clock", () => {
  // 04:30Z is 07:30 in Addis Ababa.
  const instant = "2026-10-20T04:30:00Z";

  it("are read in the tenant's zone, not the browser's", () => {
    expect(formatClockTime(instant, ADDIS, "ethiopian", "am")).toBe("1:30 ጠዋት");
    expect(formatClockTime(instant, ADDIS, "24h", "am")).toBe("07:30");
  });

  it("follow the date in a date and time", () => {
    expect(
      formatCalendarDateTime(instant, ADDIS, "ethiopian", "am", "ethiopian"),
    ).toBe("ጥቅምት 10, 2019, 1:30 ጠዋት");
  });
});

function Shown() {
  const { clockPreference } = useCalendar();
  return <output aria-label="clock in use">{clockPreference}</output>;
}

function renderWithServerClock(clock: string) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: {
          public_id: "U1",
          role: "employee",
          preferences: {
            locale: "am",
            theme: "system",
            calendar: "ethiopian",
            clock,
          },
        },
        tenant: { public_id: "T1", name: "Abay" },
        permissions: [],
      }),
    ),
  );
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <CalendarProvider>
        <CalendarPreferenceSync />
        <Shown />
      </CalendarProvider>
    </QueryClientProvider>,
  );
}

describe("the clock preference", () => {
  beforeEach(() => localStorage.setItem("ethr.clock", "auto"));

  it("follows the signed-in user's choice over this browser's cache", async () => {
    renderWithServerClock("24h");

    await waitFor(() =>
      expect(screen.getByLabelText("clock in use")).toHaveTextContent("24h"),
    );
    expect(localStorage.getItem("ethr.clock")).toBe("24h");
  });
});
