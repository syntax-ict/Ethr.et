"use client";

import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { BellRing } from "lucide-react";
import { toast } from "sonner";
import { Switch } from "@/components/ui/switch";
import { apiClient } from "@/api/client";
import {
  currentSubscription,
  pushSupported,
  subscribe,
  unsubscribe,
  type PushConfig,
} from "@/lib/push/web-push";
import { useT } from "@/lib/i18n/useT";

/**
 * "Notify me on this phone even when ETHR is closed": shift reminders,
 * approvals and every other notification as web push. Shown only where the
 * server has push set up and the browser supports it.
 */
export function PushToggle() {
  const { t } = useT();
  const config = useQuery<PushConfig>({
    queryKey: ["push", "config"],
    queryFn: async () => (await apiClient.get("/push/config")).data,
    staleTime: 60 * 60 * 1000,
    retry: false,
    enabled: pushSupported(),
  });
  const [on, setOn] = useState(false);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let alive = true;
    void currentSubscription().then((s) => alive && setOn(s !== null));
    return () => {
      alive = false;
    };
  }, []);

  if (!pushSupported() || !config.data?.enabled || !config.data.public_key) {
    return null;
  }
  const key = config.data.public_key;

  async function change(next: boolean) {
    setBusy(true);
    try {
      if (next) {
        const ok = await subscribe(key);
        setOn(ok);
        if (!ok) {
          toast.error(
            t(
              "push.denied",
              "Notifications are blocked for ETHR. Allow them in your browser settings.",
            ),
          );
        }
      } else {
        await unsubscribe();
        setOn(false);
      }
    } catch {
      toast.error(t("push.failed", "Couldn't change phone notifications"));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="flex items-start justify-between gap-3">
      <div className="min-w-0">
        <p className="flex items-center gap-1.5 text-sm font-medium text-foreground">
          <BellRing
            className="h-3.5 w-3.5 text-muted-foreground"
            aria-hidden="true"
          />
          {t("push.title", "Even when ETHR is closed")}
        </p>
        <p className="text-xs text-muted-foreground">
          {t(
            "push.description",
            "Reminders and approvals arrive on this phone like a message. They can come a few minutes late. On iPhone, add ETHR to the Home Screen first.",
          )}
        </p>
      </div>
      <Switch
        checked={on}
        disabled={busy}
        onCheckedChange={(next) => void change(next)}
        aria-label={t("push.title", "Even when ETHR is closed")}
      />
    </div>
  );
}
