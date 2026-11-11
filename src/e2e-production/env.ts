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

/** Null when the session is usable, or the reason it is not. */
export function sessionProblem(role: Role): string | null {
  const file = sessionFile(role);
  let ageMs: number;
  try {
    ageMs = Date.now() - fs.statSync(file).mtimeMs;
  } catch {
    return `No saved session for ${role.toUpperCase()} at ${file}. Save one first (11, section 3).`;
  }
  if (ageMs > SESSION_IDLE_LIMIT_MS) {
    return `The saved ${role.toUpperCase()} session is ${Math.round(ageMs / 60000)} minutes old, past the 15-minute idle limit. Save it again (11, section 3).`;
  }
  return null;
}
