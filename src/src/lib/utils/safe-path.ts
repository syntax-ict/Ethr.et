/**
 * A same-origin path to navigate to, or the fallback.
 *
 * Checking the characters is not enough: the URL parser drops tabs and
 * newlines, so `/\t/evil.com` passed a "starts with one slash" test and then
 * resolved to `//evil.com`, off the site (security review 2026-10-30). This
 * resolves the value the way the browser will and keeps it only if it stays
 * on this origin. API paths are refused too: they are not pages.
 */
const BASE = "http://same-origin.invalid";

export function safeInternalPath(
  value: string | null | undefined,
  fallback: string,
): string;
export function safeInternalPath(
  value: string | null | undefined,
): string | undefined;
export function safeInternalPath(
  value: string | null | undefined,
  fallback?: string,
): string | undefined {
  if (typeof value !== "string" || !value.startsWith("/")) return fallback;
  let url: URL;
  try {
    url = new URL(value, BASE);
  } catch {
    return fallback;
  }
  if (url.origin !== BASE) return fallback;
  if (url.pathname === "/api" || url.pathname.startsWith("/api/")) {
    return fallback;
  }
  return url.pathname + url.search + url.hash;
}
