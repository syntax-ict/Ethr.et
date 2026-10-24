"use client";

import { useEffect, useState } from "react";

/**
 * Count from zero up to a figure, once, over at most `duration` ms.
 *
 * The first render returns the final value, so the prerendered HTML and a
 * reader with scripts off see the real number. The count runs only after
 * hydration, only when motion is allowed, and never again for the same
 * figure. Under reduced motion the figure is simply shown.
 */
export function useCountUp(target: number, duration = 900): number {
  const [value, setValue] = useState(target);

  useEffect(() => {
    if (
      typeof window === "undefined" ||
      typeof window.requestAnimationFrame !== "function"
    ) {
      return;
    }
    if (
      typeof window.matchMedia === "function" &&
      window.matchMedia("(prefers-reduced-motion: reduce)").matches
    ) {
      return;
    }

    let frame = 0;
    const start = performance.now();
    const tick = (now: number) => {
      const progress = Math.min(1, (now - start) / duration);
      // Ease out: fast at first, settling on the figure.
      const eased = 1 - Math.pow(1 - progress, 3);
      setValue(Math.round(target * eased));
      if (progress < 1) frame = window.requestAnimationFrame(tick);
    };
    frame = window.requestAnimationFrame(tick);

    return () => window.cancelAnimationFrame(frame);
  }, [target, duration]);

  return value;
}
