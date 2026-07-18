import { useEffect } from "react";

/**
 * Warns the user before they close the tab, refresh, or navigate away via a
 * browser-level action (not a Next.js client-side route change — the App
 * Router has no supported hook for intercepting those) while a page form or
 * wizard has unsaved changes. Per CLAUDE.md's Form Pattern Library rules.
 */
export function useUnsavedChangesWarning(hasUnsavedChanges: boolean) {
  useEffect(() => {
    if (!hasUnsavedChanges) return;

    function handleBeforeUnload(e: BeforeUnloadEvent) {
      e.preventDefault();
      e.returnValue = "";
    }

    window.addEventListener("beforeunload", handleBeforeUnload);
    return () => window.removeEventListener("beforeunload", handleBeforeUnload);
  }, [hasUnsavedChanges]);
}
