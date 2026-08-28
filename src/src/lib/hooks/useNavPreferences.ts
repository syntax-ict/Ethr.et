"use client";

import { useState, useCallback } from "react";

const FAVORITES_KEY = "ethr.nav.favorites";
const RECENT_KEY = "ethr.nav.recent";
const MAX_RECENT = 8;
const MAX_FAVORITES = 5;
const RECENT_EXPANDED_KEY = "ethr.nav.recent.expanded";

interface RecentPage {
  href: string;
  ts: number;
}

function readFavorites(): string[] {
  if (typeof window === "undefined") return [];
  try {
    const sf = localStorage.getItem(FAVORITES_KEY);
    return sf ? JSON.parse(sf).slice(0, MAX_FAVORITES) : [];
  } catch {
    return [];
  }
}

function readRecent(): RecentPage[] {
  if (typeof window === "undefined") return [];
  try {
    const sr = localStorage.getItem(RECENT_KEY);
    return sr ? JSON.parse(sr) : [];
  } catch {
    return [];
  }
}

function readRecentExpanded(): boolean {
  if (typeof window === "undefined") return false;
  try {
    return localStorage.getItem(RECENT_EXPANDED_KEY) === "1";
  } catch {
    return false;
  }
}

export function useNavPreferences() {
  const [favorites, setFavorites] = useState<string[]>(readFavorites);
  const [recent, setRecent] = useState<RecentPage[]>(readRecent);
  const [recentExpanded, setRecentExpanded] = useState(readRecentExpanded);

  const toggleFavorite = useCallback((href: string) => {
    setFavorites((prev) => {
      let next: string[];
      if (prev.includes(href)) {
        next = prev.filter((h) => h !== href);
      } else if (prev.length >= MAX_FAVORITES) {
        return prev;
      } else {
        next = [...prev, href];
      }
      try {
        localStorage.setItem(FAVORITES_KEY, JSON.stringify(next));
      } catch {
        /* noop */
      }
      return next;
    });
  }, []);

  const toggleRecentExpanded = useCallback(() => {
    setRecentExpanded((prev) => {
      const next = !prev;
      try {
        localStorage.setItem(RECENT_EXPANDED_KEY, next ? "1" : "0");
      } catch {
        /* noop */
      }
      return next;
    });
  }, []);

  const favoritesAtLimit = favorites.length >= MAX_FAVORITES;

  const isFavorite = useCallback(
    (href: string) => favorites.includes(href),
    [favorites],
  );

  const trackVisit = useCallback((href: string) => {
    setRecent((prev) => {
      const filtered = prev.filter((r) => r.href !== href);
      const next = [{ href, ts: Date.now() }, ...filtered].slice(0, MAX_RECENT);
      try {
        localStorage.setItem(RECENT_KEY, JSON.stringify(next));
      } catch {
        /* noop */
      }
      return next;
    });
  }, []);

  return {
    favorites,
    recent,
    toggleFavorite,
    isFavorite,
    trackVisit,
    recentExpanded,
    toggleRecentExpanded,
    favoritesAtLimit,
  };
}
