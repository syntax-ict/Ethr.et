"use client";

import { useState } from "react";
import { AlertTriangle, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";

function readImpersonating(): boolean {
  if (typeof window === "undefined") return false;
  try {
    return localStorage.getItem("impersonating") === "true";
  } catch {
    return false;
  }
}

export function ImpersonationBanner() {
  const { t } = useT();
  const [isImpersonating] = useState(readImpersonating);

  async function exitImpersonation() {
    // The server revokes the impersonation token and swaps the session cookie
    // back to a fresh one for the super admin, reporting which tenant that
    // session belongs to. If it could not restore them — an expired session, an
    // account that is no longer a super admin — there is no identity left to
    // return to and the only honest destination is the login screen.
    let restoredTenant: string | null = null;
    let restored = false;

    try {
      const { apiClient } = await import("@/api/client");
      const { data } = await apiClient.post("/admin/exit-impersonation");
      restored = data?.session_restored === true;
      restoredTenant = data?.tenant ?? null;
    } catch {
      restored = false;
    }

    // Prefer the server's answer over what this browser stashed on the way in.
    const originalTenant =
      restoredTenant ?? localStorage.getItem("original_tenant");
    if (originalTenant) {
      localStorage.setItem("tenant", originalTenant);
    }
    localStorage.removeItem("original_tenant");
    localStorage.removeItem("impersonating");

    window.location.href = restored ? "/admin" : "/login";
  }

  if (!isImpersonating) return null;

  return (
    <div className="fixed top-0 left-0 right-0 z-50 flex items-center justify-between bg-status-warning px-4 py-2 text-text-inverse">
      <div className="flex items-center gap-2">
        <AlertTriangle className="h-4 w-4 shrink-0" />
        {/* Translated, not a literal. This is the only thing on screen telling
            a super admin they are acting as somebody else, and a warning the
            reader cannot read is not a warning. */}
        <span className="text-sm font-medium">
          {t(
            "impersonation.banner",
            "You are impersonating a tenant admin. Actions taken are logged.",
          )}
        </span>
      </div>
      <Button
        size="sm"
        variant="outline"
        className="h-7 border-text-inverse/40 bg-background/20 text-text-inverse hover:bg-background/30 text-xs"
        onClick={exitImpersonation}
      >
        <X className="mr-1 h-3 w-3" />
        {t("impersonation.exit", "Exit Impersonation")}
      </Button>
    </div>
  );
}
