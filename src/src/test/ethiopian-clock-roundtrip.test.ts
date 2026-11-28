import { describe, expect, it } from "vitest";
import {
  formatEthiopianClock,
  fromEthiopianClock,
  toEthiopianClock,
  type EthiopianPeriod,
} from "@/lib/calendar/ethiopian-time";

/*
 * The Ethiopian clock counts twelve hours from dawn and twelve from dusk, so
 * it runs six hours behind the 24-hour clock. The expected readings below are
 * written from that rule, not read from the module: 08:40 reads 2:40 and 17:35
 * reads 11:35 (docs/ATTENDANCE-RULES.md AR-CLOCK-03).
 */

function period(hour24: number): EthiopianPeriod {
  if (hour24 < 6) return "night";
  if (hour24 < 12) return "morning";
  if (hour24 < 18) return "day";
  return "evening";
}

describe("the four edges of the day", () => {
  const edges: Array<[number, number, number, EthiopianPeriod]> = [
    [0, 0, 6, "night"],
    [6, 0, 12, "morning"],
    [12, 0, 6, "day"],
    [18, 0, 12, "evening"],
  ];

  it.each(edges)("%i:%i reads %i %s", (h, m, hour, p) => {
    expect(toEthiopianClock(h, m)).toEqual({ hour, minute: m, period: p });
    expect(fromEthiopianClock({ hour, minute: m, period: p })).toEqual({
      hour: h,
      minute: m,
    });
  });

  const justBefore: Array<[number, number, number, EthiopianPeriod]> = [
    [5, 59, 11, "night"],
    [11, 59, 5, "morning"],
    [17, 59, 11, "day"],
    [23, 59, 5, "evening"],
    [7, 30, 1, "morning"],
  ];

  it.each(justBefore)("%i:%i reads %i:%i %s", (h, m, hour, p) => {
    expect(toEthiopianClock(h, m)).toEqual({ hour, minute: m, period: p });
  });

  it("reads 08:40 as 2:40 and 17:35 as 11:35", () => {
    expect(toEthiopianClock(8, 40)).toMatchObject({ hour: 2, minute: 40 });
    expect(toEthiopianClock(17, 35)).toMatchObject({ hour: 11, minute: 35 });
    expect(formatEthiopianClock(8, 40, "en")).toBe("2:40 morning");
    expect(formatEthiopianClock(17, 35, "en")).toBe("11:35 afternoon");
  });
});

describe("all 1,440 minutes of a day", () => {
  it("each reads 1 to 12, names its quarter, and returns to the same minute", () => {
    const bad: string[] = [];
    const seen = new Set<string>();
    let minutes = 0;

    for (let h = 0; h < 24; h++) {
      for (let m = 0; m < 60; m++) {
        minutes++;
        const t = toEthiopianClock(h, m);
        const label = `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}`;
        if (t.hour < 1 || t.hour > 12) bad.push(`${label}: hour ${t.hour}`);
        if (t.minute !== m) bad.push(`${label}: minute ${t.minute}`);
        if (t.period !== period(h)) bad.push(`${label}: period ${t.period}`);
        // the rule: six hours behind, 0 written 12
        const expectedHour = (((h - 6) % 12) + 12) % 12 || 12;
        if (t.hour !== expectedHour)
          bad.push(`${label}: reads ${t.hour}, the rule says ${expectedHour}`);
        const back = fromEthiopianClock(t);
        if (back.hour !== h || back.minute !== m) {
          bad.push(`${label}: comes back as ${back.hour}:${back.minute}`);
        }
        seen.add(`${t.hour}:${t.minute}:${t.period}`);
      }
    }

    expect(bad).toEqual([]);
    expect(minutes).toBe(1440);
    expect(seen.size).toBe(1440);
  });
});
