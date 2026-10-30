"use client";

import { useEffect } from "react";
import { useCurrentUser } from "@/features/auth/api";
import { getLocale, setLocale } from "@/lib/i18n/translations";

/**
 * Applies the language the signed-in user chose (review 2026-10-30, D1).
 *
 * The header and Preferences store the choice on the user, and nothing read it
 * back: a user who chose English signed in on a new phone to their
 * organisation's default. Only a language the user saved is applied
 * (`locale_chosen`); `users.locale` defaults to "en" and several sign-up paths
 * write "en", so applying it unasked would turn Amharic organisations English.
 *
 * Like `CalendarPreferenceSync`, it applies on a *change* of the server value
 * only, so a choice just made in this browser is not overwritten by a stale
 * read. A language already in force is left alone, so the app does not
 * re-render for nothing; `setLocale` itself refuses one that is not available.
 *
 * Renders nothing. Mounted in the authenticated shell only.
 */
export function LocalePreferenceSync() {
  const { data: user } = useCurrentUser();
  const chosen = user?.preferences?.locale_chosen
    ? user.preferences.locale
    : undefined;

  useEffect(() => {
    if (chosen === undefined || chosen === getLocale()) return;
    setLocale(chosen);
  }, [chosen]);

  return null;
}
