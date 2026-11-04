"use client";

import { useSyncExternalStore } from "react";
import { Loader2 } from "lucide-react";

const LABEL_ID = "app-loading-label";

/**
 * Runs before hydration: the prerendered screen is Amharic for everyone, and
 * an English reader saw it on every cold load (dashboard review, 2026-11-03,
 * PR 10). The stored preference is in localStorage, so the label is swapped
 * there, from the labels the server put on the element. Nothing else is
 * touched; HtmlLangSync sets the document language again once React runs.
 */
const SWAP_SCRIPT = `(function(){try{var e=document.getElementById(${JSON.stringify(LABEL_ID)});var l=localStorage.getItem("locale");var m=JSON.parse(e.getAttribute("data-labels")||"{}");if(l&&m[l]){e.textContent=m[l];document.documentElement.lang=l;}}catch(_){}})();`;

const noop = () => () => {};
function storedLocale(): string | null {
  try {
    return localStorage.getItem("locale");
  } catch {
    return null;
  }
}

/**
 * The full-screen wait in front of the authenticated app.
 *
 * One component for two gates, because the prerendered dashboard HTML is this
 * screen and whichever gate is closed first in the browser must hydrate it
 * exactly: `DashboardShell` while the reader's dictionary loads, then
 * `AuthGuard` while `/auth/me` answers.
 *
 * `labels`, when given, is the same text in every available language. The
 * server renders `label`; the inline script swaps in the stored language
 * before hydration, and this component then reads the same preference so
 * React agrees with what is on screen.
 */
export function AppLoadingScreen({
  label,
  labels,
}: {
  label: string;
  labels?: Record<string, string>;
}) {
  const stored = useSyncExternalStore(noop, storedLocale, () => null);
  const text = (labels && stored && labels[stored]) || label;

  return (
    <div className="flex min-h-screen items-center justify-center bg-background">
      <div className="flex flex-col items-center gap-3">
        <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-primary">
          <span className="text-lg font-bold text-primary-foreground">E</span>
        </div>
        <Loader2 className="h-6 w-6 animate-spin text-primary" />
        {labels ? (
          <>
            <p
              id={LABEL_ID}
              data-labels={JSON.stringify(labels)}
              suppressHydrationWarning
              className="text-sm text-muted-foreground"
            >
              {text}
            </p>
            <script dangerouslySetInnerHTML={{ __html: SWAP_SCRIPT }} />
          </>
        ) : (
          <p className="text-sm text-muted-foreground">{label}</p>
        )}
      </div>
    </div>
  );
}
