"use client";

import { Laptop, Loader2, LogOut, ShieldCheck, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { EmptyState } from "@/components/shared/empty-state";
import {
  useRevokeAllSessions,
  useRevokeSession,
  useRevokeTrustedDevice,
  useSessions,
  useTrustedDevices,
} from "@/features/auth/sessions-api";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

function formatWhen(value: string | null, never: string): string {
  if (!value) return never;

  return new Date(value).toLocaleString();
}

/**
 * Active sessions and trusted devices (PHASE_00 S03).
 *
 * Both lists are security controls, so every state is rendered explicitly — a
 * silent failure here would leave a user believing they had signed a lost device
 * out when they had not.
 */
export function ActiveSessionsCard() {
  const { t } = useT();
  const { data, isLoading, isError, refetch } = useSessions();
  const revoke = useRevokeSession();
  const revokeAll = useRevokeAllSessions();

  const sessions = data?.data ?? [];
  const others = sessions.filter((s) => !s.is_current).length;

  function handleRevoke(id: number) {
    revoke.mutate(id, {
      onSuccess: () =>
        toast.success(
          t("security_page.session_revoked", "Session signed out."),
        ),
      onError: () =>
        toast.error(
          t(
            "security_page.session_revoke_failed",
            "Could not sign that session out.",
          ),
        ),
    });
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between gap-4 space-y-0">
        <CardTitle className="text-base">
          {t("security_page.active_sessions", "Active sessions")}
        </CardTitle>
        {others > 0 && (
          <Button
            variant="outline"
            size="sm"
            onClick={() =>
              revokeAll.mutate(undefined, {
                onSuccess: (r) =>
                  toast.success(
                    t(
                      "security_page.sessions_revoked",
                      "Other sessions signed out.",
                    ) + ` (${r.revoked})`,
                  ),
                onError: () =>
                  toast.error(
                    t(
                      "security_page.session_revoke_failed",
                      "Could not sign those sessions out.",
                    ),
                  ),
              })
            }
            disabled={revokeAll.isPending}
          >
            {revokeAll.isPending ? (
              <Loader2 className="mr-2 h-3 w-3 animate-spin" />
            ) : (
              <LogOut className="mr-2 h-3 w-3" />
            )}
            {t("security_page.sign_out_others", "Sign out all other sessions")}
          </Button>
        )}
      </CardHeader>
      <CardContent className="space-y-3">
        {isLoading ? (
          <>
            <Skeleton className="h-14 w-full" />
            <Skeleton className="h-14 w-full" />
          </>
        ) : isError ? (
          <div className="flex flex-col items-start gap-2 rounded-md border border-border-default p-4">
            <p className="text-sm text-muted-foreground">
              {t(
                "security_page.sessions_error",
                "Could not load your sessions.",
              )}
            </p>
            <Button variant="outline" size="sm" onClick={() => refetch()}>
              {t("common.retry", "Retry")}
            </Button>
          </div>
        ) : sessions.length === 0 ? (
          <EmptyState
            icon={Laptop}
            title={t("security_page.no_sessions", "No active sessions")}
            description={t(
              "security_page.no_sessions_desc",
              "Sessions appear here when you sign in.",
            )}
          />
        ) : (
          sessions.map((session) => (
            <div
              key={session.id}
              className="flex flex-col gap-2 rounded-md border border-border-default p-3 sm:flex-row sm:items-center sm:justify-between"
            >
              <div className="flex items-start gap-3">
                <Laptop className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                <div>
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="text-sm font-medium text-foreground">
                      {session.device}
                    </p>
                    {session.is_current && (
                      <Badge className="border-0 bg-success-soft text-success-on-soft">
                        {t("security_page.this_device", "This device")}
                      </Badge>
                    )}
                  </div>
                  <p className="text-xs text-muted-foreground">
                    {session.ip_address ?? t("common.not_set", "Not set")} ·{" "}
                    {formatWhen(
                      session.last_used_at,
                      t("security_page.never_used", "Not used yet"),
                    )}
                  </p>
                </div>
              </div>
              {/* The current session is not revocable here — signing yourself out
                  while tidying up would be a surprise, and Log out already does it. */}
              {!session.is_current && (
                <Button
                  variant="outline"
                  size="sm"
                  className="text-destructive hover:bg-destructive/10"
                  onClick={() => handleRevoke(session.id)}
                  disabled={revoke.isPending}
                >
                  <Trash2 className="mr-1 h-3 w-3" />
                  {t("security_page.revoke", "Revoke")}
                </Button>
              )}
            </div>
          ))
        )}
      </CardContent>
    </Card>
  );
}

/** Browsers that skip this user's MFA prompt. */
export function TrustedDevicesCard() {
  const { t } = useT();
  const { data, isLoading, isError, refetch } = useTrustedDevices();
  const revoke = useRevokeTrustedDevice();

  const devices = data?.data ?? [];

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">
          {t("security_page.trusted_devices", "Trusted devices")}
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        {isLoading ? (
          <Skeleton className="h-14 w-full" />
        ) : isError ? (
          <div className="flex flex-col items-start gap-2 rounded-md border border-border-default p-4">
            <p className="text-sm text-muted-foreground">
              {t(
                "security_page.devices_error",
                "Could not load your trusted devices.",
              )}
            </p>
            <Button variant="outline" size="sm" onClick={() => refetch()}>
              {t("common.retry", "Retry")}
            </Button>
          </div>
        ) : devices.length === 0 ? (
          <EmptyState
            icon={ShieldCheck}
            title={t("security_page.no_trusted_devices", "No trusted devices")}
            description={t(
              "security_page.no_trusted_devices_desc",
              "Choose “Trust this device” when verifying a code to skip it here for 30 days.",
            )}
          />
        ) : (
          devices.map((device) => (
            <div
              key={device.id}
              className="flex flex-col gap-2 rounded-md border border-border-default p-3 sm:flex-row sm:items-center sm:justify-between"
            >
              <div>
                <p className="text-sm font-medium text-foreground">
                  {device.device_name ??
                    t("security_page.unknown_device", "Unknown device")}
                </p>
                <p className="text-xs text-muted-foreground">
                  {t("security_page.trusted_until", "Trusted until")}{" "}
                  {formatWhen(device.expires_at, "—")}
                </p>
              </div>
              <Button
                variant="outline"
                size="sm"
                className="text-destructive hover:bg-destructive/10"
                onClick={() =>
                  revoke.mutate(device.id, {
                    onSuccess: () =>
                      toast.success(
                        t(
                          "security_page.device_removed",
                          "Device removed. MFA will be required on it again.",
                        ),
                      ),
                    onError: () =>
                      toast.error(
                        t(
                          "security_page.device_remove_failed",
                          "Could not remove that device.",
                        ),
                      ),
                  })
                }
                disabled={revoke.isPending}
              >
                <Trash2 className="mr-1 h-3 w-3" />
                {t("security_page.remove", "Remove")}
              </Button>
            </div>
          ))
        )}
      </CardContent>
    </Card>
  );
}
