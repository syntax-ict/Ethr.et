"use client";

import { useEffect, useState } from "react";
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
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

interface PreferencesResponse {
  notification_types: string[];
  channels: string[];
  preferences: Record<string, Record<string, boolean>>;
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

  // Sync server data → local editable copy the first time it arrives
  useEffect(() => {
    if (data && !local) {
      setLocal(data.preferences);
    }
  }, [data, local]);

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

  function toggle(typeKey: string, channelKey: string) {
    if (CHANNEL_META[channelKey]?.alwaysOn) return;
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
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b bg-muted/50">
                  <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">
                    {t("notification_prefs_page.event")}
                  </th>
                  {data.channels.map((ch) => {
                    const meta = CHANNEL_META[ch];
                    const Icon = meta?.icon ?? Bell;
                    return (
                      <th
                        key={ch}
                        className="px-4 py-3 text-center text-xs font-medium uppercase text-muted-foreground"
                      >
                        <div className="flex flex-col items-center gap-1">
                          <Icon className="h-4 w-4" />
                          <span>{meta ? t(meta.labelKey) : ch}</span>
                        </div>
                      </th>
                    );
                  })}
                </tr>
              </thead>
              <tbody>
                {data.notification_types.map((type) => {
                  const meta = TYPE_LABEL_KEYS[type];
                  const label = meta ? t(meta.labelKey) : type;
                  const description = meta ? t(meta.descKey) : "";
                  return (
                    <tr
                      key={type}
                      className="border-b last:border-0 hover:bg-muted/30"
                    >
                      <td className="px-4 py-3">
                        <p className="text-sm font-medium text-foreground">
                          {label}
                        </p>
                        <p className="text-xs text-muted-foreground">
                          {description}
                        </p>
                      </td>
                      {data.channels.map((ch) => {
                        const cm = CHANNEL_META[ch];
                        const value = local[type]?.[ch] ?? false;
                        return (
                          <td key={ch} className="px-4 py-3 text-center">
                            <label className="inline-flex cursor-pointer items-center">
                              <input
                                type="checkbox"
                                checked={value}
                                disabled={cm?.alwaysOn}
                                onChange={() => toggle(type, ch)}
                                className="h-5 w-5 rounded cursor-pointer disabled:cursor-not-allowed disabled:opacity-60"
                              />
                            </label>
                          </td>
                        );
                      })}
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          <div className="border-t bg-muted/30 px-4 py-3 text-xs text-muted-foreground">
            {t("notification_prefs_page.footer_notice")}
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
