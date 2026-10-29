"use client";

import { useId, useState } from "react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { RoleGate } from "@/components/shared/role-gate";
import { SimpleTable } from "@/components/shared/simple-table";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  FeatureReasonDialog,
  fieldErrorsOf,
} from "@/features/admin/components/feature-reason-dialog";
import {
  FEATURE_LABEL_FALLBACK,
  usePlatformFeatures,
  useUpdateFeatureState,
  type FeatureState,
  type PlatformFeature,
} from "@/features/admin/features";
import { apiErrorDetail } from "@/lib/api/error-message";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";

/**
 * Hidden is left out until the screen reads `visible` (design §9, PR 4):
 * offered now, it would change nothing an organisation sees.
 */
const OFFERED_STATES: readonly FeatureState[] = [
  "enabled",
  "restricted",
  "disabled",
];

const STATE_FALLBACK: Record<FeatureState, string> = {
  enabled: "Enabled",
  hidden: "Hidden",
  restricted: "Restricted",
  disabled: "Disabled",
};

const STATE_BADGE: Record<
  FeatureState,
  "success" | "neutral" | "warning" | "destructive"
> = {
  enabled: "success",
  hidden: "neutral",
  restricted: "warning",
  disabled: "destructive",
};

const CONSEQUENCE_FALLBACK: Record<FeatureState, string> = {
  enabled: "Every organisation uses :feature as its plan allows.",
  hidden:
    "Taken off every organisation's screen. The API keeps working, so hiding never stops anything.",
  restricted:
    "Off for every organisation except those you grant it to on their page.",
  disabled:
    "Off for every organisation, grants included. Their records stay readable, and work already started finishes.",
};

/**
 * The platform feature switches: one state per feature for every
 * organisation, without a deploy (docs/FEATURE-CONTROL-CENTER-DESIGN.md §7).
 * One organisation's grant or revoke is set on its own page in Organisations.
 */
export default function FeatureControlsPage() {
  const { t } = useT();
  const query = usePlatformFeatures();
  const [editing, setEditing] = useState<PlatformFeature | null>(null);

  return (
    <RoleGate minRole="super_admin">
      <div className="space-y-6">
        <Card>
          <CardContent className="space-y-2 pt-6 text-sm text-muted-foreground">
            <p>
              {t(
                "admin_features.intro",
                "A change applies to every organisation on its next request. A grant or revoke for one organisation is set on its page in Organisations, and a platform Disable overrides it.",
              )}
            </p>
            <p>
              {t(
                "admin_features.intro_new",
                "Only features listed here can be switched. A new feature joins this list in the release that adds it.",
              )}
            </p>
          </CardContent>
        </Card>

        <QueryBoundary
          query={query}
          loading={<Skeleton className="h-96 w-full" />}
          isEmpty={() => false}
        >
          {(features) => (
            <Card>
              <CardHeader>
                <CardTitle className="text-base">
                  {t("admin_features.title", "Features")}
                </CardTitle>
              </CardHeader>
              <CardContent className="p-0">
                <FeatureTable features={features} onChange={setEditing} />
              </CardContent>
            </Card>
          )}
        </QueryBoundary>

        {editing && (
          <ChangeStateDialog
            feature={editing}
            onClose={() => setEditing(null)}
          />
        )}
      </div>
    </RoleGate>
  );
}

function FeatureTable({
  features,
  onChange,
}: {
  features: PlatformFeature[];
  onChange: (feature: PlatformFeature) => void;
}) {
  const { t } = useT();
  const { timeAgo } = useDateFormatters();

  return (
    <SimpleTable
      caption={t("admin_features.title", "Features")}
      headers={[
        t("admin_features.feature", "Feature"),
        t("admin_features.state", "State"),
        t("admin_features.overrides", "Organisation exceptions"),
        t("admin_features.last_change", "Last change"),
        "",
      ]}
      rows={features.map((feature) => ({
        key: feature.key,
        cells: [
          <span key="f" className="font-medium text-foreground">
            {t(
              `admin_plans.feature.${feature.key}`,
              FEATURE_LABEL_FALLBACK[feature.key],
            )}
          </span>,
          <Badge key="s" variant={STATE_BADGE[feature.state]}>
            {t(
              `admin_features.state_${feature.state}`,
              STATE_FALLBACK[feature.state],
            )}
          </Badge>,
          <span key="o" className="text-sm text-muted-foreground">
            {feature.grants + feature.revokes === 0
              ? t("admin_features.no_overrides", "None")
              : t(
                  "admin_features.override_counts",
                  ":grants granted, :revokes revoked",
                  { grants: feature.grants, revokes: feature.revokes },
                )}
          </span>,
          <span key="c" className="text-sm text-muted-foreground">
            {feature.changed_at === null
              ? t("admin_features.never_changed", "Never changed")
              : t("admin_features.changed", ":when: :reason", {
                  when: timeAgo(feature.changed_at),
                  reason: feature.reason ?? "",
                })}
          </span>,
        ],
        actions: (
          <Button
            variant="outline"
            size="sm"
            onClick={() => onChange(feature)}
            aria-label={t("admin_features.change_aria", "Change :feature", {
              feature: t(
                `admin_plans.feature.${feature.key}`,
                FEATURE_LABEL_FALLBACK[feature.key],
              ),
            })}
          >
            {t("admin_features.change", "Change")}
          </Button>
        ),
      }))}
    />
  );
}

function ChangeStateDialog({
  feature,
  onClose,
}: {
  feature: PlatformFeature;
  onClose: () => void;
}) {
  const { t } = useT();
  const update = useUpdateFeatureState();
  const stateId = useId();
  const [state, setState] = useState<FeatureState>(feature.state);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const label = t(
    `admin_plans.feature.${feature.key}`,
    FEATURE_LABEL_FALLBACK[feature.key],
  );

  function submit(reason: string) {
    setErrors({});
    update.mutate(
      { key: feature.key, state, reason },
      {
        onSuccess: () => {
          toast.success(
            t("admin_features.saved", ":feature is now :state.", {
              feature: label,
              state: t(`admin_features.state_${state}`, STATE_FALLBACK[state]),
            }),
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
      title={t("admin_features.change_title", "Change :feature", {
        feature: label,
      })}
      description={t(
        "admin_features.change_description",
        "The new state applies to every organisation on its next request.",
      )}
      consequence={t(
        `admin_features.consequence_${state}`,
        CONSEQUENCE_FALLBACK[state],
        { feature: label },
      )}
      confirmLabel={t("admin_features.confirm", "Change state")}
      destructive={state === "disabled" || state === "restricted"}
      pending={update.isPending}
      errors={errors}
      onSubmit={submit}
    >
      <div className="space-y-1.5">
        <Label htmlFor={stateId}>{t("admin_features.state", "State")}</Label>
        <Select
          value={state}
          onValueChange={(value) => setState(value as FeatureState)}
        >
          <SelectTrigger id={stateId}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {OFFERED_STATES.map((option) => (
              <SelectItem key={option} value={option}>
                {t(`admin_features.state_${option}`, STATE_FALLBACK[option])}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        {errors.state && (
          <p className="text-xs text-destructive">{errors.state}</p>
        )}
      </div>
    </FeatureReasonDialog>
  );
}
