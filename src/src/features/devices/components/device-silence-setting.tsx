"use client";

import { useState } from "react";
import { Loader2, Save, WifiOff } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";
import { SettingRow, HowItWorks } from "@/components/patterns/SettingRow";
import { InputWithUnit } from "@/components/shared/input-with-unit";
import { ErrorState } from "@/components/shared/error-state";
import { useSaveStatus } from "@/components/shared/save-status";
import { useSettings, useUpdateSettings } from "@/features/settings/api";
import { apiErrorMessage } from "@/lib/api/error-message";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useUnsavedChangesWarning } from "@/lib/hooks/useUnsavedChangesWarning";
import { useT } from "@/lib/i18n/useT";

/** The accepted ranges, as DeviceSilenceRules states them. */
export const ADMS_MINUTES = { min: 15, max: 240, fallback: 30 };
export const PUSH_GRACE_MINUTES = { min: 30, max: 480, fallback: 60 };

interface SilenceForm {
  enabled: boolean;
  adms: string;
  push: string;
}

function wholeNumberIn(value: string, min: number, max: number): boolean {
  if (!/^\d+$/.test(value.trim())) return false;
  const n = Number(value);
  return n >= min && n <= max;
}

/**
 * Whether the organisation's admins are told when a terminal that only sends to
 * ETHR goes silent, and how soon. On unless turned off. It is stored with the
 * organisation's settings, so only someone who can change those sees it.
 */
