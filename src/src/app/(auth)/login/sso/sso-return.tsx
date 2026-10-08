"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { AlertTriangle, Loader2, ShieldCheck } from "lucide-react";
import { apiClient } from "@/api/client";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";
import { useHydrated } from "@/lib/hooks/useHydrated";

const SLUG = /^[a-z0-9][a-z0-9-]{0,62}$/;

/** The error codes SsoController::callback() sends back. */
const KNOWN_ERRORS = [
  "failed",
  "no_account",
  "account_inactive",
  "tenant_inactive",
  "not_configured",
  "seat_limit",
] as const;

/** Only a path on this site; anything else is the dashboard. */
function safeNext(next: string | null): string {
  return next && /^\/(?![/\\])/.test(next) && !next.startsWith("/api")
    ? next
    : "/dashboard";
}

/**
 * Where the identity provider's sign-in lands (audit N60).
 *
 * The API's SAML callback used to answer the provider's form POST with JSON,
 * so a user who signed in through SSO was left on a raw JSON page. It now
 * redirects here with the organisation and either `next` (plus `mfa=1` while a
 * second factor is owed) or an `error` code. On the single production host the
 * organisation travels in `X-Tenant`, so it is stored before anything else.
 */
export function SsoReturn() {
  const router = useRouter();
  const { t } = useT();
  // The URL decides most outcomes, so they are derived during render once
  // hydrated (the static export has no query string to read on the server).
  // Only the session check below sets state, and it does so asynchronously.
  const hydrated = useHydrated();
  const [sessionRefused, setSessionRefused] = useState(false);

  const params = hydrated ? new URLSearchParams(window.location.search) : null;
  const org = (params?.get("org") ?? "").trim().toLowerCase();
  const urlError =
    params === null
      ? null
      : (params.get("error") ?? (SLUG.test(org) ? null : "failed"));
  const error = urlError ?? (sessionRefused ? "failed" : null);

  useEffect(() => {
    if (!hydrated || urlError !== null) return;

    const params = new URLSearchParams(window.location.search);
    const org = (params.get("org") ?? "").trim().toLowerCase();
    localStorage.setItem("tenant", org);

    if (params.get("mfa") === "1") {
      sessionStorage.setItem("mfa_pending", "true");
      router.replace("/login/mfa");
      return;
    }

    // Confirm the session belongs to that organisation before going on. A
    // link that names another one is refused by the API (the signed-in user
    // is not its member), and must not leave this browser sending it.
    apiClient
      .get("/auth/me")
      .then(() => router.replace(safeNext(params.get("next"))))
      .catch(() => {
        localStorage.removeItem("tenant");
        setSessionRefused(true);
      });
  }, [hydrated, urlError, router]);

  if (error === null) {
    return (
      <div className="w-full max-w-sm text-center">
        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10">
          <ShieldCheck className="h-6 w-6 text-primary" />
        </div>
        <p
          role="status"
          className="mt-4 flex items-center justify-center gap-2 text-sm text-muted-foreground"
        >
          <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
          {t("auth.sso_return.signing_in", "Signing you in…")}
        </p>
      </div>
    );
  }

  // Literal keys, so the i18n gate can see every one of them.
  const messages: Record<(typeof KNOWN_ERRORS)[number], string> = {
    failed: t(
      "auth.sso_return.failed",
      "Your organisation's sign-in service did not confirm who you are. Please try again.",
    ),
    no_account: t(
      "auth.sso_return.no_account",
      "There is no ETHR account for you in this organisation. Ask your administrator to invite you.",
    ),
    account_inactive: t(
      "auth.sso_return.account_inactive",
      "Your account is not active. Contact your administrator.",
    ),
    tenant_inactive: t(
      "auth.sso_return.tenant_inactive",
      "This organisation's account is not active.",
    ),
    not_configured: t(
      "auth.sso_return.not_configured",
      "Single sign-on is not set up for this organisation. Sign in with your password instead.",
    ),
    seat_limit: t(
      "auth.sso_return.seat_limit",
      "Your organisation has reached its plan's employee limit, so a new account could not be created for you. Contact your administrator.",
    ),
  };
  const message = (KNOWN_ERRORS as readonly string[]).includes(error)
    ? messages[error as (typeof KNOWN_ERRORS)[number]]
    : messages.failed;

  return (
    <div className="w-full max-w-sm text-center">
      <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-destructive/10">
        <AlertTriangle className="h-6 w-6 text-destructive" />
      </div>
      <h1 className="mt-4 text-xl font-semibold text-foreground">
        {t("auth.sso_return.title", "Single sign-on did not complete")}
      </h1>
      <p role="alert" className="mt-2 text-sm text-muted-foreground">
        {message}
      </p>
      <Button asChild className="mt-6">
        <Link href="/login">
          {t("auth.sso_return.back", "Back to sign in")}
        </Link>
      </Button>
    </div>
  );
}
