import { useEffect } from "react";

/**
 * Where a click on a plain link would take this app to another page, as a path
 * the router can be given; null for anything else.
 */
function destinationOf(e: MouseEvent): string | null {
  if (e.defaultPrevented || e.button !== 0) return null;
  if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return null;
  const anchor =
    e.target instanceof Element ? e.target.closest("a[href]") : null;
  if (!(anchor instanceof HTMLAnchorElement)) return null;
  if (anchor.target && anchor.target !== "_self") return null;
  if (anchor.hasAttribute("download")) return null;
  const next = new URL(anchor.href, window.location.href);
  // Another site, or a mailto: or tel: link, is the browser's business, and
  // `beforeunload` already covers a real unload.
  if (next.origin !== window.location.origin) return null;
  // The same page, or a jump to a place on it, keeps the form where it is.
  if (
    next.pathname === window.location.pathname &&
    next.search === window.location.search
  ) {
    return null;
  }
  return next.pathname + next.search + next.hash;
}

/**
 * Warns the user before they close the tab, refresh, or navigate away while a
 * page form or wizard has unsaved changes. Per CLAUDE.md's Form Pattern
 * Library rules.
 *
 * `beforeunload` alone sees only the browser-level exits. The App Router has
 * no supported hook for a client-side route change, so a page that passes
 * `onLeave` also has a click on a link to another page of the app stopped and
 * handed to it (the Cancel link, the sidebar). The page asks the person, with
 * its own translated dialog, and calls `router.push(href)` if they go. A route
 * change made in code, `router.push` after a save, is never a link click and
 * is not stopped. The browser's Back button is not covered.
 */
export function useUnsavedChangesWarning(
  hasUnsavedChanges: boolean,
  onLeave?: (href: string) => void,
) {
  useEffect(() => {
    if (!hasUnsavedChanges) return;

    function handleBeforeUnload(e: BeforeUnloadEvent) {
      e.preventDefault();
      e.returnValue = "";
    }

    // Capture phase on the document, so it runs before the router's own
    // handler on the link and can stop it.
    function handleLinkClick(e: MouseEvent) {
      const href = destinationOf(e);
      if (href === null || !onLeave) return;
      e.preventDefault();
      e.stopPropagation();
      onLeave(href);
    }

    window.addEventListener("beforeunload", handleBeforeUnload);
    if (onLeave) {
      document.addEventListener("click", handleLinkClick, true);
    }
    return () => {
      window.removeEventListener("beforeunload", handleBeforeUnload);
      document.removeEventListener("click", handleLinkClick, true);
    };
  }, [hasUnsavedChanges, onLeave]);
}
