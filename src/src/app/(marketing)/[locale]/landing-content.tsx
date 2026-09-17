"use client";

import Link from "next/link";
import {
  Clock,
  Wallet,
  CalendarDays,
  WifiOff,
  Building2,
  Languages,
  ArrowRight,
  Landmark,
  Hospital,
  Factory,
  GraduationCap,
  Hotel,
  Heart,
  Briefcase,
  Shield,
  CheckCircle2,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { ProductFlow } from "@/components/marketing/product-flow";
import { useT } from "@/lib/i18n/useT";
import { useLocaleHref } from "@/lib/i18n/route-locale";
import { pickLocalised, useSiteContent } from "@/features/marketing/api";

const featureIcons = [
  Clock,
  Wallet,
  CalendarDays,
  WifiOff,
  Building2,
  Languages,
];
const featureKeys = [
  "attendance",
  "payroll",
  "leave",
  "offline",
  "multi_tenant",
  "bilingual",
] as const;

const industryData = [
  { icon: Landmark, key: "government" },
  { icon: Landmark, key: "banking" },
  { icon: Hospital, key: "healthcare" },
  { icon: Factory, key: "manufacturing" },
  { icon: Heart, key: "ngo" },
  { icon: Hotel, key: "hospitality" },
  { icon: GraduationCap, key: "education" },
  { icon: Briefcase, key: "general" },
];

export function LandingContent() {
  const { t, locale } = useT();
  const href = useLocaleHref();

  // Headline figures come from platform_settings and start empty. Every one of
  // them is a claim, and the page must be able to make none.
  const site = useSiteContent();

  // Only figures an operator has actually published. Null is not zero: it means
  // no claim is being made, and the band vanishes rather than rendering a
  // placeholder.
  const publishedMetrics = [
    site.metric_organisations !== null
      ? {
          key: "organizations",
          Icon: Building2,
          value: new Intl.NumberFormat("en-ET").format(
            site.metric_organisations,
          ),
          label: t("marketing.social_proof.organizations", "Organizations"),
        }
      : null,
    site.metric_employees !== null
      ? {
          key: "employees_managed",
          Icon: CheckCircle2,
          value: new Intl.NumberFormat("en-ET").format(site.metric_employees),
          label: t(
            "marketing.social_proof.employees_managed",
            "Employees Managed",
          ),
        }
      : null,
  ].filter((m): m is NonNullable<typeof m> => m !== null);

  /**
   * The customer quote, if a real customer has given one.
   *
   * `testimonial_quote` is non-null only when the API had an author and a
   * recorded consent date to go with it, so this single check is the whole
   * gate. Nothing renders otherwise — not a placeholder, not a skeleton, not an
   * empty bordered box. The page previously carried an invented quote from
   * "Abebe Kebede, HR Director, Addis Manufacturing PLC", a person who does not
   * exist; saying nothing is the honest amount until someone real agrees.
   *
   * No star rating, deliberately. Five filled stars is a score, and nothing
   * collects one — reinstating them would be inventing a number on top of a
   * quote that is finally true.
   */
  const testimonialQuote = pickLocalised(
    locale,
    site.testimonial_quote_am,
    site.testimonial_quote,
  );
  const testimonialAttribution = [
    pickLocalised(locale, site.testimonial_role_am, site.testimonial_role),
    site.testimonial_organisation,
  ]
    .filter((part): part is string => Boolean(part))
    .join(", ");

  /* No <MarketingHeader>, <main> or <MarketingFooter> here.
     They belong to `(marketing)/[locale]/layout.tsx`, which wraps every public
     page. This component carried its own set because it used to live at
     `app/page.tsx`, outside the marketing route group — and when phase 7 moved
     it inside, the page started rendering two headers, two footers and two
     <main> landmarks. Only the built HTML showed it: `.next/server/app/en.html`
     had `<header` twice where every other public page had it once. Two mains is
     also an a11y defect in its own right, since a screen reader's "skip to main
     content" has two places to go. */
  return (
    <>
      {/* Hero */}
      <section className="relative overflow-hidden">
        <div className="pointer-events-none absolute inset-0 -z-10">
          <div className="absolute inset-0 bg-gradient-to-b from-primary/[0.03] via-transparent to-transparent" />
          <div className="absolute left-1/2 top-0 h-[600px] w-[800px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-primary/[0.04] blur-3xl" />
          <div className="absolute right-0 top-1/3 h-[300px] w-[400px] rounded-full bg-brand-accent/[0.03] blur-3xl" />
        </div>

        <div className="mx-auto max-w-7xl px-4 pb-16 pt-20 sm:px-6 sm:pb-24 sm:pt-28 lg:px-8 lg:pt-32">
          <div className="mx-auto max-w-3xl text-center">
            {/* The badge said "Now serving 500+ Ethiopian organizations".
                  Nobody can substantiate that — ethr.et serves nothing yet, per
                  the external probe in B1-B5_GATE_REPORT.md — so it is rendered
                  only once an operator has published a figure, and the figure
                  comes from the database rather than from this file. An
                  unpublished metric renders no badge at all. */}
            {site.metric_organisations !== null && (
              <div className="mb-6 inline-flex items-center gap-2 rounded-full border border-border bg-background/80 px-4 py-1.5 text-sm text-muted-foreground backdrop-blur-sm">
                <span className="relative flex h-2 w-2">
                  <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-status-success opacity-75" />
                  <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                </span>
                {t(
                  "marketing.hero.badge_count",
                  "Now serving :count Ethiopian organizations",
                  {
                    count: new Intl.NumberFormat("en-ET").format(
                      site.metric_organisations,
                    ),
                  },
                )}
              </div>
            )}

            <h1 className="text-4xl font-bold tracking-tight text-foreground sm:text-5xl lg:text-6xl">
              {t(
                "marketing.hero.title",
                "The Complete HR Platform for Ethiopian Organizations",
              )}
            </h1>
            <p className="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-muted-foreground sm:text-xl">
              {t(
                "marketing.hero.subtitle",
                "Manage employees, attendance, payroll, and leave — offline-first, bilingual, and built for Ethiopian labor law.",
              )}
            </p>
            <div className="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row">
              <Button
                size="lg"
                className="h-12 px-8 text-base shadow-md shadow-primary/20"
                asChild
              >
                <Link href="/register">
                  {t("marketing.hero.cta", "Start Free Trial")}
                  <ArrowRight className="ml-2 h-4 w-4" />
                </Link>
              </Button>
              <Button
                variant="outline"
                size="lg"
                className="h-12 px-8 text-base"
                asChild
              >
                <Link href={href("/features")}>
                  {t("marketing.hero.learn_more", "Learn More")}
                </Link>
              </Button>
            </div>
            <p className="mt-4 text-sm text-muted-foreground">
              {t(
                "marketing.hero.trial_note",
                "6-month free trial · No credit card required",
              )}
            </p>
          </div>

          {/* The hero was text-only: it asserted "offline-first" and asked the
                visitor to take it on faith. This shows the claim instead. */}
          <div className="mt-14 sm:mt-16">
            <ProductFlow />
          </div>
        </div>
      </section>

      {/* Social proof, when there is any.

            This band read "500+ organizations", "50,000+ employees", "1M+
            payrolls processed" and "99.9% uptime". Every one of those was
            written into this file by a developer, and none can be
            substantiated: ethr.et serves nothing yet — B1-B5_GATE_REPORT.md
            records an external probe finding a dormant host with every port
            closed — so there are no organisations, no employees and no
            payrolls, and with nothing deployed there is no uptime to measure.
            The fourth figure was the worst of them, because "99.9%" reads as an
            SLA and the terms page states plainly that no SLA exists.

            They are columns on platform_settings now, editable at
            /admin/platform-settings, and they start empty. An operator
            publishes what they can stand behind; the band renders only those,
            and disappears entirely when there are none. That is what the plan
            means by the fabrications becoming empty rows rather than code. */}
      {publishedMetrics.length > 0 && (
        <section className="border-y border-border/50 bg-muted/30">
          <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <div
              className={
                publishedMetrics.length === 1
                  ? "grid grid-cols-1 gap-8"
                  : "grid grid-cols-2 gap-8"
              }
            >
              {publishedMetrics.map(({ key, value, Icon, label }) => (
                <div key={key} className="text-center">
                  <div className="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                    <Icon className="h-5 w-5 text-primary" />
                  </div>
                  <p className="text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
                    {value}
                  </p>
                  <p className="mt-1 text-sm text-muted-foreground">{label}</p>
                </div>
              ))}
            </div>
          </div>
        </section>
      )}

      {/* Features */}
      <section className="py-20 sm:py-24">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="mx-auto max-w-2xl text-center">
            <p className="text-sm font-semibold uppercase tracking-wider text-primary">
              {t("marketing.features.overline", "Platform")}
            </p>
            <h2 className="mt-2 text-3xl font-bold tracking-tight text-foreground sm:text-4xl">
              {t("marketing.features.title", "Everything You Need")}
            </h2>
            <p className="mt-4 text-lg text-muted-foreground">
              {t(
                "marketing.features.subtitle",
                "One platform to manage your entire workforce",
              )}
            </p>
          </div>
          <div className="mt-16 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {featureKeys.map((key, i) => {
              const Icon = featureIcons[i];
              return (
                <div
                  key={key}
                  className="group relative rounded-xl border border-border/60 bg-card p-6 transition-all duration-200 hover:border-border hover:shadow-md"
                >
                  <div className="flex h-11 w-11 items-center justify-center rounded-lg bg-primary/10 transition-colors group-hover:bg-primary/15">
                    <Icon className="h-5 w-5 text-primary" />
                  </div>
                  <h3 className="mt-4 text-base font-semibold text-foreground">
                    {t(`marketing.features.${key}`)}
                  </h3>
                  <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                    {t(`marketing.features.${key}_desc`)}
                  </p>
                </div>
              );
            })}
          </div>
        </div>
      </section>

      {/* How it works */}
      <section className="border-y border-border/50 bg-muted/20 py-20 sm:py-24">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="mx-auto max-w-2xl text-center">
            <p className="text-sm font-semibold uppercase tracking-wider text-primary">
              {t("marketing.how_it_works.overline", "Get started")}
            </p>
            <h2 className="mt-2 text-3xl font-bold tracking-tight text-foreground sm:text-4xl">
              {t("marketing.how_it_works.title", "Up and Running in Minutes")}
            </h2>
          </div>
          <div className="mx-auto mt-16 grid max-w-4xl gap-8 sm:grid-cols-3">
            {(["register", "configure", "go_live"] as const).map((step, i) => (
              <div key={step} className="relative text-center">
                <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-primary text-lg font-bold text-primary-foreground">
                  {i + 1}
                </div>
                {i < 2 && (
                  <div className="absolute left-[calc(50%+28px)] top-6 hidden h-px w-[calc(100%-56px)] bg-border sm:block" />
                )}
                <h3 className="mt-4 text-base font-semibold text-foreground">
                  {t(`marketing.how_it_works.step_${step}`)}
                </h3>
                <p className="mt-2 text-sm text-muted-foreground">
                  {t(`marketing.how_it_works.step_${step}_desc`)}
                </p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Industries */}
      <section className="py-20 sm:py-24">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="mx-auto max-w-2xl text-center">
            <p className="text-sm font-semibold uppercase tracking-wider text-primary">
              {t("marketing.industries.overline", "Industries")}
            </p>
            <h2 className="mt-2 text-3xl font-bold tracking-tight text-foreground sm:text-4xl">
              {t(
                "marketing.industries.title",
                "Built for Ethiopian Industries",
              )}
            </h2>
            <p className="mt-4 text-lg text-muted-foreground">
              {t(
                "marketing.industries.subtitle",
                "Pre-configured templates for your sector",
              )}
            </p>
          </div>
          <div className="mt-12 grid grid-cols-2 gap-3 sm:grid-cols-4 sm:gap-4">
            {industryData.map((industry) => {
              const Icon = industry.icon;
              return (
                <div
                  key={industry.key}
                  className="group flex flex-col items-center gap-3 rounded-xl border border-border/60 bg-card p-5 text-center transition-all duration-200 hover:border-border hover:shadow-sm sm:p-6"
                >
                  <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary/10 transition-colors group-hover:bg-primary/15">
                    <Icon className="h-5 w-5 text-primary" />
                  </div>
                  <span className="text-sm font-medium text-foreground">
                    {t(`marketing.industries.${industry.key}`)}
                  </span>
                </div>
              );
            })}
          </div>
        </div>
      </section>

      {/* Trust / Security */}
      <section className="border-y border-border/50 bg-muted/20 py-20 sm:py-24">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="grid items-center gap-12 lg:grid-cols-2">
            <div>
              <p className="text-sm font-semibold uppercase tracking-wider text-primary">
                {t("marketing.trust.overline", "Enterprise Security")}
              </p>
              <h2 className="mt-2 text-3xl font-bold tracking-tight text-foreground sm:text-4xl">
                {t("marketing.trust.title", "Your Data, Your Servers")}
              </h2>
              <p className="mt-4 text-lg text-muted-foreground">
                {t(
                  "marketing.trust.subtitle",
                  "ETHR runs on your infrastructure in Ethiopia. No data leaves the country. Full compliance with Ethiopian data protection law.",
                )}
              </p>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              {(
                ["data_residency", "encryption", "isolation", "audit"] as const
              ).map((key) => (
                <div
                  key={key}
                  className="flex items-start gap-3 rounded-lg border border-border/60 bg-card p-4"
                >
                  <Shield className="mt-0.5 h-5 w-5 shrink-0 text-primary" />
                  <div>
                    <p className="text-sm font-medium text-foreground">
                      {t(`marketing.trust.${key}`)}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                      {t(`marketing.trust.${key}_desc`)}
                    </p>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      </section>

      {testimonialQuote && site.testimonial_author && (
        <section className="py-20 sm:py-24">
          <div className="mx-auto max-w-4xl px-4 text-center sm:px-6 lg:px-8">
            <blockquote className="text-xl font-medium leading-relaxed text-foreground sm:text-2xl">
              &ldquo;{testimonialQuote}&rdquo;
            </blockquote>
            <div className="mt-6">
              <p className="font-semibold text-foreground">
                {site.testimonial_author}
              </p>
              {testimonialAttribution && (
                <p className="text-sm text-muted-foreground">
                  {testimonialAttribution}
                </p>
              )}
            </div>
          </div>
        </section>
      )}

      {/* CTA */}
      <section className="relative overflow-hidden border-t">
        <div className="absolute inset-0 -z-10 bg-primary" />
        <div className="absolute inset-0 -z-10 bg-[linear-gradient(to_right,rgba(255,255,255,0.03)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.03)_1px,transparent_1px)] bg-[size:48px_48px]" />
        <div className="mx-auto max-w-7xl px-4 py-20 text-center sm:px-6 sm:py-24 lg:px-8">
          <h2 className="text-3xl font-bold tracking-tight text-primary-foreground sm:text-4xl">
            {t("marketing.cta.title", "Start Your 6-Month Free Trial")}
          </h2>
          <p className="mx-auto mt-4 max-w-xl text-lg text-primary-foreground/80">
            {t(
              "marketing.cta.subtitle",
              "No credit card required. Full access to all features.",
            )}
          </p>
          <div className="mt-8 flex flex-col items-center justify-center gap-4 sm:flex-row">
            <Button
              size="lg"
              variant="secondary"
              className="h-12 px-8 text-base shadow-lg"
              asChild
            >
              <Link href="/register">
                {t("marketing.hero.get_started", "Get Started")}
                <ArrowRight className="ml-2 h-4 w-4" />
              </Link>
            </Button>
            <Button
              size="lg"
              variant="outline"
              className="h-12 border-primary-foreground/20 bg-transparent px-8 text-base text-primary-foreground hover:bg-primary-foreground/10 hover:text-primary-foreground"
              asChild
            >
              <Link href={href("/contact")}>
                {t("marketing.cta.talk_to_sales", "Talk to Sales")}
              </Link>
            </Button>
          </div>
          <div className="mt-8 flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-sm text-primary-foreground/70">
            <span className="flex items-center gap-1.5">
              <CheckCircle2 className="h-4 w-4" />
              {t("marketing.cta.check_1", "6-month free trial")}
            </span>
            <span className="flex items-center gap-1.5">
              <CheckCircle2 className="h-4 w-4" />
              {t("marketing.cta.check_2", "No credit card")}
            </span>
            <span className="flex items-center gap-1.5">
              <CheckCircle2 className="h-4 w-4" />
              {t("marketing.cta.check_3", "Cancel anytime")}
            </span>
          </div>
        </div>
      </section>
    </>
  );
}