export function DeviceSilenceSetting() {
  const { t } = useT();
  const { can } = usePermissions();
  const { data, isLoading, isError, refetch } = useSettings();
  const save = useUpdateSettings();
  const { announceSaved, announceError } = useSaveStatus();

  const persisted: SilenceForm = {
    enabled: data?.devices?.silence_alerts ?? true,
    adms: String(data?.devices?.silence_adms_minutes ?? ADMS_MINUTES.fallback),
    push: String(
      data?.devices?.silence_push_grace_minutes ?? PUSH_GRACE_MINUTES.fallback,
    ),
  };
  const [edits, setEdits] = useState<SilenceForm | null>(null);
  const [errors, setErrors] = useState<{ adms?: string; push?: string }>({});
  const form = edits ?? persisted;
  const isDirty =
    edits !== null &&
    (form.enabled !== persisted.enabled ||
      form.adms !== persisted.adms ||
      form.push !== persisted.push);
  useUnsavedChangesWarning(isDirty);

  if (!can.manageSettings) {
    return null;
  }

  function submit() {
    const next: { adms?: string; push?: string } = {};
    if (!wholeNumberIn(form.adms, ADMS_MINUTES.min, ADMS_MINUTES.max)) {
      next.adms = t("device_silence.range", undefined, {
        min: String(ADMS_MINUTES.min),
        max: String(ADMS_MINUTES.max),
      });
    }
    if (
      !wholeNumberIn(form.push, PUSH_GRACE_MINUTES.min, PUSH_GRACE_MINUTES.max)
    ) {
      next.push = t("device_silence.range", undefined, {
        min: String(PUSH_GRACE_MINUTES.min),
        max: String(PUSH_GRACE_MINUTES.max),
      });
    }
    setErrors(next);
    if (next.adms || next.push) return;

    save.mutate(
      {
        device_silence_alerts: form.enabled,
        device_silence_adms_minutes: Number(form.adms),
        device_silence_push_grace_minutes: Number(form.push),
      },
      {
        onSuccess: () => {
          setEdits(null);
          const message = t(
            "device_silence.saved",
            "Silent terminal notices saved",
          );
          toast.success(message);
          announceSaved(message);
        },
        onError: (err) => {
          const message = apiErrorMessage(
            err,
            t("device_silence.save_failed", "Could not save the notices"),
          );
          toast.error(message);
          announceError(err, message);
        },
      },
    );
  }

  return (
    <Card className="lg:col-span-2">
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle className="flex items-center gap-2 text-base">
            <WifiOff className="h-4 w-4" aria-hidden="true" />{" "}
            {t("device_silence.title", "Silent terminals")}
          </CardTitle>
          <p className="mt-1 text-sm text-muted-foreground">
            {t(
              "device_silence.description",
              "Tells admins when a terminal goes quiet during work hours.",
            )}
          </p>
          <HowItWorks className="mt-1">
            {t(
              "device_silence.details",
              "The organisation's admins and HR admins are told, at most once a day per terminal, when a terminal that only sends to ETHR goes quiet during working hours.",
            )}
          </HowItWorks>
        </div>
        {isDirty && !isError && (
          <Button onClick={submit} disabled={save.isPending} size="sm">
            {save.isPending ? (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            ) : (
              <Save className="mr-2 h-4 w-4" />
            )}
            {t("common.save", "Save")}
          </Button>
        )}
      </CardHeader>
      <CardContent className="grid gap-4 sm:grid-cols-2">
        {isLoading ? (
          <>
            <Skeleton className="h-16" />
            <Skeleton className="h-16" />
          </>
        ) : isError ? (
          <ErrorState
            compact
            className="sm:col-span-2"
            onRetry={() => refetch()}
          />
        ) : (
          <>
            <div className="flex items-center justify-between gap-4 sm:col-span-2">
              <Label
                htmlFor="device_silence_alerts"
                className="text-sm font-medium"
              >
                {t(
                  "device_silence.enabled",
                  "Tell me when a terminal goes silent",
                )}
              </Label>
              <Switch
                id="device_silence_alerts"
                checked={form.enabled}
                onCheckedChange={(checked) =>
                  setEdits({ ...form, enabled: checked })
                }
              />
            </div>
            <SettingRow
              id="device_silence_adms_minutes"
              title={t("device_silence.adms_label", "Not connected after")}
              description={t(
                "device_silence.adms_help",
                "For terminals that call ETHR all the time.",
              )}
              details={t(
                "device_silence.adms_details",
                "The scheduler on the hosting can run 10 to 20 minutes late, so a short limit is not exact.",
              )}
              error={
                errors.adms ? (
                  <p
                    id="device_silence_adms_minutes-error"
                    role="alert"
                    className="text-caption text-destructive"
                  >
                    {errors.adms}
                  </p>
                ) : null
              }
            >
              <InputWithUnit
                id="device_silence_adms_minutes"
                unit={t("common.unit_min", "min")}
                type="number"
                min={ADMS_MINUTES.min}
                max={ADMS_MINUTES.max}
                value={form.adms}
                disabled={!form.enabled}
                aria-invalid={errors.adms ? true : undefined}
                aria-describedby={
                  errors.adms ? "device_silence_adms_minutes-error" : undefined
                }
                onChange={(e) => {
                  setEdits({ ...form, adms: e.target.value });
                  setErrors((prev) => ({ ...prev, adms: undefined }));
                }}
              />
            </SettingRow>
            <SettingRow
              id="device_silence_push_grace_minutes"
              title={t(
                "device_silence.push_label",
                "No punches after the day began",
              )}
              description={t(
                "device_silence.push_help",
                "For terminals heard only when someone punches.",
              )}
              details={t(
                "device_silence.push_details",
                "Counted from the start of the default shift, and only where people have badge numbers.",
              )}
              error={
                errors.push ? (
                  <p
                    id="device_silence_push_grace_minutes-error"
                    role="alert"
                    className="text-caption text-destructive"
                  >
                    {errors.push}
                  </p>
                ) : null
              }
            >
              <InputWithUnit
                id="device_silence_push_grace_minutes"
                unit={t("common.unit_min", "min")}
                type="number"
                min={PUSH_GRACE_MINUTES.min}
                max={PUSH_GRACE_MINUTES.max}
                value={form.push}
                disabled={!form.enabled}
                aria-invalid={errors.push ? true : undefined}
                aria-describedby={
                  errors.push
                    ? "device_silence_push_grace_minutes-error"
                    : undefined
                }
                onChange={(e) => {
                  setEdits({ ...form, push: e.target.value });
                  setErrors((prev) => ({ ...prev, push: undefined }));
                }}
              />
            </SettingRow>
          </>
        )}
      </CardContent>
    </Card>
  );
}
