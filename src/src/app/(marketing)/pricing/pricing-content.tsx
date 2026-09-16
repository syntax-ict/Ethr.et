"use client";

import Link from "next/link";
import { Check, ChevronDown } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";
import { usePlans, type Plan } from "@/features/billing/api";
import {
  PLANS_SNAPSHOT,
  PLANS_SNAPSHOT_GENERATED_AT,
  isUnlimited,
  publicPlans,
} from "@/lib/marketing/plans";

/**
 * Every price, limit and capability on this page comes from the `plans` table.
 *
 * It used to come from three hardcoded arrays and a `priceText: "2,500"`
 * literal, and every one of those numbers was wrong: the catalog says
 * Professional is 999 ETB with 100 employees / 5 branches / 20 devices, while
 * the page advertised 2,500 ETB with 200 / 15 / 50. The limits are the ones
 * `PlanLimitService` enforces, so the site was promising five times what the
 * product would honour — and `BillingService` bills `price_cents`, so the
 * advertised price was not the charged one either.
 *
 * `GET /api/v1/plans` was already public, already typed in `generated.ts` and
 * already wrapped by `usePlans()`. Nothing needed building; the page simply
 * never connected to it.
 *
 * WHAT STILL HAS NO DATABASE HOME, and is therefore derived here rather than
 * invented:
 *
 *  - Plan *display names and descriptions* stay in i18n, keyed by slug, because
 *    `plans` has no `name_am`/`description` column — a DB-supplied name would
 *    render English inside an Amharic page. An unknown slug falls back to the
 *    catalog's own `name`, so a plan added by an admin still renders.
 *  - "Most popular" has no `is_popular` column, so it is not shown at all
 *    rather than hardcoded back onto a slug. Adding the column restores it.
 *  - Marketing-only bullets the old page carried ("priority support", "SLA",
 *    "on-premise", "training") have no column either. They are dropped rather
 *    than kept as copy the catalog cannot back.
 */

/** Maps a `PlanFeature` enum value to its sales-facing line. */
function featureLabel(t: ReturnType<typeof useT>["t"], value: string): string {
  return t(
    `marketing.pricing_page.feature_${value}`,
    // A capability added to the enum without a translation should still read as
    // words on a public page rather than as a raw snake_case key.
    value.replace(/_/g, " "),
  );
}

const faqKeys = [
  "trial",
  "upgrade",
  "offline",
  "security",
  "calendar",
  "hosting",
] as const;

