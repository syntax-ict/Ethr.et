"use client";

import { useEffect } from "react";
import { useCurrentUser } from "@/features/auth/api";
import { toCalendarSystem, useCalendar } from "@/lib/calendar/calendar-context";

/**
 * Applies the signed-in user's calendar to the display (audit N33).
 *
 * `/auth/me` returns the calendar in force — the user's own choice, else their
 * organisation's default — and nothing read it: the profile choice was stored
 * and never applied, and every browser showed whatever it last had in
 * `localStorage`. This is the one place it is applied, on sign-in and whenever
 * `/auth/me` is refetched (both the header toggle and the profile select
 * invalidate it after saving).
 *
 * It applies on a *change* of the server value only, so a choice the user just
 * made locally is not overwritten by a stale read, and an offline session keeps
 * the cached `localStorage` value until the server can answer.
 *
 * Renders nothing. Mounted inside `CalendarProvider` in the authenticated shell
 * only — public pages make no `/auth/me` call.
 */
export function CalendarPreferenceSync() {
  const { data: user } = useCurrentUser();
  const { setCalendar } = useCalendar();
  const preferred = user?.preferences?.calendar;

  useEffect(() => {
    if (preferred === undefined) return;
    setCalendar(toCalendarSystem(preferred));
  }, [preferred, setCalendar]);

  return null;
}
