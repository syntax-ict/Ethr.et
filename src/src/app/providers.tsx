"use client";

import {
  QueryClient,
  QueryClientProvider,
  useQueryClient,
} from "@tanstack/react-query";
import { ThemeProvider } from "next-themes";
import { useEffect, useState, type ReactNode } from "react";
import { AppToaster } from "@/components/shared/app-toaster";
import { TooltipProvider } from "@/components/ui/tooltip";
import { useServiceWorker } from "@/lib/hooks/useServiceWorker";
import { listenForInstallPrompt } from "@/lib/hooks/useInstallPrompt";
import { getLocale } from "@/lib/i18n/translations";
import { useRouteLocale } from "@/lib/i18n/route-locale";

function ServiceWorkerRegistrar() {
  useServiceWorker();
  // Attached here, beside the worker: the browser's install event can fire
  // before any component that offers installation has mounted.
  useEffect(() => {
    listenForInstallPrompt();
  }, []);
  return null;
}

/**
 * Keeps `<html lang>` honest on the routes that cannot know the locale up front.
 *
 * Inside `/am/*` and `/en/*` the server already emitted the right value, and
 * the stored preference must not override it: a reader whose localStorage says
 * `en` following a link to `/am/pricing` gets Amharic text, so the attribute
 * has to say `am`. Hence the early return — on those routes the URL wins, and
 * the language switcher navigates rather than mutating the current page.
 */
function HtmlLangSync() {
  const routeLocale = useRouteLocale();

  useEffect(() => {
    if (routeLocale) {
      document.documentElement.lang = routeLocale;
      return;
    }

    const sync = () => {
      document.documentElement.lang = getLocale();
    };
    sync();
    window.addEventListener("locale-changed", sync);
    return () => window.removeEventListener("locale-changed", sync);
  }, [routeLocale]);

  return null;
}

/**
 * Refetches what the server wrote when the reader changes language.
 *
 * The switcher re-renders the app in place, which translates every key the
 * client owns but leaves cached responses alone, and some of those carry text
 * the server composed in the old language (approval summaries with their month
 * names, notification titles). They stayed English on the Amharic screen until
 * the query went stale (production QA 2026-10-29). Every request sends
 * `Accept-Language` from the stored locale, which `setLocale` writes before it
 * fires the event, so the refetch arrives in the new language.
 */
function ServerTextLocaleSync() {
  const queryClient = useQueryClient();

  useEffect(() => {
    const refetch = () => {
      void queryClient.invalidateQueries();
    };
    window.addEventListener("locale-changed", refetch);
    return () => window.removeEventListener("locale-changed", refetch);
  }, [queryClient]);

  return null;
}

export function Providers({ children }: { children: ReactNode }) {
  const [queryClient] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            staleTime: 60 * 1000,
            retry: 1,
            refetchOnWindowFocus: false,
          },
        },
      }),
  );

  return (
    <QueryClientProvider client={queryClient}>
      <ThemeProvider
        attribute="class"
        defaultTheme="system"
        enableSystem
        themes={["light", "dark", "high-contrast"]}
        disableTransitionOnChange
      >
        {/* Mounted once app-wide so call sites need only Tooltip/Trigger/Content.
            `delayDuration` is deliberately short: these are dense enterprise
            screens where hints sit on icon-only controls and a 700ms default
            reads as unresponsive. */}
        <TooltipProvider delayDuration={250} skipDelayDuration={300}>
          <ServiceWorkerRegistrar />
          <HtmlLangSync />
          <ServerTextLocaleSync />
          {children}
          <AppToaster />
        </TooltipProvider>
      </ThemeProvider>
    </QueryClientProvider>
  );
}
