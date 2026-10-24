"use client";

import { Building2, CheckCircle2, type LucideIcon } from "lucide-react";
import type { SiteContent } from "@/features/marketing/api";
import { useCountUp } from "@/lib/hooks/useCountUp";
import { useT } from "@/lib/i18n/useT";

/**
 * Headline figures, only those the platform operator has published.
 *
 * Null is not zero: it means no claim is made, and the band is not drawn at
 * all rather than rendering a placeholder. The figures count up once after
 * hydration; the prerendered HTML carries the final values.
 */
export function MetricsBand({ site }: { site: SiteContent }) {
  const { t } = useT();

  const figures = [
    site.metric_organisations !== null
      ? {
          key: "organizations",
          Icon: Building2,
          value: site.metric_organisations,
          label: t("marketing.social_proof.organizations", "Organizations"),
        }
      : null,
    site.metric_employees !== null
      ? {
          key: "employees_managed",
          Icon: CheckCircle2,
          value: site.metric_employees,
          label: t(
            "marketing.social_proof.employees_managed",
            "Employees Managed",
          ),
        }
      : null,
  ].filter((m): m is NonNullable<typeof m> => m !== null);

  if (figures.length === 0) return null;

  return (
    <section className="border-y border-border/50 bg-muted/30">
      <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div
          className={
            figures.length === 1
              ? "grid grid-cols-1 gap-8"
              : "grid grid-cols-2 gap-8"
          }
        >
          {figures.map((figure) => (
            <Figure
              key={figure.key}
              Icon={figure.Icon}
              value={figure.value}
              label={figure.label}
            />
          ))}
        </div>
      </div>
    </section>
  );
}

function Figure({
  Icon,
  value,
  label,
}: {
  Icon: LucideIcon;
  value: number;
  label: string;
}) {
  const shown = useCountUp(value);

  return (
    <div className="text-center">
      <div className="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
        <Icon className="h-5 w-5 text-primary" aria-hidden="true" />
      </div>
      <p className="text-2xl font-semibold tabular-nums tracking-tight text-foreground sm:text-3xl">
        {new Intl.NumberFormat("en-ET").format(shown)}
      </p>
      <p className="mt-1 text-sm text-muted-foreground">{label}</p>
    </div>
  );
}
