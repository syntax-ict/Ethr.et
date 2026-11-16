/**
 * Removes credential-shaped fields from the address of the page.
 *
 * The auth forms are submitted by script. A person who presses the button
 * before the page has hydrated used to get the browser's own submit instead, a
 * GET, which wrote every field into the address (password included) and from
 * there into history, access logs and Referer headers. The forms now POST and
 * stay disabled until hydrated; this clears an address that already carries
 * such fields (a bookmark, a shared link, history).
 *
 * Only these names go. `tenant`, `email`, `token`, `org`, `lang`, `error`,
 * `mfa` and `next` are read by the pages and stay. The values are never
 * returned or logged.
 */
const LEAKED = [
  "password",
  "password_confirmation",
  "current_password",
  "code",
  "identifier",
  "employee_code",
];

/** True when the address was rewritten. */
export function scrubLeakedFields(): boolean {
  if (typeof window === "undefined") return false;

  const params = new URLSearchParams(window.location.search);
  let found = false;
  for (const name of LEAKED) {
    if (params.has(name)) {
      params.delete(name);
      found = true;
    }
  }
  if (!found) return false;

  const search = params.toString();
  // The state must not be window.history.state. Once the app router has
  // hydrated that object carries __NA, and Next's patched replaceState then
  // returns early without applying the new URL to the router, so its
  // canonicalUrl keeps the leaked fields and writes them back. With null, Next
  // copies its own internals onto the entry and applies the URL.
  window.history.replaceState(
    null,
    "",
    window.location.pathname +
      (search ? `?${search}` : "") +
      window.location.hash,
  );
  return true;
}
