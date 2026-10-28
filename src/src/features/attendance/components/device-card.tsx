"use client";

import { useEffect, useState, useSyncExternalStore } from "react";
import { Loader2, ShieldCheck, Smartphone } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import {
  useAttendanceDevice,
  useRegisterDevice,
} from "@/features/attendance/api";
import { apiErrorDetail } from "@/lib/api/error-message";
import {
  alreadyRegisteredHere,
  passkeySupported,
  rememberThisPhone,
  subscribeThisPhone,
  thisPhone,
} from "@/lib/attendance/passkey";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";

/**
 * This phone and the organisation's rule about it. Shown only where phones
 * are checked: registered, waiting for approval, not registered yet, or on
 * the wrong address. The first phone registers by itself only at work.
 *
 * "Your registered phone" is the same answer on every browser, so the card
 * says "this is it" only where this browser is known to be that phone
 * (thisPhone). Anywhere else it names the registered phone and offers to
 * register this one instead, which is how a lost or new phone is replaced.
 */
export function DeviceCard() {
  const { t } = useT();
  const { timeAgo } = useDateFormatters();
  const { data: state } = useAttendanceDevice();
  const register = useRegisterDevice();
  const [supported, setSupported] = useState<boolean | null>(null);
  const here = useSyncExternalStore(subscribeThisPhone, thisPhone, () => null);

  useEffect(() => {
    let live = true;
    void passkeySupported().then((ok) => {
      if (live) setSupported(ok);
    });
    return () => {
      live = false;
    };
  }, []);

  const registerButton = (label: string) => (
    <Button
      size="sm"
      variant={state?.device ? "outline" : "default"}
      disabled={register.isPending || supported === null}
      onClick={() =>
        register.mutate(undefined, {
          onSuccess: (device) => {
            toast.success(
              device.status === "active"
                ? t("device.registered_toast", "Phone registered.")
                : state?.device
                  ? t(
                      "device.replacement_toast",
                      "Sent for approval. Your old phone works until HR approves this one.",
                    )
                  : t(
                      "device.requested_toast",
                      "Sent for approval. You were not at work, so a manager will confirm it.",
                    ),
            );
          },
          onError: (err) => {
            if (alreadyRegisteredHere(err)) {
              if (state?.device && !state.pending) {
                rememberThisPhone(state.device.public_id);
              }
              toast.info(
                t(
                  "device.already_here",
                  "This phone is already registered or waiting for approval.",
                ),
              );
              return;
            }
            toast.error(
              apiErrorDetail(err) ??
                t("device.register_failed", "Could not register this phone."),
            );
          },
        })
      }
    >
      {register.isPending && (
        <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden />
      )}
      {label}
    </Button>
  );

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

  if (state.device && here === state.device.public_id) {
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

  if (state.device && state.pending && here === state.pending.public_id) {
    return (
      <Notice
        tone="warning"
        title={t(
          "device.this_pending_title",
          "This phone is waiting for approval",
        )}
        body={t(
          "device.this_pending_body",
          "Your registered phone keeps working until HR approves this one. Then only this phone can punch for you.",
        )}
      />
    );
  }

  if (state.device) {
    const name = state.device.label ?? t("devices.unnamed", "Phone");
    const used = state.device.last_used_at
      ? t("device.last_used", "Last punched :when.", {
          when: timeAgo(state.device.last_used_at),
        })
      : "";
    return (
      <Notice
        tone="info"
        title={t(
          "device.registered_elsewhere_title",
          "Your registered phone: :name",
          { name },
        )}
        body={`${used ? `${used} ` : ""}${t(
          "device.registered_elsewhere_body",
          "On a different phone now? Register this one and HR will approve it. Your old phone stops working once they do.",
        )}`}
        action={
          supported === false
            ? null
            : registerButton(t("device.register_this", "Register this phone"))
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
      action={registerButton(t("device.register", "Register"))}
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
