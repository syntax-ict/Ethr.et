"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";

import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";

/**
 * Tenant-host landing point for an impersonation handoff.
 *
 * The platform console mints the impersonation token on admin.ethr.et and sends
 * the browser here, on {tenant}.ethr.et, with a single-use nonce. This page
 * exchanges it for a host-only session cookie and then behaves like any other
 * signed-in arrival.
 *
 * The nonce arrives in the URL **fragment**, not the query string. Browsers do
 * not send fragments to servers, so it never reaches an access log or a Referer
 * header — the same rule that keeps credentials out of this application's URLs.
 * Reading it therefore has to happen client-side, which is why this page exists
 * at all rather than the redirect landing straight on /dashboard.
 */
export function ClaimSession() {
  const router = useRouter();
  const { t } = useT();
  const [failed, setFailed] = useState(false);

  // React 18 StrictMode mounts effects twice in development. The nonce is
  // single-use, so a second exchange would consume nothing and report failure
  // for a handoff that actually succeeded.
  const attempted = useRef(false);

  useEffect(() => {
    if (attempted.current) return;
    attempted.current = true;

    const nonce = new URLSearchParams(
      window.location.hash.replace(/^#/, ""),
    ).get("nonce");

    const claim = nonce
      ? (() => {
          // Drop the nonce from the address bar before doing anything else, so
          // it is not left in history or shoulder-surfable once it has been used.
          window.history.replaceState(null, "", window.location.pathname);
          return apiClient.post("/auth/session/claim", { nonce });
        })()
      : Promise.reject(new Error("missing nonce"));

    claim.then(() => router.replace("/dashboard")).catch(() => setFailed(true));
  }, [router]);

  return (
    <div className="mx-auto flex min-h-[50vh] max-w-md flex-col items-center justify-center gap-4 text-center">
      {failed ? (
        <>
          <h1 className="text-lg font-semibold text-foreground">
            {t("impersonation.handoff_failed_title", "Sign-in link expired")}
          </h1>
          <p className="text-sm text-muted-foreground">
            {t(
              "impersonation.handoff_failed_body",
              "This link has already been used or has expired. Start the impersonation again from the admin console.",
            )}
          </p>
        </>
      ) : (
        <p className="text-sm text-muted-foreground">
          {t("impersonation.handoff_pending", "Signing you in…")}
        </p>
      )}
    </div>
  );
}
