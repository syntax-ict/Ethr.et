import type { Metadata } from "next";
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
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";

export const metadata: Metadata = {
  title: "Features",
  description:
    "Explore ETHR features — attendance, payroll, leave, offline-first, bilingual, and more.",
};

const attendanceFeatures = [
  {
    icon: Fingerprint,
    title: "Biometric Integration",
    description:
      "Connect fingerprint and face recognition devices for accurate check-in/out.",
  },
  {
    icon: MapPin,
    title: "GPS Check-In",
    description:
      "Field employees can check in from any location with GPS coordinates and photos.",
  },
  {
    icon: WifiOff,
    title: "Offline Sync",
    description:
      "Devices store data locally and sync automatically when connectivity returns.",
  },
  {
    icon: Clock,
    title: "Shift Management",
    description:
      "Define shifts, rotations, overtime rules, and grace periods per department.",
  },
];

const coreModules = [
  {
    icon: Users,
    title: "HR Management",
    description:
      "Complete employee lifecycle — from hiring to retirement. Documents, contacts, education, and transitions.",
  },
  {
    icon: Wallet,
    title: "Payroll",
    description:
      "Ethiopian tax brackets (Proclamation 979/2016), pension (7%+11%), overtime, allowances, and automated payslips.",
  },
  {
    icon: CalendarDays,
    title: "Leave Management",
    description:
      "Custom leave types, accrual rules, approval workflows, balance tracking, and carry-forward policies.",
  },
  {
    icon: Shield,
    title: "Security",
    description:
      "Role-based access, MFA/TOTP, tenant isolation, encrypted data at rest, and comprehensive audit trails.",
  },
  {
    icon: FileText,
    title: "Reports & Exports",
    description:
      "Attendance reports, payroll summaries, leave analytics. Export to PDF and CSV.",
  },
  {
    icon: BarChart3,
    title: "Analytics Dashboard",
    description:
      "Real-time workforce insights — headcount, attendance rates, payroll costs, leave trends.",
  },
  {
    icon: Languages,
    title: "Bilingual",
    description:
      "Full Amharic and English interface. Ethiopian calendar (13 months) with Gregorian toggle.",
  },
  {
    icon: Smartphone,
    title: "Mobile Ready",
    description:
      "Progressive Web App works on any device. Install on phones for native-like experience.",
  },
];

export default function FeaturesPage() {
  return (
    <div className="py-20">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        {/* Header */}
        <div className="mx-auto max-w-2xl text-center">
          <h1 className="text-4xl font-bold tracking-tight text-foreground">
            Built for How Ethiopian Organizations Actually Work
          </h1>
          <p className="mt-4 text-lg text-muted-foreground">
            Every feature is designed around Ethiopian labor law, tax rules, and
            workplace realities.
          </p>
        </div>

        {/* Attendance Deep-Dive */}
        <div className="mt-20">
          <h2 className="text-2xl font-bold text-foreground">
            Attendance Platform
          </h2>
          <p className="mt-2 text-muted-foreground">
            Reliable time tracking that works even without internet
          </p>
          <div className="mt-8 grid gap-6 sm:grid-cols-2">
            {attendanceFeatures.map((f) => {
              const Icon = f.icon;
              return (
                <Card key={f.title}>
                  <CardHeader className="flex flex-row items-center gap-3 pb-2">
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                      <Icon className="h-5 w-5 text-primary" />
                    </div>
                    <CardTitle className="text-base">{f.title}</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <p className="text-sm text-muted-foreground">
                      {f.description}
                    </p>
                  </CardContent>
                </Card>
              );
            })}
          </div>
        </div>

        {/* Core Modules */}
        <div className="mt-20">
          <h2 className="text-2xl font-bold text-foreground">Core Modules</h2>
          <p className="mt-2 text-muted-foreground">
            Everything from hiring to payroll in one system
          </p>
          <div className="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            {coreModules.map((m) => {
              const Icon = m.icon;
              return (
                <div
                  key={m.title}
                  className="rounded-xl border bg-card p-5 shadow-sm"
                >
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                    <Icon className="h-5 w-5 text-primary" />
                  </div>
                  <h3 className="mt-4 font-semibold">{m.title}</h3>
                  <p className="mt-2 text-sm text-muted-foreground">
                    {m.description}
                  </p>
                </div>
              );
            })}
          </div>
        </div>

        {/* CTA */}
        <div className="mt-20 rounded-2xl bg-muted/50 p-8 text-center sm:p-12">
          <h2 className="text-2xl font-bold text-foreground">
            Ready to get started?
          </h2>
          <p className="mt-2 text-muted-foreground">
            Set up your organization in under 5 minutes
          </p>
          <Button size="lg" className="mt-6" asChild>
            <Link href="/register">
              Start Free Trial <ArrowRight className="ml-2 h-4 w-4" />
            </Link>
          </Button>
        </div>
      </div>
    </div>
  );
}
