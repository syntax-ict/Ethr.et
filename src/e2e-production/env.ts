/**
 * Where the production run points, and whose saved sessions it may use.
 *
 * Every value comes from the environment and none of them is a secret: the
 * signed-in specs use sessions a person saved by hand (see the README in
 * `docs/production testing/11-CLAUDE-CODE-AND-PLAYWRIGHT.md`, section 3), so
 * no password, MFA code or token is ever read from here.
 */
import fs from "fs";
import path from "path";
import { fileURLToPath } from "url";

/** The public site. Organisation addresses are resolved from it. */
export const PROD_BASE = process.env.PROD_BASE_URL ?? "https://www.ethr.et";

/** The platform console's own host. */
export const PROD_PLATFORM =
  process.env.PROD_PLATFORM_URL ?? "https://admin.ethr.et";

/**
 * The QA organisation the signed-in specs read. Never a customer's: rule 1 of
 * the test plan allows QA Audit, Syntax and the `uat-` organisations only.
 */
export const QA_SLUG = process.env.QA_SLUG ?? "qa-audit";

const QA_SLUGS = /^(qa-audit|syntax|uat-[a-z0-9-]+)$/;
if (!QA_SLUGS.test(QA_SLUG)) {
  throw new Error(
    `QA_SLUG="${QA_SLUG}" is not a QA organisation. Production testing signs in ` +
      `only to qa-audit, syntax or a uat- organisation (test plan, rule 1).`,
  );
}

/** The commit under test, from the release notes; S-01 compares release.json to it. */
export const EXPECTED_COMMIT = process.env.EXPECTED_COMMIT?.toLowerCase();

/** S-05 sends refused requests, which the host's firewall counts; opt in (rule 10). */
export const PROBE_SECRETS = process.env.PROD_PROBE_SECRETS === "1";

/** The personas of test plan section 5 that have a saved session. */
export const ROLES = ["ta", "hr", "fin", "sup", "emp", "pa"] as const;
export type Role = (typeof ROLES)[number];

const AUTH_DIR = fileURLToPath(new URL("./.auth/", import.meta.url));

/**
 * A stored session outlives 15 idle minutes on neither the tenant nor the
 * console (`AuthService::SESSION_MINUTES`), so an older file is reported as
 * stale rather than loaded and allowed to fail as a sign-in defect.
 */
const SESSION_IDLE_LIMIT_MS = 15 * 60 * 1000;

export function sessionFile(role: Role): string {
  return path.join(AUTH_DIR, `${role}.json`);
}

/**
 * The cookie a signed-in browser carries (`App\Services\Auth\SessionCookie`):
 * set by sign-in, scoped to `/api`, absent from a window that never signed in.
 */
const SIGN_IN_COOKIE = "access_token";

type SignInCookie = { domain: string; expires: number };

/**
 * The sign-in cookies in a saved session: where they belong and when they
 * expire, never their values. `playwright open --save-storage` writes the file
 * when the browser process ends, Ctrl-C included, with whatever the window
 * holds: a window stopped before anyone signed in leaves a fresh file with no
 * sign-in in it, which the age check alone passes (2026-11-13).
 */
function signInCookies(file: string): SignInCookie[] | null {
  try {
    const state = JSON.parse(fs.readFileSync(file, "utf8")) as {
      cookies?: { name?: string; domain?: string; path?: string; expires?: number }[];
    };
    return (state.cookies ?? [])
      .filter((c) => c.name === SIGN_IN_COOKIE && (c.path ?? "").startsWith("/api"))
      .map((c) => ({ domain: c.domain ?? "", expires: c.expires ?? -1 }));
  } catch {
    return null;
  }
}

function cookieCovers(domain: string, host: string): boolean {
  const bare = domain.replace(/^\./, "").toLowerCase();
  const h = host.toLowerCase();
  return h === bare || (domain.startsWith(".") && h.endsWith(`.${bare}`));
}

/** Null when the session is usable, or the reason it is not. */
export function sessionProblem(role: Role): string | null {
  const file = sessionFile(role);
  const who = role.toUpperCase();
  let ageMs: number;
  try {
    ageMs = Date.now() - fs.statSync(file).mtimeMs;
  } catch {
    return `No saved session for ${who} at ${file}. Save one first (11, section 3).`;
  }
  if (ageMs > SESSION_IDLE_LIMIT_MS) {
    return `The saved ${who} session is ${Math.round(ageMs / 60000)} minutes old, past the 15-minute idle limit. Save it again (11, section 3).`;
  }
  const cookies = signInCookies(file);
  if (cookies === null) {
    return `The saved ${who} session at ${file} is not a Playwright storage file. Save it again (11, section 3).`;
  }
  if (cookies.length === 0) {
    return `The saved ${who} session holds no sign-in: the window was closed or stopped before anyone signed in. Save it again, and wait for the terminal to return to its prompt (11, section 3).`;
  }
  const nowSeconds = Date.now() / 1000;
  if (cookies.every((c) => c.expires > 0 && c.expires < nowSeconds)) {
    return `The saved ${who} session's sign-in has expired. Save it again (11, section 3).`;
  }
  return null;
}

/**
 * Null when the saved session signed in on `host`, or the reason it did not.
 * Asked once the organisation's address is known: a window saved on another
 * organisation, or on the apex, is signed in somewhere the run never goes.
 */
export function sessionHostProblem(role: Role, host: string): string | null {
  const cookies = signInCookies(sessionFile(role)) ?? [];
  if (cookies.some((c) => cookieCovers(c.domain, host))) return null;
  const where = [...new Set(cookies.map((c) => c.domain.replace(/^\./, "")))].join(", ");
  return `The saved ${role.toUpperCase()} session signed in on ${where || "no host"}, not ${host}. Save it again from ${host} (11, section 3).`;
}
