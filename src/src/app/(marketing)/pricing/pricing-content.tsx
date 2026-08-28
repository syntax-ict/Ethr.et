"use client";

import Link from "next/link";
import { Check, ChevronDown } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { useT } from "@/lib/i18n/useT";

const starterFeatureKeys = [
  "up_to_50",
  "5_branches",
  "10_devices",
  "attendance_tracking",
  "basic_payroll",
  "leave_mgmt",
  "email_support",
] as const;

const professionalFeatureKeys = [
  "up_to_200",
  "15_branches",
  "50_devices",
  "advanced_attendance",
  "full_payroll",
  "leave_mgmt",
  "custom_reports",
  "api_access",
  "priority_support",
] as const;

const enterpriseFeatureKeys = [
  "unlimited_employees",
  "unlimited_branches",
  "unlimited_devices",
  "all_professional",
  "custom_integrations",
  "dedicated_support",
  "sla",
  "on_premise",
  "training",
] as const;

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

  const plans = [
    {
      nameKey: "starter",
      priceKey: "marketing.pricing_page.free",
      periodKey: "starter_period",
      descKey: "starter_desc",
      featureKeys: starterFeatureKeys,
      ctaKey: "marketing.pricing.start_trial",
      ctaFallback: "Start Free Trial",
      popular: false,
      href: "/register",
    },
    {
      nameKey: "professional",
      priceKey: null,
      priceText: "2,500",
      periodKey: "professional_period",
      descKey: "professional_desc",
      featureKeys: professionalFeatureKeys,
      ctaKey: "marketing.pricing.start_trial",
      ctaFallback: "Start Free Trial",
      popular: true,
      href: "/register",
    },
    {
      nameKey: "enterprise",
      priceKey: "marketing.pricing_page.custom",
      periodKey: "enterprise_period",
      descKey: "enterprise_desc",
      featureKeys: enterpriseFeatureKeys,
      ctaKey: "marketing.pricing.contact_sales",
      ctaFallback: "Contact Sales",
      popular: false,
      href: "/contact",
    },
  ];

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
            {plans.map((plan) => (
              <div
                key={plan.nameKey}
                className={`relative flex flex-col rounded-2xl border bg-card transition-shadow ${
                  plan.popular
                    ? "border-primary shadow-lg ring-1 ring-primary/20"
                    : "border-border/60 shadow-sm hover:shadow-md"
                }`}
              >
                {plan.popular && (
                  <div className="absolute -top-3.5 left-1/2 -translate-x-1/2">
                    <Badge className="px-4 py-1 text-xs shadow-sm">
                      {t("marketing.pricing.most_popular", "Most Popular")}
                    </Badge>
                  </div>
                )}

                <div className="flex flex-1 flex-col p-6 sm:p-8">
                  {/* Plan header */}
                  <div>
                    <h3 className="text-lg font-semibold text-foreground">
                      {t(`marketing.pricing_page.${plan.nameKey}`)}
                    </h3>
                    <p className="mt-1 text-sm text-muted-foreground">
                      {t(`marketing.pricing_page.${plan.descKey}`)}
                    </p>
                    <div className="mt-6 flex items-baseline gap-1">
                      <span className="text-4xl font-bold tracking-tight text-foreground">
                        {plan.priceKey ? t(plan.priceKey) : plan.priceText}
                      </span>
                      <span className="text-sm text-muted-foreground">
                        {t(`marketing.pricing_page.${plan.periodKey}`)}
                      </span>
                    </div>
                  </div>

                  {/* Divider */}
                  <div className="my-6 h-px bg-border" />

                  {/* Features */}
                  <ul className="flex-1 space-y-3">
                    {plan.featureKeys.map((fk) => (
                      <li key={fk} className="flex items-start gap-3">
                        <Check className="mt-0.5 h-4 w-4 shrink-0 text-status-success" />
                        <span className="text-sm text-muted-foreground">
                          {t(`marketing.pricing_page.${fk}`)}
                        </span>
                      </li>
                    ))}
                  </ul>

                  {/* CTA */}
                  <div className="mt-8">
                    <Button
                      className={`w-full h-11 ${plan.popular ? "shadow-sm" : ""}`}
                      variant={plan.popular ? "default" : "outline"}
                      asChild
                    >
                      <Link href={plan.href}>
                        {t(plan.ctaKey, plan.ctaFallback)}
                      </Link>
                    </Button>
                  </div>
                </div>
              </div>
            ))}
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
