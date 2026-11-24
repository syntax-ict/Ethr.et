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

/**
 * The files under /_next/static this page has loaded, as paths.
 *
 * On a first visit they arrived before the worker controlled the page, so the
 * worker never saw them. Most are named by the shell pages it stores anyway;
 * one loaded on demand is not, which is how a first visit in Afaan Oromoo got
 * an English offline page: that dictionary is a chunk of its own. The page
 * sends this list once the worker takes control and the worker keeps what it
 * lacks (`KEEP_ASSETS` in public/sw.js).
 */
export function loadedStaticAssets(
  entries: readonly { name: string }[],
  origin: string,
): string[] {
  const paths = new Set<string>();
  for (const entry of entries) {
    let url: URL;
    try {
      url = new URL(entry.name, origin);
    } catch {
      continue;
    }
    if (url.origin !== origin) continue;
    if (!url.pathname.startsWith("/_next/static/")) continue;
    paths.add(url.pathname);
  }
  return [...paths];
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

    // A page that started with no worker in charge tells the worker, the
    // moment it takes over, what had already been loaded without it.
    const startedUncontrolled = navigator.serviceWorker.controller === null;
    function onControllerChange() {
      if (!startedUncontrolled) return;
      try {
        navigator.serviceWorker.controller?.postMessage({
          type: "KEEP_ASSETS",
          urls: loadedStaticAssets(
            performance.getEntriesByType("resource"),
            window.location.origin,
          ),
        });
      } catch {
        // The worker keeps these as they are next requested instead.
      }
    }

    navigator.serviceWorker.addEventListener("message", onMessage);
    navigator.serviceWorker.addEventListener(
      "controllerchange",
      onControllerChange,
    );
    return () => {
      navigator.serviceWorker.removeEventListener("message", onMessage);
      navigator.serviceWorker.removeEventListener(
        "controllerchange",
        onControllerChange,
      );
    };
  }, []);
}

/**
 * Asks the browser to wake the service worker when connectivity returns, so
 * punches queued offline are sent even if ETHR is not open then. The worker
 * listened for `sync-attendance` but nothing ever registered it, so a queued
 * punch waited until the app was next opened. Where Background Sync is not
 * supported (Safari, Firefox) the app's own reconnect listener still sends
 * the queue the next time it is open.
 */
export async function requestAttendanceSync(): Promise<boolean> {
  if (typeof navigator === "undefined" || !("serviceWorker" in navigator)) {
    return false;
  }
  try {
    // getRegistration(), not `ready`: `ready` never settles when no worker
    // is registered (development, a blocked registration).
    const registration = await navigator.serviceWorker.getRegistration();
    if (!registration) return false;
    const sync = (
      registration as ServiceWorkerRegistration & {
        sync?: { register: (tag: string) => Promise<void> };
      }
    ).sync;
    if (!sync) return false;
    await sync.register("sync-attendance");
    return true;
  } catch {
    return false;
  }
}
