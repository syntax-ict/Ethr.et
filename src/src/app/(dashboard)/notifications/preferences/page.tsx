"use client";

import { useState } from "react";
import {
  Mail,
  Bell,
  MessageSquare,
  Save,
  Loader2,
  RotateCcw,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { SimpleTable } from "@/components/shared/simple-table";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

interface PreferencesResponse {
  notification_types: string[];
  channels: string[];
  preferences: Record<string, Record<string, boolean>>;
  /**
   * Which channels this deployment can actually deliver on. SMS needs a
   * configured gateway; without one the column is disabled rather than offering
   * a toggle that nothing acts on.
   */
  channel_availability?: Record<string, boolean>;
}

const TYPE_LABEL_KEYS: Record<string, { labelKey: string; descKey: string }> = {
  leave_requested: {
    labelKey: "notification_prefs_page.type_leave_requested",
    descKey: "notification_prefs_page.desc_leave_requested",
  },
  leave_approved: {
    labelKey: "notification_prefs_page.type_leave_approved",
    descKey: "notification_prefs_page.desc_leave_approved",
  },
  leave_rejected: {
    labelKey: "notification_prefs_page.type_leave_rejected",
    descKey: "notification_prefs_page.desc_leave_rejected",
  },
  attendance_correction: {
    labelKey: "notification_prefs_page.type_correction_request",
    descKey: "notification_prefs_page.desc_correction_request",
  },
  attendance_anomaly: {
    labelKey: "notification_prefs_page.type_attendance_anomaly",
    descKey: "notification_prefs_page.desc_attendance_anomaly",
  },
  payslip_available: {
    labelKey: "notification_prefs_page.type_payslip_available",
    descKey: "notification_prefs_page.desc_payslip_available",
  },
  payroll_processed: {
    labelKey: "notification_prefs_page.type_payroll_processed",
    descKey: "notification_prefs_page.desc_payroll_processed",
  },
  announcement: {
    labelKey: "notification_prefs_page.type_announcement",
    descKey: "notification_prefs_page.desc_announcement",
  },
  approval_reminder: {
    labelKey: "notification_prefs_page.type_approval_reminder",
    descKey: "notification_prefs_page.desc_approval_reminder",
  },
};

const CHANNEL_META: Record<
  string,
  {
    labelKey: string;
    icon: React.ComponentType<{ className?: string }>;
    alwaysOn?: boolean;
  }
> = {
  in_app: {
    labelKey: "notification_prefs_page.channel_in_app",
    icon: Bell,
    alwaysOn: true,
  },
  email: { labelKey: "notification_prefs_page.channel_email", icon: Mail },
  sms: { labelKey: "notification_prefs_page.channel_sms", icon: MessageSquare },
};

export default function NotificationPreferencesPage() {
  const { t } = useT();
  const queryClient = useQueryClient();
  const [local, setLocal] = useState<Record<
    string,
    Record<string, boolean>
  > | null>(null);
  const [dirty, setDirty] = useState(false);

  const { data, isLoading } = useQuery<PreferencesResponse>({
    queryKey: ["notifications", "preferences"],
    queryFn: async () =>
      (await apiClient.get("/notifications/preferences")).data,
  });

  // Sync server data → local editable copy the first time it arrives. Adjusting
  // state during render avoids an extra effect commit — see
  // https://react.dev/learn/you-might-not-need-an-effect.
  if (data && !local) {
    setLocal(data.preferences);
  }

  const save = useMutation({
    mutationFn: async () => {
      if (!local) throw new Error("No preferences");
      const { data } = await apiClient.put("/notifications/preferences", {
        preferences: local,
      });
      return data;
    },
    onSuccess: (result) => {
      queryClient.setQueryData(["notifications", "preferences"], result);
      setLocal(result.preferences);
      setDirty(false);
      toast.success(t("notification_prefs_page.saved"));
    },
    onError: () => toast.error(t("notification_prefs_page.save_failed")),
  });

  /**
   * A channel the deployment cannot deliver on. The server omits the field on
   * older builds, so an absent entry means "assume available" rather than
   * blanking every column.
   */
  function isUnavailable(channelKey: string) {
    return data?.channel_availability?.[channelKey] === false;
  }

  function toggle(typeKey: string, channelKey: string) {
    if (CHANNEL_META[channelKey]?.alwaysOn) return;
    if (isUnavailable(channelKey)) return;
    setLocal((p) => {
      if (!p) return p;
      return {
        ...p,
        [typeKey]: { ...p[typeKey], [channelKey]: !p[typeKey][channelKey] },
      };
    });
    setDirty(true);
  }

  function reset() {
    if (data) {
      setLocal(data.preferences);
      setDirty(false);
    }
  }

  if (isLoading || !data || !local) {
    return (
      <div className="space-y-6">
        <PageHeader
          title={t("notification_prefs_page.title")}
          description={t("notification_prefs_page.description_short")}
        />
        <Skeleton className="h-96 w-full" />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("notification_prefs_page.title")}
        description={t("notification_prefs_page.description")}
        actions={
          <div className="flex gap-2">
            {dirty && (
              <Button
                variant="outline"
                onClick={reset}
                disabled={save.isPending}
              >
                <RotateCcw className="mr-2 h-4 w-4" />{" "}
                {t("notification_prefs_page.reset")}
              </Button>
            )}
            <Button
              onClick={() => save.mutate()}
              disabled={!dirty || save.isPending}
            >
              {save.isPending ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : (
                <Save className="mr-2 h-4 w-4" />
              )}
              {t("leave_types_page.save_changes")}
            </Button>
          </div>
        }
      />

      <Card>
        <CardHeader>
          <CardTitle className="text-base">
            {t("notification_prefs_page.notification_types")}
          </CardTitle>
        </CardHeader>
        <CardContent className="p-0">
          <SimpleTable
            caption={t(
              "notification_prefs_page.notification_types",
              "Notification types",
            )}
            headers={[
              t("notification_prefs_page.event"),
              ...data.channels.map((ch) => {
                const meta = CHANNEL_META[ch];
                const Icon = meta?.icon ?? Bell;
                const unavailable = isUnavailable(ch);
                return (
                  <div key={ch} className="flex flex-col items-center gap-1">
                    <Icon className="h-4 w-4" />
                    <span>{meta ? t(meta.labelKey) : ch}</span>
                    {unavailable && (
                      <span className="rounded-full bg-neutral-soft px-2 py-0.5 text-[10px] font-medium normal-case text-neutral-on-soft">
                        {t(
                          "notification_prefs_page.channel_unavailable",
                          "Not configured",
                        )}
                      </span>
                    )}
                  </div>
                );
              }),
            ]}
            align={["left", ...data.channels.map(() => "center" as const)]}
            rows={data.notification_types.map((type) => {
              const meta = TYPE_LABEL_KEYS[type];
              const label = meta ? t(meta.labelKey) : type;
              const description = meta ? t(meta.descKey) : "";
              return {
                key: type,
                cells: [
                  <div key="label">
                    <p className="font-medium text-foreground">{label}</p>
                    <p className="text-xs text-muted-foreground">
                      {description}
                    </p>
                  </div>,
                  ...data.channels.map((ch) => {
                    const cm = CHANNEL_META[ch];
                    const unavailable = isUnavailable(ch);
                    const value = local[type]?.[ch] ?? false;
                    return (
                      <label
                        key={ch}
                        className="inline-flex cursor-pointer items-center"
                      >
                        <input
                          type="checkbox"
                          checked={value && !unavailable}
                          disabled={cm?.alwaysOn || unavailable}
                          aria-label={`${label} — ${cm ? t(cm.labelKey) : ch}`}
                          onChange={() => toggle(type, ch)}
                          className="h-5 w-5 cursor-pointer rounded disabled:cursor-not-allowed disabled:opacity-60"
                        />
                      </label>
                    );
                  }),
                ],
              };
            })}
          />
          <div className="border-t bg-muted/30 px-4 py-3 text-xs text-muted-foreground">
            {t("notification_prefs_page.footer_notice")}
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
