"use client";

import Link from "next/link";
import {
  ArrowRight,
  Banknote,
  CalendarDays,
  CalendarRange,
  CheckCircle2,
  Clock,
  FileText,
  Languages,
  ListChecks,
  MapPin,
  Percent,
  QrCode,
  ShieldCheck,
  Sun,
  WifiOff,
  type LucideIcon,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";
import { useLocaleHref } from "@/lib/i18n/route-locale";
import {
  SOLUTIONS,
  solutionFor,
  type SolutionId,
} from "@/components/marketing/solutions";

const ICONS: Record<string, LucideIcon> = {
  tax: Percent,
  pension: ShieldCheck,
  deductions: Banknote,
  payslips: FileText,
  explained: ListChecks,
  methods: QrCode,
  location: MapPin,
  offline: WifiOff,
  shifts: Clock,
  integrity: ShieldCheck,
  types: ListChecks,
  balances: CalendarRange,
  requests: CheckCircle2,
  holidays: Sun,
  dual: CalendarDays,
  pagumen: CalendarRange,
  bilingual: Languages,
};

/**
 * One search-intent page: a keyword headline, what ETHR does for that need,
 * the other three needs, and the way in. Every such page is this template
 * with its own copy (`SOLUTIONS`), so they cannot drift into four designs.
 */
export function SolutionPage({ id }: { id: SolutionId }) {
  const { t } = useT();
  const href = useLocaleHref();
  const solution = solutionFor(id);
  const others = SOLUTIONS.filter((s) => s.id !== id);
  const k = (suffix: string) => `marketing.solutions.${id}.${suffix}`;

  return (
    <div>
      <section className="relative overflow-hidden border-b border-border/50 bg-muted/20">
        <div className="pointer-events-none absolute inset-0 -z-10">
          <div className="absolute left-1/2 top-0 h-[400px] w-[600px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-primary/[0.04] blur-3xl" />
        </div>
        <div className="mx-auto max-w-7xl px-4 py-20 sm:px-6 sm:py-24 lg:px-8">
          <div className="mx-auto max-w-3xl text-center">
            <p className="text-sm font-semibold uppercase tracking-wider text-primary">
              {t(k("overline"))}
            </p>
            <h1 className="mt-2 text-4xl font-bold tracking-tight text-foreground sm:text-5xl">
              {t(k("title"))}
            </h1>
            <p className="mt-6 text-lg leading-relaxed text-muted-foreground">
              {t(k("subtitle"))}
            </p>
            <div className="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
              <Button size="lg" className="h-12 px-8 shadow-sm" asChild>
                <Link href="/register">
                  {t("marketing.hero.cta", "Start Free Trial")}
                  <ArrowRight className="ml-2 h-4 w-4" />
                </Link>
              </Button>
              <Button size="lg" variant="outline" className="h-12 px-8" asChild>
                <Link href={href("/pricing")}>
                  {t("marketing.solutions.see_pricing", "See pricing")}
                </Link>
              </Button>
            </div>
          </div>
        </div>
      </section>

      <section className="py-20 sm:py-24">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <h2 className="max-w-2xl text-3xl font-bold tracking-tight text-foreground">
            {t(k("points_title"))}
          </h2>
          <div className="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {solution.points.map((point) => {
              const Icon = ICONS[point] ?? CheckCircle2;
              return (
                <div
                  key={point}
                  className="rounded-xl border border-border/60 bg-card p-6"
                >
                  <div className="flex h-11 w-11 items-center justify-center rounded-lg bg-primary/10">
                    <Icon className="h-5 w-5 text-primary" aria-hidden />
                  </div>
                  <h3 className="mt-4 text-base font-semibold text-foreground">
                    {t(k(point))}
                  </h3>
                  <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                    {t(k(`${point}_desc`))}
                  </p>
                </div>
              );
            })}
          </div>
        </div>
      </section>

      <section className="border-y border-border/50 bg-muted/20 py-16">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <h2 className="text-xl font-semibold tracking-tight text-foreground">
            {t("marketing.solutions.more_title", "Also in ETHR")}
          </h2>
          <ul className="mt-6 grid gap-3 sm:grid-cols-3">
            {others.map((other) => (
              <li key={other.id}>
                <Link
                  href={href(other.path)}
                  className="group flex items-center justify-between rounded-lg border border-border/60 bg-card px-4 py-3 text-sm font-medium text-foreground transition-colors hover:border-border"
                >
                  {t(`marketing.solutions.${other.id}.link`)}
                  <ArrowRight className="h-4 w-4 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                </Link>
              </li>
            ))}
          </ul>
        </div>
      </section>

      <section>
        <div className="mx-auto max-w-7xl px-4 py-20 sm:px-6 sm:py-24 lg:px-8">
          <div className="mx-auto max-w-2xl rounded-2xl bg-muted/40 p-8 text-center sm:p-12">
            <h2 className="text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
              {t("marketing.features_page.cta_title", "Ready to get started?")}
            </h2>
            <p className="mt-3 text-muted-foreground">
              {t(
                "marketing.features_page.cta_subtitle",
                "Set up your organization in under 5 minutes",
              )}
            </p>
            <div className="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
              <Button size="lg" className="h-12 px-8 shadow-sm" asChild>
                <Link href="/register">
                  {t("marketing.hero.cta", "Start Free Trial")}
                  <ArrowRight className="ml-2 h-4 w-4" />
                </Link>
              </Button>
              <Button size="lg" variant="outline" className="h-12 px-8" asChild>
                <Link href={href("/contact")}>
                  {t("marketing.cta.talk_to_sales", "Talk to Sales")}
                </Link>
              </Button>
            </div>
          </div>
        </div>
      </section>
    </div>
  );
}
