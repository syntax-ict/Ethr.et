"use client";

import { useEffect, useState } from "react";
import { Loader2, ShieldCheck, Smartphone } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import {
  useAttendanceDevice,
  useRegisterDevice,
} from "@/features/attendance/api";
import { apiErrorDetail } from "@/lib/api/error-message";
import { passkeySupported } from "@/lib/attendance/passkey";
import { useT } from "@/lib/i18n/useT";

/**
 * This phone and the organisation's rule about it. Shown only where phones
 * are checked: registered, waiting for approval, not registered yet, or on
 * the wrong address. The first phone registers by itself only at work.
 */
export function DeviceCard() {
  const { t } = useT();
  const { data: state } = useAttendanceDevice();
  const register = useRegisterDevice();
  const [supported, setSupported] = useState<boolean | null>(null);

  useEffect(() => {
    let live = true;
    void passkeySupported().then((ok) => {
      if (live) setSupported(ok);
    });
    return () => {
      live = false;
    };
  }, []);

  if (!state || state.binding === "off") return null;

  if (!state.host_allowed) {
    return (
      <Notice
        tone="warning"
        title={t("device.wrong_host_title", "Open your organisation address")}
        body={t(
          "device.wrong_host_body",
          "Your registered phone works on your organisation's own ETHR address.",
        )}
        action={
          state.canonical_url ? (
            <Button asChild size="sm" variant="outline">
              <a href={state.canonical_url}>{t("device.open", "Open")}</a>
            </Button>
          ) : null
        }
      />
    );
  }

  if (state.device) {
    return (
      <Notice
        tone="success"
        title={t("device.registered_title", "This is your registered phone")}
        body={
          state.pending
            ? t(
                "device.replacement_pending",
                "A new phone is waiting for approval. Keep using this one until then.",
              )
            : t(
                "device.registered_body",
                "Your punches are confirmed with this phone's fingerprint, face or PIN.",
              )
        }
      />
    );
  }

  if (state.pending) {
    return (
      <Notice
        tone="warning"
        title={t("device.pending_title", "Waiting for approval")}
        body={t(
          "device.pending_body",
          "This phone was registered away from work. Your manager or HR will approve it.",
        )}
      />
    );
  }

  if (supported === false) {
    return (
      <Notice
        tone="warning"
        title={t(
          "device.unsupported_title",
          "This browser cannot hold a passkey",
        )}
        body={t(
          "device.unsupported_body",
          "Use an up to date browser on your phone, or the kiosk at work. HR can exempt you for a while.",
        )}
      />
    );
  }

  return (
    <Notice
      tone={state.binding === "enforce" ? "warning" : "info"}
      title={t("device.register_title", "Register this phone")}
      body={t(
        "device.register_body",
        "Register the phone you punch with while you are at work. Only this phone can then punch for you.",
      )}
      action={
        <Button
          size="sm"
          disabled={register.isPending || supported === null}
          onClick={() =>
            register.mutate(undefined, {
              onSuccess: (device) =>
                toast.success(
                  device.status === "active"
                    ? t("device.registered_toast", "Phone registered.")
                    : t(
                        "device.requested_toast",
                        "Sent for approval. You were not at work, so a manager will confirm it.",
                      ),
                ),
              onError: (err) =>
                toast.error(
                  apiErrorDetail(err) ??
                    t(
                      "device.register_failed",
                      "Could not register this phone.",
                    ),
                ),
            })
          }
        >
          {register.isPending && (
            <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden />
          )}
          {t("device.register", "Register")}
        </Button>
      }
    />
  );
}

function Notice({
  tone,
  title,
  body,
  action,
}: {
  tone: "success" | "warning" | "info";
  title: string;
  body: string;
  action?: React.ReactNode;
}) {
  const Icon = tone === "success" ? ShieldCheck : Smartphone;
  const color =
    tone === "success"
      ? "text-[var(--color-status-success)]"
      : tone === "warning"
        ? "text-[var(--color-status-warning)]"
        : "text-[var(--color-status-info)]";

  return (
    <Card>
      <CardContent className="flex items-start gap-3 p-4">
        <Icon className={`mt-0.5 h-5 w-5 shrink-0 ${color}`} aria-hidden />
        <div className="min-w-0 flex-1">
          <p className="text-sm font-medium">{title}</p>
          <p className="text-sm text-[var(--color-text-secondary)]">{body}</p>
        </div>
        {action}
      </CardContent>
    </Card>
  );
}
