"use client";

import { useSyncExternalStore } from "react";

/**
 * Whether the current machine uses Cmd (macOS) or Ctrl for shortcuts.
 *
 * The keyboard hints in the sidebar and header were hardcoded to `⌘K` and
 * `⌘B`. ETHR's users are Ethiopian organizations running overwhelmingly on
 * Windows, so the app was instructing them to press a key their keyboard does
 * not have. The handlers themselves accept either modifier — only the *hint*
 * was wrong, which is the worst version of the bug: the shortcut works, but the
 * label tells you it does not.
 *
 * Read through `useSyncExternalStore` with a server snapshot of `false`, so SSR
 * and the first client render agree (Ctrl is also the safer default to show if
 * detection ever fails).
 */
function subscribe(): () => void {
  // Platform cannot change during a session; nothing to subscribe to.
  return () => {};
}

function getSnapshot(): boolean {
  if (typeof navigator === "undefined") return false;

  // `userAgentData.platform` is the non-deprecated source where available;
  // `navigator.platform` remains the reliable fallback in Safari and Firefox.
  const uaPlatform = (
    navigator as Navigator & { userAgentData?: { platform?: string } }
  ).userAgentData?.platform;

  const platform = uaPlatform || navigator.platform || "";
  return /mac|iphone|ipad|ipod/i.test(platform);
}

function getServerSnapshot(): boolean {
  return false;
}

export function useIsMac(): boolean {
  return useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
}

/**
 * Renders a shortcut hint for the current platform, e.g. `⌘K` or `Ctrl+K`.
 * Pass the bare key ("K", "B").
 */
export function useShortcutLabel(key: string): string {
  const isMac = useIsMac();
  return isMac ? `⌘${key.toUpperCase()}` : `Ctrl+${key.toUpperCase()}`;
}
