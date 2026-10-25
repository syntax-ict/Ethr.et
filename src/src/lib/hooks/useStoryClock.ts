"use client";

import { useCallback, useEffect, useRef, useState } from "react";

/**
 * The clock behind a looping illustration: a step number that advances every
 * `stepMs` and wraps after `steps`, so a scene can be written as "what is on
 * screen at step N" and let CSS transitions do the moving.
 *
 * The order of operations follows `useRevealOnce`. The clock starts STILL on
 * its last step, which is what the prerendered HTML carries: a crawler, a
 * reader with scripts off and a reader who prefers reduced motion all get
 * the finished frame and never a half-played one. Only after hydration, only
 * when IntersectionObserver exists and motion is allowed, does it start, and
 * only while the illustration is on screen; it stops again when it scrolls
 * away, so six of them on one page cost nothing out of view.
 *
 * `toggle` is the pause control WCAG 2.2.2 asks of anything that moves for
 * more than five seconds beside other content. A paused clock stays paused
 * when it scrolls back into view.
 */
export function useStoryClock<T extends HTMLElement>(
  steps: number,
  stepMs: number,
): {
  ref: React.RefObject<T | null>;
  step: number;
  cycle: number;
  /** Motion is allowed and the clock has started at least once. */
  moving: boolean;
  /** The clock is advancing right now. */
  playing: boolean;
  paused: boolean;
  toggle: () => void;
} {
  const ref = useRef<T | null>(null);
  const [tick, setTick] = useState(steps - 1);
  const [moving, setMoving] = useState(false);
  const [playing, setPlaying] = useState(false);
  const [paused, setPaused] = useState(false);
  const pausedRef = useRef(false);
  const controls = useRef<{ start: () => void; stop: () => void } | null>(null);

  useEffect(() => {
    const element = ref.current;
    if (!element || typeof IntersectionObserver === "undefined") return;
    const reduce =
      typeof window.matchMedia === "function"
        ? window.matchMedia("(prefers-reduced-motion: reduce)")
        : null;
    if (reduce?.matches) return;

    let timer: ReturnType<typeof setInterval> | null = null;
    let visible = false;
    let started = false;

    const start = () => {
      if (timer !== null || pausedRef.current || !visible) return;
      if (!started) {
        started = true;
        setMoving(true);
        // Step 0 of cycle 1: the first time it is seen, the story starts
        // from its beginning rather than from the settled frame.
        setTick(steps);
      }
      setPlaying(true);
      timer = setInterval(() => setTick((value) => value + 1), stepMs);
    };
    const stop = () => {
      if (timer !== null) clearInterval(timer);
      timer = null;
      setPlaying(false);
    };
    controls.current = { start, stop };

    const observer = new IntersectionObserver(
      (entries) => {
        visible = entries.some((entry) => entry.isIntersecting);
        if (visible) start();
        else stop();
      },
      { rootMargin: "0px 0px -10% 0px" },
    );
    observer.observe(element);

    // A reader who turns reduced motion on while reading gets the settled
    // frame back, and the clock does not restart behind their back.
    const onReduceChange = (event: MediaQueryListEvent) => {
      if (!event.matches) return;
      stop();
      pausedRef.current = true;
      setMoving(false);
      setTick((value) => value - (value % steps) + steps - 1);
    };
    reduce?.addEventListener?.("change", onReduceChange);

    return () => {
      observer.disconnect();
      reduce?.removeEventListener?.("change", onReduceChange);
      if (timer !== null) clearInterval(timer);
      controls.current = null;
    };
  }, [steps, stepMs]);

  const toggle = useCallback(() => {
    const next = !pausedRef.current;
    pausedRef.current = next;
    setPaused(next);
    if (next) controls.current?.stop();
    else controls.current?.start();
  }, []);

  return {
    ref,
    step: tick % steps,
    cycle: Math.floor(tick / steps),
    moving,
    playing,
    paused,
    toggle,
  };
}
