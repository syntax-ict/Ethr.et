"use client";

import { useId } from "react";
import { toast } from "sonner";
import { Card, CardContent } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { useSettings, useUpdateSettings } from "@/features/settings/api";
import { toastError } from "@/lib/errors";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";

/**
 * The organisation's choice to remind people who have not activated: another
 * invitation three days after the last one, at most twice. On unless turned off.
 */
export function InvitationRemindersSetting() {
  const { t } = useT();
  const { can } = usePermissions();
  const id = useId();
  const settings = useSettings();
  const update = useUpdateSettings();

  if (!can.manageSettings || !settings.data) {
    return null;
  }

  const enabled = settings.data.employees.invitation_reminders;

  function change(checked: boolean) {
    update.mutate(
      { invitation_reminders: checked },
      {
        onSuccess: () =>
          toast.success(
            checked
              ? t("users_page.reminders_on", "Reminders turned on")
              : t("users_page.reminders_off", "Reminders turned off"),
          ),
        onError: (e) =>
          toastError(e, t("common.error", "Something went wrong")),
      },
    );
  }

  return (
    <Card>
      <CardContent className="flex items-start justify-between gap-4 p-4">
        <div className="space-y-1">
          <Label htmlFor={id} className="text-sm font-medium">
            {t("users_page.reminders", "Remind people who have not activated")}
          </Label>
          <p className="text-xs text-muted-foreground">
            {t(
              "users_page.reminders_hint",
              "Another invitation goes out three days after the last one, at most twice.",
            )}
          </p>
        </div>
        <Switch
          id={id}
          checked={enabled}
          disabled={update.isPending}
          onCheckedChange={change}
        />
      </CardContent>
    </Card>
  );
}
