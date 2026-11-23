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
import { PageActions } from "@/components/shared/page-actions";
import { SimpleTable } from "@/components/shared/simple-table";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  useNotificationPreferences,
  useUpdateNotificationPreferences,
} from "@/features/notifications/api";
import { PushToggle } from "@/features/notifications/components/push-toggle";
import { useT } from "@/lib/i18n/useT";
import {
  useNotificationTypeDescription,
  useNotificationTypeLabel,
} from "@/lib/options/notification-type";
import { toast } from "sonner";
import { apiErrorMessage } from "@/lib/api/error-message";

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
  const typeLabel = useNotificationTypeLabel();
  const typeDescription = useNotificationTypeDescription();
  const [local, setLocal] = useState<Record<
    string,
    Record<string, boolean>
  > | null>(null);
  const [dirty, setDirty] = useState(false);

  const preferences = useNotificationPreferences();
  const { data } = preferences;

  // Sync server data → local editable copy the first time it arrives. Adjusting
  // state during render avoids an extra effect commit — see
  // https://react.dev/learn/you-might-not-need-an-effect.
  if (data && !local) {
    setLocal(data.preferences);
  }

  const save = useUpdateNotificationPreferences();

  function saveChanges() {
    if (!local) return;
    save.mutate(local, {
      onSuccess: (result) => {
        setLocal(result.preferences);
        setDirty(false);
        toast.success(t("notification_prefs_page.saved"));
      },
      onError: (err) =>
        toast.error(
          apiErrorMessage(err, t("notification_prefs_page.save_failed")),
        ),
    });
  }

  /**
   * A channel the deployment cannot deliver on. The server omits the field on
   * older builds, so an absent entry means "assume available" rather than
   * blanking every column.
   */
  function isUnavailable(channelKey: string) {
    const availability: Record<string, boolean> | undefined =
      data?.channel_availability;
    return availability?.[channelKey] === false;
  }

  /**
   * The channels this page draws: SMS is left out while it is unavailable,
   * because no type can send it, so its column would be switches that change
   * nothing.
   */
  const channels = (data?.channels ?? []).filter(
    (ch) => ch !== "sms" || !isUnavailable("sms"),
  );

  /** What a type can reach. An older server omits the map: assume every channel. */
  function sendsOn(type: string, channelKey: string) {
    const reach: Record<string, string[]> | undefined = data?.type_channels;
    return reach?.[type]?.includes(channelKey) ?? true;
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

  // Through QueryBoundary so a failed load shows its error and a retry. It
  // was `if (isLoading || !data)` → skeleton, so a failed request left the
  // skeleton on screen for good.
  if (!data || !local) {
    return (
      <div className="space-y-6">
        <QueryBoundary
          query={preferences}
          loading={<Skeleton className="h-96 w-full" />}
        >
          {() => <Skeleton className="h-96 w-full" />}
        </QueryBoundary>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <PageActions
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
            <Button onClick={saveChanges} disabled={!dirty || save.isPending}>
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
              ...channels.map((ch) => {
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
            align={["left", ...channels.map(() => "center" as const)]}
            rows={data.notification_types.map((type) => {
              const label = typeLabel(type);
              const description = typeDescription(type);
              return {
                key: type,
                cells: [
                  <div key="label">
                    <p className="font-medium text-foreground">{label}</p>
                    <p className="text-xs text-muted-foreground">
                      {description}
                    </p>
                  </div>,
                  ...channels.map((ch) => {
                    if (!sendsOn(type, ch)) {
                      return (
                        <span
                          key={ch}
                          className="text-xs text-muted-foreground"
                        >
                          {t(
                            "notification_prefs_page.not_sent_this_way",
                            "Not sent this way",
                          )}
                        </span>
                      );
                    }
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
                          aria-label={`${label}: ${cm ? t(cm.labelKey) : ch}`}
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

      {/* PushToggle draws nothing where the browser or the server has no push,
          so the section hides itself until a switch is there. */}
      <section
        aria-labelledby="push-heading"
        className="hidden has-[[role=switch]]:block"
      >
        <Card>
          <CardHeader>
            <CardTitle id="push-heading" className="text-base">
              {t(
                "notification_prefs_page.this_device",
                "Notifications on this device",
              )}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <PushToggle />
          </CardContent>
        </Card>
      </section>
    </div>
  );
}
