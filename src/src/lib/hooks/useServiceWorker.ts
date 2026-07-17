"use client";

import { useEffect } from "react";

export function useServiceWorker() {
  useEffect(() => {
    if (typeof window === "undefined" || !("serviceWorker" in navigator))
      return;

    const isLocalDev =
      window.location.hostname === "localhost" ||
      window.location.hostname === "127.0.0.1" ||
      window.location.hostname === "0.0.0.0";

    if (isLocalDev) {
      // A stale registration from a previous prod-like run can still
      // intercept requests and serve the cached offline page.
      navigator.serviceWorker.getRegistrations().then((registrations) => {
        registrations.forEach((registration) => registration.unregister());
      });
      return;
    }

    navigator.serviceWorker.register("/sw.js", { scope: "/" }).catch((err) => {
      console.warn("Service worker registration failed:", err);
    });

    // Listen for SW messages — relay SYNC_ATTENDANCE to the offline sync hook
    function onMessage(event: MessageEvent) {
      if (event.data?.type === "SYNC_ATTENDANCE") {
        window.dispatchEvent(new CustomEvent("ethr:sync-attendance"));
      }
    }

    navigator.serviceWorker.addEventListener("message", onMessage);
    return () =>
      navigator.serviceWorker.removeEventListener("message", onMessage);
  }, []);
}
