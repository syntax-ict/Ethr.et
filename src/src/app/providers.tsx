"use client";

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ThemeProvider } from "next-themes";
import { useEffect, useState, type ReactNode } from "react";
import { Toaster } from "sonner";
import { useServiceWorker } from "@/lib/hooks/useServiceWorker";
import { getLocale } from "@/lib/i18n/translations";

function ServiceWorkerRegistrar() {
  useServiceWorker();
  return null;
}

function HtmlLangSync() {
  useEffect(() => {
    const sync = () => {
      document.documentElement.lang = getLocale();
    };
    sync();
    window.addEventListener("locale-changed", sync);
    return () => window.removeEventListener("locale-changed", sync);
  }, []);
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
        disableTransitionOnChange
      >
        <ServiceWorkerRegistrar />
        <HtmlLangSync />
        {children}
        <Toaster richColors position="top-right" />
      </ThemeProvider>
    </QueryClientProvider>
  );
}
