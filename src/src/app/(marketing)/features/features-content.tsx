"use client";

import Link from "next/link";
import {
  Fingerprint,
  MapPin,
  WifiOff,
  Clock,
  ArrowRight,
  Users,
  Shield,
  CalendarDays,
  Wallet,
  FileText,
  BarChart3,
  Languages,
  Smartphone,
  CheckCircle2,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";

const attendanceFeatures = [
  { icon: Fingerprint, key: "biometric" },
  { icon: MapPin, key: "gps" },
  { icon: WifiOff, key: "offline_sync" },
  { icon: Clock, key: "shifts" },
] as const;

const coreModules = [
  { icon: Users, key: "hr" },
  { icon: Wallet, key: "payroll" },
  { icon: CalendarDays, key: "leave" },
  { icon: Shield, key: "security" },
  { icon: FileText, key: "reports" },
  { icon: BarChart3, key: "analytics" },
  { icon: Languages, key: "bilingual" },
  { icon: Smartphone, key: "mobile" },
] as const;

const complianceItems = [
  "compliance_tax",
  "compliance_pension",
  "compliance_calendar",
  "compliance_labor",
  "compliance_data",
  "compliance_audit",
] as const;

export function FeaturesContent() {
  const { t } = useT();

  return (
    <div>
      {/* Hero */}
      <section className="relative overflow-hidden border-b border-border/50 bg-muted/20">
        <div className="pointer-events-none absolute inset-0 -z-10">
          <div className="absolute left-1/2 top-0 h-[400px] w-[600px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-primary/[0.04] blur-3xl" />
        </div>
        <div className="mx-auto max-w-7xl px-4 py-20 sm:px-6 sm:py-24 lg:px-8">
          <div className="mx-auto max-w-3xl text-center">
            <p className="text-sm font-semibold uppercase tracking-wider text-primary">
              {t("marketing.features_page.overline", "Product")}
            </p>
            <h1 className="mt-2 text-4xl font-bold tracking-tight text-foreground sm:text-5xl">
              {t(
                "marketing.features_page.title",
                "Built for How Ethiopian Organizations Actually Work",
              )}
            </h1>
            <p className="mt-6 text-lg leading-relaxed text-muted-foreground">
              {t(
                "marketing.features_page.subtitle",
                "Every feature is designed around Ethiopian labor law, tax rules, and workplace realities.",
              )}
            </p>
          </div>
        </div>
      </section>

      {/* Attendance Deep-Dive */}
      <section className="py-20 sm:py-24">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="max-w-xl">
            <p className="text-sm font-semibold uppercase tracking-wider text-primary">
              {t("marketing.features_page.attendance_overline", "Attendance")}
            </p>
            <h2 className="mt-2 text-3xl font-bold tracking-tight text-foreground">
              {t(
                "marketing.features_page.attendance_title",
                "Attendance Platform",
              )}
            </h2>
            <p className="mt-4 text-lg text-muted-foreground">
              {t(
                "marketing.features_page.attendance_subtitle",
                "Reliable time tracking that works even without internet",
              )}
            </p>
          </div>
          <div className="mt-12 grid gap-4 sm:grid-cols-2">
            {attendanceFeatures.map((f) => {
              const Icon = f.icon;
              return (
                <div
                  key={f.key}
                  className="group rounded-xl border border-border/60 bg-card p-6 transition-all duration-200 hover:border-border hover:shadow-md"
                >
                  <div className="flex items-start gap-4">
                    <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 transition-colors group-hover:bg-primary/15">
                      <Icon className="h-5 w-5 text-primary" />
                    </div>
                    <div>
                      <h3 className="text-base font-semibold text-foreground">
                        {t(`marketing.features_page.${f.key}`)}
                      </h3>
                      <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                        {t(`marketing.features_page.${f.key}_desc`)}
                      </p>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      </section>

      {/* Core Modules */}
      <section className="border-y border-border/50 bg-muted/20 py-20 sm:py-24">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="max-w-xl">
            <p className="text-sm font-semibold uppercase tracking-wider text-primary">
              {t("marketing.features_page.core_overline", "Modules")}
            </p>
            <h2 className="mt-2 text-3xl font-bold tracking-tight text-foreground">
              {t("marketing.features_page.core_title", "Core Modules")}
            </h2>
            <p className="mt-4 text-lg text-muted-foreground">
              {t(
                "marketing.features_page.core_subtitle",
                "Everything from hiring to payroll in one system",
              )}
            </p>
          </div>
          <div className="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {coreModules.map((m) => {
              const Icon = m.icon;
              return (
                <div
                  key={m.key}
                  className="group rounded-xl border border-border/60 bg-card p-5 transition-all duration-200 hover:border-border hover:shadow-md"
                >
                  <div className="flex h-11 w-11 items-center justify-center rounded-lg bg-primary/10 transition-colors group-hover:bg-primary/15">
                    <Icon className="h-5 w-5 text-primary" />
                  </div>
                  <h3 className="mt-4 text-base font-semibold text-foreground">
                    {t(`marketing.features_page.${m.key}`)}
                  </h3>
                  <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                    {t(`marketing.features_page.${m.key}_desc`)}
                  </p>
                </div>
              );
            })}
          </div>
        </div>
      </section>

      {/* Ethiopian Compliance */}
      <section className="py-20 sm:py-24">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="grid items-start gap-12 lg:grid-cols-2">
            <div>
              <p className="text-sm font-semibold uppercase tracking-wider text-primary">
                {t("marketing.features_page.compliance_overline", "Compliance")}
              </p>
              <h2 className="mt-2 text-3xl font-bold tracking-tight text-foreground">
                {t(
                  "marketing.features_page.compliance_title",
                  "Ethiopian Law Built In",
                )}
              </h2>
              <p className="mt-4 text-lg text-muted-foreground">
                {t(
                  "marketing.features_page.compliance_subtitle",
                  "Tax brackets, pension rules, calendar, and labor law — we handle the complexity so you don't have to.",
                )}
              </p>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
              {complianceItems.map((key) => (
                <div
                  key={key}
                  className="flex items-start gap-3 rounded-lg border border-border/60 bg-card p-4"
                >
                  <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-status-success" />
                  <p className="text-sm font-medium text-foreground">
                    {t(`marketing.features_page.${key}`)}
                  </p>
                </div>
              ))}
            </div>
          </div>
        </div>
      </section>

      {/* CTA */}
      <section className="border-t border-border/50">
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
                <Link href="/contact">
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
