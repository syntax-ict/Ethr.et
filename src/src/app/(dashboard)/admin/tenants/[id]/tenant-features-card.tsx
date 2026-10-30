"use client";

import { useId, useState } from "react";
import { AlertTriangle } from "lucide-react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { SimpleTable } from "@/components/shared/simple-table";
import {
  FeatureReasonDialog,
  fieldErrorsOf,
} from "@/features/admin/components/feature-reason-dialog";
import {
  FEATURE_LABEL_FALLBACK,
  featureLabelKey,
  useRemoveTenantFeatureOverride,
  useSetTenantFeatureOverride,
  useTenantFeatures,
  type FeatureOverrideMode,
  type FeatureReason,
  type TenantFeature,
} from "@/features/admin/features";
import { apiErrorDetail } from "@/lib/api/error-message";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";

const REASON_FALLBACK: Record<FeatureReason, string> = {
  enabled: "As its plan allows",
  granted: "Granted to this organisation",
  plan: "Not in this organisation's plan",
  platform_disabled: "Disabled for every organisation",
  not_in_rollout: "Restricted, and this organisation has no grant",
  revoked: "Revoked for this organisation",
};

/** A grant ends after 30 days unless the admin changes it (owner decision D1). */
const DEFAULT_GRANT_DAYS = 30;

function isoDay(date: Date): string {
  return date.toISOString().slice(0, 10);
}

function daysFromNow(days: number): string {
  return isoDay(new Date(Date.now() + days * 86_400_000));
}

/**
 * Every feature's state for one organisation and why, with its grant or
 * revoke: the "why is payroll off for this organisation" view
 * (docs/FEATURE-CONTROL-CENTER-DESIGN.md §7).
 */
export function TenantFeaturesCard({
  publicId,
  name,
  readOnly,
}: {
  publicId: string;
  name: string;
  /** No MFA on this session, or the organisation is deleted. */
  readOnly: boolean;
}) {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  const query = useTenantFeatures(publicId);
  const [setting, setSetting] = useState<TenantFeature | null>(null);
  const [removing, setRemoving] = useState<TenantFeature | null>(null);

  const label = (feature: TenantFeature) =>
    t(featureLabelKey(feature.key), FEATURE_LABEL_FALLBACK[feature.key]);

  function exception(feature: TenantFeature): string {
    const override = feature.override;
    if (!feature.overridable) {
      return t(
        "admin_features.not_overridable",
        "Decided for every organisation at once",
      );
    }
    if (override === null) return t("admin_features.no_exception", "None");
    if (override.expired) {
      return t("admin_features.exception_expired", "Ended :date", {
        date: formatDate(override.expires_at ?? ""),
      });
    }
    const mode =
      override.mode === "grant"
        ? t("admin_features.mode_grant", "Granted")
        : t("admin_features.mode_revoke", "Revoked");
    return override.expires_at === null
      ? t("admin_features.exception_open", ":mode, no end date", { mode })
      : t("admin_features.exception_until", ":mode until :date", {
          mode,
          date: formatDate(override.expires_at),
        });
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">
          {t("admin_features.tenant_title", "Features")}
        </CardTitle>
      </CardHeader>
      <CardContent className="p-0">
        {query.isLoading ? (
          <div className="p-6">
            <Skeleton className="h-48 w-full" />
          </div>
        ) : query.isError ? (
          <div className="flex items-center gap-3 p-6 text-sm">
            <AlertTriangle className="h-4 w-4 text-status-error" aria-hidden />
            <span className="text-muted-foreground">
              {t(
                "admin_features.load_failed",
                "Could not load this organisation's features.",
              )}
            </span>
            <Button variant="outline" size="sm" onClick={() => query.refetch()}>
              {t("common.retry", "Retry")}
            </Button>
          </div>
        ) : (
          <SimpleTable
            caption={t("admin_features.tenant_title", "Features")}
            headers={[
              t("admin_features.feature", "Feature"),
              t("admin_features.decision", "For this organisation"),
              t("admin_features.exception", "Exception"),
              "",
            ]}
            rows={(query.data ?? []).map((feature) => {
              const active =
                feature.override !== null && !feature.override.expired;

              return {
                key: feature.key,
                cells: [
                  <span key="f" className="font-medium text-foreground">
                    {label(feature)}
                  </span>,
                  <span key="d" className="flex items-center gap-2 text-sm">
                    <Badge variant={feature.on ? "success" : "neutral"}>
                      {feature.on
                        ? t("admin_features.on", "On")
                        : t("admin_features.off", "Off")}
                    </Badge>
                    <span className="text-muted-foreground">
                      {t(
                        `admin_features.reason_${feature.reason}`,
                        REASON_FALLBACK[feature.reason],
                      )}
                    </span>
                  </span>,
                  <span key="e" className="text-sm text-muted-foreground">
                    {exception(feature)}
                  </span>,
                ],
                actions:
                  readOnly || !feature.overridable ? undefined : (
                    <div className="flex justify-end gap-2">
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setSetting(feature)}
                        aria-label={t(
                          "admin_features.set_exception_aria",
                          "Set an exception for :feature",
                          { feature: label(feature) },
                        )}
                      >
                        {active
                          ? t("admin_features.change", "Change")
                          : t("admin_features.set_exception", "Set exception")}
                      </Button>
                      {active && (
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setRemoving(feature)}
                          aria-label={t(
                            "admin_features.remove_exception_aria",
                            "Remove the exception for :feature",
                            { feature: label(feature) },
                          )}
                        >
                          {t("admin_features.remove", "Remove")}
                        </Button>
                      )}
                    </div>
                  ),
              };
            })}
          />
        )}
      </CardContent>

      {setting && (
        <SetExceptionDialog
          publicId={publicId}
          name={name}
          feature={setting}
          label={label(setting)}
          onClose={() => setSetting(null)}
        />
      )}
      {removing && (
        <RemoveExceptionDialog
          publicId={publicId}
          name={name}
          feature={removing}
          label={label(removing)}
          onClose={() => setRemoving(null)}
        />
      )}
    </Card>
  );
}

