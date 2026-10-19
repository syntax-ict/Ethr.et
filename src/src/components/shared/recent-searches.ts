"use client";

import { useCallback, useMemo, useSyncExternalStore } from "react";
import {
  Building2,
  CalendarDays,
  Clock,
  Users,
  type LucideIcon,
} from "lucide-react";

/**
 * The records a person last opened from search, shown when the search box is
 * empty. Kept in this browser only, per signed-in user, and never sent
 * anywhere: it is a convenience, and an empty list is a fine answer when
 * storage is blocked or cleared.
 */
export type RecentKind = "employee" | "attendance" | "leave" | "department";

export interface RecentEntry {
  kind: RecentKind;
  id: string;
  title: string;
  subtitle?: string;
  href: string;
}

export const RECENT_KIND_ICON: Record<RecentKind, LucideIcon> = {
  employee: Users,
  attendance: Clock,
  leave: CalendarDays,
  department: Building2,
};

const LIMIT = 5;
const CHANGED = "ethr:recent-searches";

function keyFor(userId: string): string {
  return `ethr.search.recent.${userId}`;
}

function read(key: string | null): string {
  if (key === null) return "[]";
  try {
    return window.localStorage.getItem(key) ?? "[]";
  } catch {
    return "[]";
  }
}

function write(key: string, entries: RecentEntry[]): void {
  try {
    window.localStorage.setItem(key, JSON.stringify(entries));
  } catch {
    // Storage blocked or full: the list simply is not remembered.
  }
  window.dispatchEvent(new Event(CHANGED));
}

function parse(raw: string): RecentEntry[] {
  try {
    const value: unknown = JSON.parse(raw);
    if (!Array.isArray(value)) return [];
    return value
      .filter(
        (e): e is RecentEntry =>
          typeof e === "object" &&
          e !== null &&
          typeof e.id === "string" &&
          typeof e.title === "string" &&
          typeof e.href === "string" &&
          // Only in-app paths: a stored value must never navigate elsewhere.
          e.href.startsWith("/") &&
          !e.href.startsWith("//") &&
          e.kind in RECENT_KIND_ICON,
      )
      .slice(0, LIMIT);
  } catch {
    return [];
  }
}

function subscribe(onChange: () => void): () => void {
  window.addEventListener(CHANGED, onChange);
  window.addEventListener("storage", onChange);
  return () => {
    window.removeEventListener(CHANGED, onChange);
    window.removeEventListener("storage", onChange);
  };
}

export function useRecentSearches(userId: string | null) {
  const key = userId ? keyFor(userId) : null;
  const raw = useSyncExternalStore(
    subscribe,
    () => read(key),
    () => "[]",
  );
  const items = useMemo(() => parse(raw), [raw]);

  const remember = useCallback(
    (entry: RecentEntry) => {
      if (key === null) return;
      const rest = parse(read(key)).filter(
        (e) => !(e.kind === entry.kind && e.id === entry.id),
      );
      write(key, [entry, ...rest].slice(0, LIMIT));
    },
    [key],
  );

  const clear = useCallback(() => {
    if (key !== null) write(key, []);
  }, [key]);

  return { items, remember, clear };
}
