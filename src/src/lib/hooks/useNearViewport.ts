"use client";

import { useEffect, useRef, useState } from "react";

/**
 * True once the element comes within `rootMargin` of the visible area, and
 * true from then on.
 *
 * For mounting something heavy only when the reader is about to see it: the
 * dashboard's charts pull in recharts, about 98 KB brotli, and sit below the
 * work queue, where many readers never scroll. The margin is generous so the
 * chunk is usually in hand before the chart scrolls into view.
 *
 * The dashboard scrolls inside its shell's `<main>`, not the window. Observed
 * against the viewport, the element is clipped by that container, and the
 * margin, which grows only the root, never applied: the chart was fetched
 * once it was already on screen. So the root is the nearest scrolling
 * ancestor, and the viewport only when there is none.
 *
 * Starts false on the server and on the first client render alike, so the
 * prerendered HTML and hydration agree. A browser without
 * IntersectionObserver gets the content straight after hydration.
 */
export function useNearViewport<T extends Element>(
  rootMargin = "600px",
): { ref: React.RefObject<T | null>; near: boolean } {
  const ref = useRef<T | null>(null);
  const [near, setNear] = useState(false);

  useEffect(() => {
    const element = ref.current;
    if (!element) return;
    if (typeof IntersectionObserver === "undefined") {
      const timer = setTimeout(() => setNear(true));
      return () => clearTimeout(timer);
    }

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          setNear(true);
          observer.disconnect();
        }
      },
      { root: scrollingAncestor(element), rootMargin },
    );
    observer.observe(element);

    return () => observer.disconnect();
  }, [rootMargin]);

  return { ref, near };
}

function scrollingAncestor(element: Element): Element | null {
  for (let node = element.parentElement; node; node = node.parentElement) {
    const { overflowY } = getComputedStyle(node);
    if (overflowY === "auto" || overflowY === "scroll") return node;
  }
  return null;
}
