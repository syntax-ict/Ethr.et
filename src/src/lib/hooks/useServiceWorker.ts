"use client";

import { useEffect } from "react";

export function useServiceWorker() {
  useEffect(() => {
    if (typeof window === "undefined" || !("serviceWorker" in navigator))
      return;

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
