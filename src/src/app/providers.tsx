"use client";

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ThemeProvider } from "next-themes";
import { useEffect, useState, type ReactNode } from "react";
import { Toaster } from "sonner";
import { TooltipProvider } from "@/components/ui/tooltip";
import { useServiceWorker } from "@/lib/hooks/useServiceWorker";
import { getLocale } from "@/lib/i18n/translations";
import { useRouteLocale } from "@/lib/i18n/route-locale";

function ServiceWorkerRegistrar() {
  useServiceWorker();
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
          {children}
          <Toaster richColors position="top-right" />
        </TooltipProvider>
      </ThemeProvider>
    </QueryClientProvider>
  );
}
