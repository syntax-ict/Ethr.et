/**
 * Drop every API response the service worker has stored on this device.
 *
 * `public/sw.js` keeps successful API GETs so the app can answer from them
 * offline. Those responses belong to whoever was signed in, so they must not
 * outlive the session: until 2026-10-12 nothing removed them, and the platform
 * console's audit log, tenants and platform settings stayed in Cache Storage
 * after sign-out (console QA F3). Only the API caches go — the static shell
 * stays, so the app still opens offline.
 *
 * Never throws: Cache Storage is missing in some contexts (insecure origins,
 * older browsers), and signing out must not depend on it.
 */
export async function clearApiCache(): Promise<void> {
  try {
    if (typeof caches === "undefined") return;

    const keys = await caches.keys();
    await Promise.all(
      keys
        .filter((key) => key.startsWith("ethr-") && key.endsWith("-api"))
        .map((key) => caches.delete(key)),
    );
  } catch {
    // Nothing to clear, or the browser refused; the session still ends.
  }
}
