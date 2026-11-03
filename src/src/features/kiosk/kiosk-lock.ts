"use client";

import { useCallback, useEffect, useRef, useState } from "react";

/** Hold the organisation's name this long to reach the admin PIN screen. */
export const EXIT_HOLD_MS = 3000;

/**
 * Locked kiosk mode: what a web page can do to keep a shared terminal on the
 * punch screen.
 *
 * It cannot stop the home button, the app switcher or a swipe that leaves the
 * browser; only the device can (Android screen pinning, iPad Guided Access,
 * Windows assigned access), and the Kiosks page says how. What it does stop
 * is the browser itself getting in the way of the next person: the
 * long-press and right-click menus, pinch zoom, pull-to-refresh, dragging,
 * the back button, and the reload, print and save shortcuts.
 */
export function useKioskLock(active: boolean) {
  const [fullscreen, setFullscreen] = useState(false);
  // A browser that says no (an embedded frame, a policy) is not asked again:
  // insisting would leave the "tap to start" cover up for good.
  const [refused, setRefused] = useState(false);
  const supported =
    !refused &&
    typeof document !== "undefined" &&
    document.fullscreenEnabled === true &&
    typeof document.documentElement.requestFullscreen === "function";

  useEffect(() => {
    if (!active) return;
    const root = document.documentElement;
    const before = {
      overscroll: root.style.overscrollBehavior,
      touchAction: root.style.touchAction,
    };
    root.style.overscrollBehavior = "none";
    root.style.touchAction = "manipulation";

    const prevent = (e: Event) => e.preventDefault();
    const pinch = (e: TouchEvent) => {
      if (e.touches.length > 1) e.preventDefault();
    };
    const keys = (e: KeyboardEvent) => {
      const mod = e.ctrlKey || e.metaKey;
      const key = e.key.toLowerCase();
      if (
        e.key === "F5" ||
        (mod && ["r", "p", "s", "o", "u", "+", "-", "=", "0"].includes(key)) ||
        (e.altKey && (e.key === "ArrowLeft" || e.key === "ArrowRight"))
      ) {
        e.preventDefault();
      }
    };
    // The back button: a step of history to swallow, put back each time.
    const back = () => window.history.pushState(null, "", window.location.href);
    window.history.pushState(null, "", window.location.href);

    const onFullscreen = () =>
      setFullscreen(document.fullscreenElement !== null);
    onFullscreen();

    document.addEventListener("contextmenu", prevent);
    document.addEventListener("dragstart", prevent);
    document.addEventListener("gesturestart", prevent);
    document.addEventListener("touchmove", pinch, { passive: false });
    document.addEventListener("keydown", keys);
    document.addEventListener("fullscreenchange", onFullscreen);
    window.addEventListener("popstate", back);

    return () => {
      root.style.overscrollBehavior = before.overscroll;
      root.style.touchAction = before.touchAction;
      document.removeEventListener("contextmenu", prevent);
      document.removeEventListener("dragstart", prevent);
      document.removeEventListener("gesturestart", prevent);
      document.removeEventListener("touchmove", pinch);
      document.removeEventListener("keydown", keys);
      document.removeEventListener("fullscreenchange", onFullscreen);
      window.removeEventListener("popstate", back);
    };
  }, [active]);

  // Browsers allow full screen only from a tap, so it is asked for on one.
  const enterFullscreen = useCallback(() => {
    if (!supported || document.fullscreenElement) return;
    document.documentElement.requestFullscreen().catch(() => setRefused(true));
  }, [supported]);

  return { fullscreen, fullscreenSupported: supported, enterFullscreen };
}

/**
 * Calls `onIdle` after `ms` without a touch, click or key, and again after
 * every later quiet stretch of the same length. Re-armed by any input.
 */
export function useIdle(ms: number, onIdle: () => void, enabled = true) {
  const callback = useRef(onIdle);
  useEffect(() => {
    callback.current = onIdle;
  }, [onIdle]);

  useEffect(() => {
    if (!enabled) return;
    let timer = window.setTimeout(() => callback.current(), ms);
    const reset = () => {
      window.clearTimeout(timer);
      timer = window.setTimeout(() => callback.current(), ms);
    };
    const events = ["pointerdown", "keydown", "touchstart"] as const;
    events.forEach((e) => document.addEventListener(e, reset, true));
    return () => {
      window.clearTimeout(timer);
      events.forEach((e) => document.removeEventListener(e, reset, true));
    };
  }, [ms, enabled]);
}

/**
 * Press and hold for `ms` to call `onHold`. Returns the handlers for the
 * element, and how far through the hold it is (0 to 1) for a progress bar.
 * A tap, or sliding off, does nothing, which is the point: the way out of
 * kiosk mode is not a button anyone can poke.
 */
export function useHold(ms: number, onHold: () => void) {
  const [progress, setProgress] = useState(0);
  const timer = useRef<number | null>(null);
  const started = useRef(0);
  const frame = useRef<number | null>(null);

  const cancel = useCallback(() => {
    if (timer.current !== null) window.clearTimeout(timer.current);
    if (frame.current !== null) window.cancelAnimationFrame(frame.current);
    timer.current = null;
    frame.current = null;
    setProgress(0);
  }, []);

  const start = useCallback(() => {
    cancel();
    started.current = Date.now();
    const tick = () => {
      setProgress(Math.min(1, (Date.now() - started.current) / ms));
      frame.current = window.requestAnimationFrame(tick);
    };
    frame.current = window.requestAnimationFrame(tick);
    timer.current = window.setTimeout(() => {
      cancel();
      onHold();
    }, ms);
  }, [cancel, ms, onHold]);

  useEffect(() => cancel, [cancel]);

  return {
    progress,
    handlers: {
      onPointerDown: start,
      onPointerUp: cancel,
      onPointerLeave: cancel,
      onPointerCancel: cancel,
    },
  };
}
