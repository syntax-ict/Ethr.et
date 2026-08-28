import { redirect } from "next/navigation";

/**
 * The v1 wizard this route used to render is superseded by the v2 guided
 * onboarding flow (industry scoring, migration workspace, readiness, go-live)
 * — see docs/ETHR_AUDIT_2026-08-03.md punch list item 7. v1 had unfinished
 * steps (e.g. "File upload coming soon") and every entry point in the app
 * still pointed here instead of `/setup/guided`, so new tenants were being
 * funneled into the worse flow. Redirecting keeps this URL from breaking any
 * saved links or in-flight sessions.
 */
export default function SetupPage() {
  redirect("/setup/guided");
}
