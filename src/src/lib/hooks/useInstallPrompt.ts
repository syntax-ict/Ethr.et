"use client";

import { useSyncExternalStore } from "react";

/**
 * Installing ETHR as an app.
 *
 * Chrome and Edge (Android, desktop) fire `beforeinstallprompt` once the page
 * qualifies; holding on to that event is the only way to offer an "Install"
 * button of our own. It fires early — often before React has mounted — so the
 * listener lives at module scope and is attached by `listenForInstallPrompt()`
 * from the providers, and components read the captured state.
 *
 * Safari on iPhone and iPad has no such event: an app is installed from the
 * Share menu. `isIos` lets the UI show those steps instead.
 */
interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: "accepted" | "dismissed" }>;
}

interface InstallState {
  /** A deferred browser prompt is available. */
  canPrompt: boolean;
  /** Running as an installed app already. */
  installed: boolean;
}

let deferred: BeforeInstallPromptEvent | null = null;
let state: InstallState = { canPrompt: false, installed: false };
const listeners = new Set<() => void>();
let attached = false;

function emit(next: Partial<InstallState>) {
  state = { ...state, ...next };
  listeners.forEach((l) => l());
}

function runningStandalone(): boolean {
  if (typeof window === "undefined") return false;
  return (
    window.matchMedia?.("(display-mode: standalone)").matches === true ||
    // Safari's own flag for a home-screen app.
    (navigator as Navigator & { standalone?: boolean }).standalone === true
  );
}

export function listenForInstallPrompt(): void {
  if (attached || typeof window === "undefined") return;
  attached = true;

  state = { ...state, installed: runningStandalone() };

  window.addEventListener("beforeinstallprompt", (event) => {
    // Keep the browser's mini-infobar from appearing on its own; the app
    // offers installation where it makes sense instead.
    event.preventDefault();
    deferred = event as BeforeInstallPromptEvent;
    emit({ canPrompt: true });
  });

  window.addEventListener("appinstalled", () => {
    deferred = null;
    emit({ canPrompt: false, installed: true });
  });
}

/** Shows the browser's install dialog. Resolves true when the user accepted. */
export async function promptInstall(): Promise<boolean> {
  if (!deferred) return false;
  const event = deferred;
  // A prompt can be shown once; the browser fires a new event later if the
  // user dismissed it.
  deferred = null;
  emit({ canPrompt: false });
  await event.prompt();
  const choice = await event.userChoice;
  return choice.outcome === "accepted";
}

function subscribe(listener: () => void) {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

const SERVER_STATE: InstallState = { canPrompt: false, installed: false };

export function isIosDevice(): boolean {
  if (typeof navigator === "undefined") return false;
  const ua = navigator.userAgent;
  // iPadOS reports itself as a Mac; a touch screen gives it away.
  return (
    /iPhone|iPad|iPod/.test(ua) ||
    (/Macintosh/.test(ua) && (navigator.maxTouchPoints ?? 0) > 1)
  );
}

export function useInstallPrompt(): InstallState & { isIos: boolean } {
  const snapshot = useSyncExternalStore(
    subscribe,
    () => state,
    () => SERVER_STATE,
  );

  return { ...snapshot, isIos: !snapshot.installed && isIosDevice() };
}
