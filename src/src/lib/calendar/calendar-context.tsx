"use client";

import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from "react";
import { toEthiopian, formatEthiopian } from "./ethiopian";

export type CalendarSystem = "ethiopian" | "gregorian";

const STORAGE_KEY = "ethr.calendar";

/**
 * The display mode for a stored calendar preference.
 *
 * The API still accepts `dual`, which a user could choose before the option was
 * withdrawn (audit N33); no display implements it, so it — like anything else
 * unrecognised — shows as Ethiopian, the app's default.
 */
export function toCalendarSystem(value: unknown): CalendarSystem {
  return value === "gregorian" ? "gregorian" : "ethiopian";
}

interface CalendarContextValue {
  calendar: CalendarSystem;
  setCalendar: (system: CalendarSystem) => void;
  toggle: () => void;
  formatDate: (date: Date, locale?: string) => string;
  formatDateDual: (
    date: Date,
    locale?: string,
  ) => { primary: string; secondary: string };
}

const CalendarContext = createContext<CalendarContextValue | null>(null);

function readStoredCalendar(): CalendarSystem {
  if (typeof window === "undefined") return "ethiopian";
  try {
    return toCalendarSystem(localStorage.getItem(STORAGE_KEY));
  } catch {
    return "ethiopian";
  }
}

/**
 * Holds the calendar dates are displayed in.
 *
 * The source of truth is the signed-in user's preference (`/auth/me` →
 * `preferences.calendar`, which falls back to the organisation's default) and
 * `CalendarPreferenceSync` applies it. `localStorage` is only the cache that
 * covers the moments before that answer arrives, or when it cannot.
 */
export function CalendarProvider({ children }: { children: ReactNode }) {
  const [calendar, setCalendarState] =
    useState<CalendarSystem>(readStoredCalendar);

  const setCalendar = useCallback((system: CalendarSystem) => {
    setCalendarState(system);
    try {
      localStorage.setItem(STORAGE_KEY, system);
    } catch {
      /* ignore */
    }
  }, []);

  const toggle = useCallback(() => {
    setCalendar(calendar === "ethiopian" ? "gregorian" : "ethiopian");
  }, [calendar, setCalendar]);

  const formatGreg = useCallback(
    (date: Date, locale: string) =>
      new Intl.DateTimeFormat(locale === "am" ? "am-ET" : "en-US", {
        year: "numeric",
        month: "long",
        day: "numeric",
      }).format(date),
    [],
  );

  const formatEth = useCallback((date: Date, locale: string) => {
    const eth = toEthiopian(
      date.getFullYear(),
      date.getMonth() + 1,
      date.getDate(),
    );
    return formatEthiopian(eth, locale);
  }, []);

  const formatDate = useCallback(
    (date: Date, locale: string = "en") =>
      calendar === "ethiopian"
        ? formatEth(date, locale)
        : formatGreg(date, locale),
    [calendar, formatEth, formatGreg],
  );

  const formatDateDual = useCallback(
    (date: Date, locale: string = "en") => {
      const ethStr = formatEth(date, locale);
      const gregStr = formatGreg(date, locale);
      return calendar === "ethiopian"
        ? { primary: ethStr, secondary: gregStr }
        : { primary: gregStr, secondary: ethStr };
    },
    [calendar, formatEth, formatGreg],
  );

  const value = useMemo<CalendarContextValue>(
    () => ({ calendar, setCalendar, toggle, formatDate, formatDateDual }),
    [calendar, setCalendar, toggle, formatDate, formatDateDual],
  );

  return (
    <CalendarContext.Provider value={value}>
      {children}
    </CalendarContext.Provider>
  );
}

export function useCalendar(): CalendarContextValue {
  const ctx = useContext(CalendarContext);
  if (!ctx) {
    throw new Error("useCalendar must be used within a CalendarProvider");
  }
  return ctx;
}
