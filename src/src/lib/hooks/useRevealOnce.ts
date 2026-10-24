"use client";

import { useEffect, useRef, useState } from "react";

export type RevealState = "settled" | "hidden" | "revealing";

/**
 * Reveal an element once, the first time it scrolls into view.
 *
 * The order of operations is the whole design. The element starts SETTLED,
 * which is what the prerendered HTML carries: a crawler, a reader with
 * scripts off, and a reader who prefers reduced motion all get the finished
 * page with nothing hidden. Only after hydration, and only when
 * IntersectionObserver exists and motion is allowed, is the element
 * observed; the observer's first report says whether it is on screen. On
 * screen already: it stays settled and is never hidden. Below the fold:
 * it becomes HIDDEN, out of sight, and plays its entrance once when it
 * enters the viewport.
 *
 * `globals.css` zeroes `animation-delay` under reduced motion and the global
 * block collapses every duration, so the `revealing` keyframes, which end in
 * the settled state, arrive complete and at once if they ever run there at
 * all. The meaning always lives in the text, never in the motion.
 */
export function useRevealOnce<T extends HTMLElement>(): {
  ref: React.RefObject<T | null>;
  state: RevealState;
} {
  const ref = useRef<T | null>(null);
  const hidden = useRef(false);
  const [state, setState] = useState<RevealState>("settled");

  useEffect(() => {
    const element = ref.current;
    if (!element || typeof IntersectionObserver === "undefined") return;
    if (
      typeof window.matchMedia === "function" &&
      window.matchMedia("(prefers-reduced-motion: reduce)").matches
    ) {
      return;
    }

    const observer = new IntersectionObserver(
      (entries) => {
        const visible = entries.some((entry) => entry.isIntersecting);
        if (visible) {
          if (hidden.current) setState("revealing");
          observer.disconnect();
        } else if (!hidden.current) {
          hidden.current = true;
          setState("hidden");
        }
      },
      { rootMargin: "0px 0px -10% 0px" },
    );
    observer.observe(element);

    return () => observer.disconnect();
  }, []);

  return { ref, state };
}

/** The class a revealed element carries in each state. */
export function revealClass(state: RevealState): string {
  if (state === "hidden") return "ethr-reveal-hidden";
  if (state === "revealing") return "ethr-reveal-in";
  return "";
}
