"use client";

import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";
import {
  JOURNEY_STAGES,
  useTenantJourneySummary,
  type JourneyStage,
} from "@/features/admin/journey";
import { stageLabel } from "@/features/admin/components/journey-labels";

/**
 * How many organisations sit at each stage between sign-up and using ETHR.
 * Each tile filters the organisations list below it to that stage; pressing
 * the pressed tile clears the filter.
 */
export function JourneyFunnel({
  stage,
  onStage,
}: {
  stage: JourneyStage | null;
  onStage: (stage: JourneyStage | null) => void;
}) {
  const { t } = useT();
  const { data, isLoading, isError } = useTenantJourneySummary();

  if (isError) return null;

  return (
    <Card>
      <CardContent className="space-y-3 p-4">
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <h2 className="text-sm font-semibold text-foreground">
            {t("admin_tenant_journey.funnel_title", "Where organisations are")}
          </h2>
          {data && data.not_computed > 0 && (
            <p className="text-xs text-muted-foreground">
              {t(
                "admin_tenant_journey.funnel_pending",
                "Not measured yet: :count",
                { count: data.not_computed },
              )}
            </p>
          )}
        </div>
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6">
          {JOURNEY_STAGES.map((key) => {
            const count = data?.stages.find((s) => s.stage === key)?.count;
            const pressed = stage === key;
            return (
              <button
                key={key}
                type="button"
                aria-pressed={pressed}
                onClick={() => onStage(pressed ? null : key)}
                className={cn(
                  "rounded-lg border px-3 py-2 text-left transition-colors",
                  "hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                  pressed && "border-primary bg-primary-soft",
                )}
              >
                <span className="block text-xs text-muted-foreground">
                  {stageLabel(t, key)}
                </span>
                {isLoading || count === undefined ? (
                  <Skeleton className="mt-1 h-6 w-8" />
                ) : (
                  <span className="block text-xl font-semibold tabular-nums text-foreground">
                    {count}
                  </span>
                )}
              </button>
            );
          })}
        </div>
        {data && (
          <p className="text-xs text-muted-foreground">
            {t(
              "admin_tenant_journey.funnel_health",
              "Need action: :critical. Need a look: :warning",
              { critical: data.health.critical, warning: data.health.warning },
            )}
          </p>
        )}
      </CardContent>
    </Card>
  );
}
