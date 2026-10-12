/**
 * Carrying a sign-in across the 409 `canonical-address` hand-off.
 *
 * An organisation signs in at its own address. Signing in on the apex is
 * answered 409 before the password is checked, and the form moves the browser
 * there. It used to land on a blank form in the app's default language with no
 * word of why — the owner, during production QA (2026-10-11, P6), reasonably
 * took that for a completed sign-in.
 *
 * The email and the language travel in the URL *fragment*, which the browser
 * never sends to a server, so the address stays out of access logs and
 * Referer headers. The password never travels. The login page reads the
 * fragment once and removes it from the address bar.
 */

const LANG = /^[a-z]{2,3}$/;

export interface CanonicalHandoff {
  email: string;
  lang: string | null;
}

export function canonicalHandoffUrl(
  canonicalUrl: string,
  email: string,
  lang: string,
): string {
  const url = new URL(canonicalUrl);
  const fragment = new URLSearchParams({ handoff: "1", email });
  if (LANG.test(lang)) fragment.set("lang", lang);
  url.hash = fragment.toString();
  return url.toString();
}

/** The hand-off this page was opened with, if any; clears it from the URL. */
export function takeCanonicalHandoff(): CanonicalHandoff | null {
  if (typeof window === "undefined" || window.location.hash.length < 2) {
    return null;
  }

  const fragment = new URLSearchParams(window.location.hash.slice(1));
  if (fragment.get("handoff") !== "1") return null;

  window.history.replaceState(
    window.history.state,
    "",
    window.location.pathname + window.location.search,
  );

  const email = (fragment.get("email") ?? "").trim();
  const lang = fragment.get("lang");

  return {
    // Shown in a field the person can edit, but still only something
    // email-shaped is accepted from a URL.
    email: /^[^\s@]+@[^\s@]+$/.test(email) ? email : "",
    lang: lang && LANG.test(lang) ? lang : null,
  };
}
