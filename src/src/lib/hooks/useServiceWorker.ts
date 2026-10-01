"use client";

import { useEffect } from "react";

const LOOPBACK_HOSTS = ["localhost", "127.0.0.1", "0.0.0.0"];

/**
 * Whether this page should run the offline service worker.
 *
 * Never under `next dev`: its chunks are named by module, not by content, so a
 * cache-first worker keeps serving the first build of a file and hides every
 * edit after it. Only bare loopback hosts were excluded before, so tenant
 * subdomains such as demo.localhost — how local tenant work is done — kept a
 * worker that did exactly that. A production build on demo.localhost still
 * registers it, which the offline E2E specs depend on.
 */
export function shouldRegisterServiceWorker(
  hostname: string,
  nodeEnv: string | undefined,
): boolean {
  if (nodeEnv === "development") return false;
  return !LOOPBACK_HOSTS.includes(hostname);
}

export function useServiceWorker() {
  useEffect(() => {
    if (typeof window === "undefined" || !("serviceWorker" in navigator))
      return;

    if (
      !shouldRegisterServiceWorker(
        window.location.hostname,
        process.env.NODE_ENV,
      )
    ) {
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