function SetExceptionDialog({
  publicId,
  name,
  feature,
  label,
  onClose,
}: {
  publicId: string;
  name: string;
  feature: TenantFeature;
  label: string;
  onClose: () => void;
}) {
  const { t } = useT();
  const set = useSetTenantFeatureOverride(publicId);
  const modeId = useId();
  const untilId = useId();
  const current =
    feature.override !== null && !feature.override.expired
      ? feature.override
      : null;
  const [mode, setMode] = useState<FeatureOverrideMode>(
    current?.mode ?? (feature.on ? "revoke" : "grant"),
  );
  const [until, setUntil] = useState<string>(
    current
      ? (current.expires_at?.slice(0, 10) ?? "")
      : daysFromNow(DEFAULT_GRANT_DAYS),
  );
  const [errors, setErrors] = useState<Record<string, string>>({});

  function submit(reason: string) {
    setErrors({});
    set.mutate(
      {
        key: feature.key,
        mode,
        reason,
        // The end of the chosen day in Ethiopia, which keeps UTC+3 all year.
        expires_at: until === "" ? null : `${until}T23:59:59+03:00`,
      },
      {
        onSuccess: () => {
          toast.success(t("admin_features.exception_saved", "Exception saved"));
          onClose();
        },
        onError: (err: unknown) => {
          const fields = fieldErrorsOf(err);
          if (Object.keys(fields).length > 0) {
            setErrors(fields);
            return;
          }
          toast.error(
            apiErrorDetail(err) ??
              t("admin_features.save_failed", "Could not change the feature"),
          );
        },
      },
    );
  }

  return (
    <FeatureReasonDialog
      open
      onOpenChange={(open) => !open && onClose()}
      title={t("admin_features.exception_title", ":feature for :name", {
        feature: label,
        name,
      })}
      description={t(
        "admin_features.exception_description",
        "An exception applies to this organisation only. A platform Disable still turns the feature off.",
      )}
      consequence={
        mode === "grant"
          ? t(
              "admin_features.consequence_grant",
              ":name can use :feature whatever its plan says.",
              { name, feature: label },
            )
          : t(
              "admin_features.consequence_revoke",
              ":name cannot use :feature, even if its plan includes it. Its records stay readable.",
              { name, feature: label },
            )
      }
      confirmLabel={t("admin_features.save_exception", "Save exception")}
      destructive={mode === "revoke"}
      pending={set.isPending}
      errors={errors}
      onSubmit={submit}
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor={modeId}>
            {t("admin_features.mode", "Exception")}
          </Label>
          <Select
            value={mode}
            onValueChange={(value) => setMode(value as FeatureOverrideMode)}
          >
            <SelectTrigger id={modeId}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="grant">
                {t("admin_features.mode_grant", "Granted")}
              </SelectItem>
              <SelectItem value="revoke">
                {t("admin_features.mode_revoke", "Revoked")}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={untilId}>
            {t("admin_features.until", "Ends on")}
          </Label>
          <Input
            id={untilId}
            type="date"
            min={daysFromNow(1)}
            value={until}
            onChange={(e) => setUntil(e.target.value)}
            aria-invalid={errors.expires_at ? true : undefined}
          />
          <p className="text-xs text-muted-foreground">
            {t("admin_features.until_hint", "Leave empty for no end date.")}
          </p>
          {errors.expires_at && (
            <p className="text-xs text-destructive">{errors.expires_at}</p>
          )}
        </div>
      </div>
    </FeatureReasonDialog>
  );
}

function RemoveExceptionDialog({
  publicId,
  name,
  feature,
  label,
  onClose,
}: {
  publicId: string;
  name: string;
  feature: TenantFeature;
  label: string;
  onClose: () => void;
}) {
  const { t } = useT();
  const remove = useRemoveTenantFeatureOverride(publicId);
  const [errors, setErrors] = useState<Record<string, string>>({});

  function submit(reason: string) {
    setErrors({});
    remove.mutate(
      { key: feature.key, reason },
      {
        onSuccess: () => {
          toast.success(
            t("admin_features.exception_removed", "Exception removed"),
          );
          onClose();
        },
        onError: (err: unknown) => {
          const fields = fieldErrorsOf(err);
          if (Object.keys(fields).length > 0) {
            setErrors(fields);
            return;
          }
          toast.error(
            apiErrorDetail(err) ??
              t("admin_features.save_failed", "Could not change the feature"),
          );
        },
      },
    );
  }

  return (
    <FeatureReasonDialog
      open
      onOpenChange={(open) => !open && onClose()}
      title={t(
        "admin_features.remove_title",
        "Remove the exception for :feature?",
        { feature: label },
      )}
      description={t(
        "admin_features.remove_description",
        ":name goes back to what its plan and the platform state allow.",
        { name },
      )}
      confirmLabel={t("admin_features.remove", "Remove")}
      pending={remove.isPending}
      errors={errors}
      onSubmit={submit}
    />
  );
}