export function PricingContent() {
  const { t } = useT();

  // `initialData` is the committed build-time snapshot, so the prerendered HTML
  // and the first client paint both carry real prices — there is no loading
  // state and nothing for a crawler to miss. The live catalog replaces it as
  // soon as the fetch resolves.
  const { data } = usePlans({
    initialData: PLANS_SNAPSHOT,
    initialDataUpdatedAt: PLANS_SNAPSHOT_GENERATED_AT,
  });

  // A failed fetch or an unseeded database must not blank the pricing page.
  const plans = publicPlans(
    data?.data?.length ? data.data : PLANS_SNAPSHOT.data,
  );

  function priceParts(plan: Plan): { amount: string; period: string } {
    if (plan.price_cents === 0) {
      return {
        amount: t("marketing.pricing_page.free", "Free"),
        period: t("marketing.pricing_page.starter_period", "6-month trial"),
      };
    }
    return {
      // Whole birr: the catalog stores cents, but no plan is priced in them and
      // "999.00" reads as a decimal the buyer has to parse. formatETB is kept
      // for invoices, where the cents are real.
      amount: new Intl.NumberFormat("en-ET", {
        maximumFractionDigits: 0,
      }).format(plan.price_cents / 100),
      period: t("marketing.pricing_page.professional_period", "ETB/month"),
    };
  }

  /** The three limit columns, rendered as bullets with the number interpolated. */
  function limitLines(plan: Plan): string[] {
    const limits: Array<[number | null, string, string, string]> = [
      [
        plan.max_employees,
        "employees",
        "Up to :count employees",
        "Unlimited employees",
      ],
      [
        plan.max_branches,
        "branches",
        "Up to :count branches",
        "Unlimited branches",
      ],
      [
        plan.max_devices,
        "devices",
        "Up to :count devices",
        "Unlimited devices",
      ],
    ];

    return limits.map(([value, noun, capped, uncapped]) => {
      if (isUnlimited(value)) {
        return t(`marketing.pricing_page.limit_${noun}_unlimited`, uncapped);
      }
      // "Up to 1 branches" — Starter has exactly one branch, so the plural
      // reads as a bug on the first card a visitor sees. The i18n layer does
      // interpolation but not pluralisation, so the singular is its own key.
      if (value === 1) {
        return t(`marketing.pricing_page.limit_${noun}_one`, `1 ${noun}`);
      }
      return t(`marketing.pricing_page.limit_${noun}`, capped, {
        count: new Intl.NumberFormat("en-ET").format(value ?? 0),
      });
    });
  }

  return (
    <div>
      {/* Hero */}
      <section className="relative overflow-hidden border-b border-border/50 bg-muted/20">
        <div className="pointer-events-none absolute inset-0 -z-10">
          <div className="absolute left-1/2 top-0 h-[400px] w-[600px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-primary/[0.04] blur-3xl" />
        </div>
        <div className="mx-auto max-w-7xl px-4 py-20 sm:px-6 sm:py-24 lg:px-8">
          <div className="mx-auto max-w-2xl text-center">
            <p className="text-sm font-semibold uppercase tracking-wider text-primary">
              {t("marketing.pricing.overline", "Pricing")}
            </p>
            <h1 className="mt-2 text-4xl font-bold tracking-tight text-foreground sm:text-5xl">
              {t("marketing.pricing.title", "Simple, Transparent Pricing")}
            </h1>
            <p className="mt-6 text-lg text-muted-foreground">
              {t(
                "marketing.pricing.subtitle",
                "Start free, upgrade when you grow",
              )}
            </p>
          </div>
        </div>
      </section>

      {/* Plans */}
      <section className="py-20 sm:py-24">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="grid gap-6 lg:grid-cols-3 lg:gap-8">
            {plans.map((plan) => {
              const { amount, period } = priceParts(plan);
              return (
                <div
                  key={plan.public_id}
                  className="relative flex flex-col rounded-2xl border border-border/60 bg-card shadow-sm transition-shadow hover:shadow-md"
                >
                  <div className="flex flex-1 flex-col p-6 sm:p-8">
                    {/* Plan header */}
                    <div>
                      <h3 className="text-lg font-semibold text-foreground">
                        {/* i18n by slug, catalog name as the fallback, so a plan
                            an admin adds still renders — in English, until
                            `plans` grows a `name_am`. */}
                        {t(`marketing.pricing_page.${plan.slug}`, plan.name)}
                      </h3>
                      <p className="mt-1 text-sm text-muted-foreground">
                        {t(`marketing.pricing_page.${plan.slug}_desc`, "")}
                      </p>
                      <div className="mt-6 flex items-baseline gap-1">
                        <span className="text-4xl font-bold tracking-tight text-foreground">
                          {amount}
                        </span>
                        <span className="text-sm text-muted-foreground">
                          {period}
                        </span>
                      </div>
                    </div>

                    {/* Divider */}
                    <div className="my-6 h-px bg-border" />

                    {/* Limits, then capabilities — both straight from the row */}
                    <ul className="flex-1 space-y-3">
                      {limitLines(plan).map((line) => (
                        <li key={line} className="flex items-start gap-3">
                          <Check className="mt-0.5 h-4 w-4 shrink-0 text-status-success" />
                          <span className="text-sm text-muted-foreground">
                            {line}
                          </span>
                        </li>
                      ))}
                      {(plan.features ?? []).map((feature) => (
                        <li key={feature} className="flex items-start gap-3">
                          <Check className="mt-0.5 h-4 w-4 shrink-0 text-status-success" />
                          <span className="text-sm text-muted-foreground">
                            {featureLabel(t, feature)}
                          </span>
                        </li>
                      ))}
                    </ul>

                    {/* CTA — every plan starts the same trial, because
                        AuthService puts every new tenant on one regardless of
                        plan, so routing the top tier to /contact would be a
                        claim the code does not make. */}
                    <div className="mt-8">
                      <Button className="h-11 w-full" variant="outline" asChild>
                        <Link href="/register">
                          {t(
                            "marketing.pricing.start_trial",
                            "Start Free Trial",
                          )}
                        </Link>
                      </Button>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      </section>

      {/* FAQ */}
      <section className="border-t border-border/50 bg-muted/20 py-20 sm:py-24">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="mx-auto max-w-2xl text-center">
            <h2 className="text-3xl font-bold tracking-tight text-foreground">
              {t("marketing.pricing.faq_title", "Frequently Asked Questions")}
            </h2>
          </div>

          <div className="mx-auto mt-12 max-w-3xl divide-y divide-border">
            {faqKeys.map((fk) => (
              <details key={fk} className="group">
                <summary className="flex cursor-pointer items-center justify-between gap-4 py-5 text-sm font-medium text-foreground transition-colors hover:text-primary [&::-webkit-details-marker]:hidden">
                  <span>{t(`marketing.pricing_page.faq_${fk}_q`)}</span>
                  <ChevronDown className="h-4 w-4 shrink-0 text-muted-foreground transition-transform duration-200 group-open:rotate-180" />
                </summary>
                <p className="pb-5 pr-8 text-sm leading-relaxed text-muted-foreground">
                  {t(`marketing.pricing_page.faq_${fk}_a`)}
                </p>
              </details>
            ))}
          </div>
        </div>
      </section>
    </div>
  );
}
