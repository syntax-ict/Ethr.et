"use client";

import { useEffect, useSyncExternalStore } from "react";

/**
 * A page's own title for the paper, where the document title is not enough.
 *
 * The print header names the page by its document title ("Employees"). A
 * record page is better named by the record: "Abebe Kebede · EMP-0042". The
 * page says so with `usePrintTitle(...)`, and the header (`PrintHeader`)
 * reads it. It is a tiny module-level store, not a context, because the
 * header and the page sit in different branches of the shell and neither
 * owns the other.
 */
let current: string | null = null;
const listeners = new Set<() => void>();

function set(next: string | null) {
  if (current === next) return;
  current = next;
  listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void) {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

/** Name this page on paper. Pass null/undefined until the record has loaded. */
export function usePrintTitle(title: string | null | undefined) {
  useEffect(() => {
    set(title || null);
    return () => set(null);
  }, [title]);
}

/** The title a page has set for the paper, or null. */
export function usePrintTitleOverride(): string | null {
  return useSyncExternalStore(
    subscribe,
    () => current,
    () => null,
  );
}
