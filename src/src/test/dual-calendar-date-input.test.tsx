import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import type { ReactNode } from "react";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { toEthiopian, toGregorian } from "@/lib/calendar/ethiopian";

function withCalendar(system: "ethiopian" | "gregorian") {
  localStorage.setItem("ethr.calendar", system);
  function Wrapper({ children }: { children: ReactNode }) {
    return <CalendarProvider>{children}</CalendarProvider>;
  }
  return Wrapper;
}

describe("<DualCalendarDateInput>", () => {
  beforeEach(() => {
    localStorage.removeItem("ethr.calendar");
  });

  describe("gregorian preference", () => {
    it("renders a native date input bound to the ISO value", () => {
      render(
        <DualCalendarDateInput
          value="2026-09-11"
          onChange={vi.fn()}
          aria-label="Hire date"
        />,
        { wrapper: withCalendar("gregorian") },
      );

      const input = screen.getByLabelText("Hire date") as HTMLInputElement;
      expect(input.type).toBe("date");
      expect(input.value).toBe("2026-09-11");
    });

    it("shows the Ethiopian equivalent as a secondary readout", () => {
      // 2026-09-11 Gregorian is Meskerem 1, 2019 Ethiopian (Ethiopian New Year).
      const eth = toEthiopian(2026, 9, 11);
      render(<DualCalendarDateInput value="2026-09-11" onChange={vi.fn()} />, {
        wrapper: withCalendar("gregorian"),
      });

      expect(
        screen.getByText(new RegExp(`Meskerem 1, ${eth.year}`)),
      ).toBeInTheDocument();
    });

    it("passes the raw ISO value straight through on change", () => {
      const onChange = vi.fn();
      render(
        <DualCalendarDateInput
          value=""
          onChange={onChange}
          aria-label="Date"
        />,
        { wrapper: withCalendar("gregorian") },
      );

      fireEvent.change(screen.getByLabelText("Date"), {
        target: { value: "2025-01-15" },
      });
      expect(onChange).toHaveBeenCalledWith("2025-01-15");
    });
  });

  describe("ethiopian preference", () => {
    it("renders year / month / day fields instead of a native picker", () => {
      render(<DualCalendarDateInput value="2026-09-11" onChange={vi.fn()} />, {
        wrapper: withCalendar("ethiopian"),
      });

      const year = screen.getByLabelText("Ethiopian year") as HTMLInputElement;
      expect(year.value).toBe("2019"); // Meskerem 1, 2019
      expect(screen.getByLabelText("Ethiopian month")).toBeInTheDocument();
      expect(screen.getByLabelText("Ethiopian day")).toBeInTheDocument();
    });

    it("emits a Gregorian ISO string when the Ethiopian year changes", () => {
      const onChange = vi.fn();
      render(<DualCalendarDateInput value="2026-09-11" onChange={onChange} />, {
        wrapper: withCalendar("ethiopian"),
      });

      // Change Ethiopian year 2019 → 2018, keeping Meskerem 1.
      fireEvent.change(screen.getByLabelText("Ethiopian year"), {
        target: { value: "2018" },
      });

      const expected = toGregorian(2018, 1, 1);
      const iso = `${expected.getFullYear()}-${String(expected.getMonth() + 1).padStart(2, "0")}-${String(expected.getDate()).padStart(2, "0")}`;
      expect(onChange).toHaveBeenCalledWith(iso);
    });

    it("round-trips: the ISO it emits parses back to the same Ethiopian date", () => {
      const onChange = vi.fn();
      render(<DualCalendarDateInput value="2026-09-11" onChange={onChange} />, {
        wrapper: withCalendar("ethiopian"),
      });

      fireEvent.change(screen.getByLabelText("Ethiopian year"), {
        target: { value: "2015" },
      });

      const iso = onChange.mock.calls[0][0] as string;
      const [y, m, d] = iso.split("-").map(Number);
      const back = toEthiopian(y, m, d);
      expect(back).toEqual({ year: 2015, month: 1, day: 1 });
    });

    it("clamps the day when switching to Pagume (the short 13th month)", async () => {
      const onChange = vi.fn();
      // Start at Meskerem 30, 2015.
      const greg = toGregorian(2015, 1, 30);
      const iso = `${greg.getFullYear()}-${String(greg.getMonth() + 1).padStart(2, "0")}-${String(greg.getDate()).padStart(2, "0")}`;
      render(<DualCalendarDateInput value={iso} onChange={onChange} />, {
        wrapper: withCalendar("ethiopian"),
      });

      // Switch month to Pagume (13). 2015 is not a leap year (2015 % 4 = 3 is,
      // actually) — use the lib to compute the clamp target.
      await userEvent.click(screen.getByLabelText("Ethiopian month"));
      await userEvent.click(screen.getByRole("option", { name: "Pagume" }));

      const emitted = onChange.mock.calls.at(-1)?.[0] as string;
      const [y, m, d] = emitted.split("-").map(Number);
      const back = toEthiopian(y, m, d);
      expect(back.month).toBe(13);
      // Day 30 is impossible in Pagume; it must have clamped to <= 6.
      expect(back.day).toBeLessThanOrEqual(6);
    });
  });
});
