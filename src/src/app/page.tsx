import type { Metadata } from 'next';
import Link from 'next/link';
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
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { MarketingHeader } from '@/components/layouts/marketing-header';
import { MarketingFooter } from '@/components/layouts/marketing-footer';

export const metadata: Metadata = {
  title: 'ETHR — Ethiopian Workforce Operating System',
  description:
    'Enterprise-grade HR platform for Ethiopian organizations. Manage attendance, payroll, leave, and more — offline-first, bilingual, and built for Ethiopian labor law.',
  openGraph: {
    title: 'ETHR — Ethiopian Workforce Operating System',
    description: 'Enterprise-grade HR platform for Ethiopian organizations.',
    type: 'website',
  },
};

const features = [
  {
    icon: Clock,
    title: 'Attendance Tracking',
    description: 'Biometric devices, GPS check-in, and offline sync for reliable time tracking.',
  },
  {
    icon: Wallet,
    title: 'Payroll Management',
    description: 'Ethiopian tax brackets, pension calculations, and automated payslips.',
  },
  {
    icon: CalendarDays,
    title: 'Leave Management',
    description: 'Custom leave types, approval workflows, and balance tracking.',
  },
  {
    icon: WifiOff,
    title: 'Offline-First',
    description: 'Works without internet. Syncs automatically when connectivity returns.',
  },
  {
    icon: Building2,
    title: 'Multi-Organization',
    description: 'Each organization gets its own isolated workspace with custom branding.',
  },
  {
    icon: Languages,
    title: 'Amharic + English',
    description: 'Full bilingual interface with Ethiopian calendar support.',
  },
];

const industries = [
  { icon: Landmark, name: 'Government', color: 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300' },
  { icon: Landmark, name: 'Banking', color: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' },
  { icon: Hospital, name: 'Healthcare', color: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' },
  { icon: Factory, name: 'Manufacturing', color: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' },
  { icon: Heart, name: 'NGO', color: 'bg-pink-100 text-pink-700 dark:bg-pink-950 dark:text-pink-300' },
  { icon: Hotel, name: 'Hospitality', color: 'bg-purple-100 text-purple-700 dark:bg-purple-950 dark:text-purple-300' },
  { icon: GraduationCap, name: 'Education', color: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300' },
  { icon: Briefcase, name: 'General', color: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' },
];

export default function LandingPage() {
  return (
    <div className="flex min-h-screen flex-col bg-background">
      <MarketingHeader />
      <main className="flex-1">
        {/* Hero */}
        <section className="relative overflow-hidden">
          <div className="mx-auto max-w-7xl px-4 py-24 sm:px-6 sm:py-32 lg:px-8">
            <div className="mx-auto max-w-3xl text-center">
              <h1 className="text-4xl font-bold tracking-tight text-foreground sm:text-5xl lg:text-6xl">
                The Complete HR Platform for{' '}
                <span className="text-primary">Ethiopian Organizations</span>
              </h1>
              <p className="mt-6 text-lg leading-8 text-muted-foreground">
                Manage employees, attendance, payroll, and leave &mdash; offline-first, bilingual,
                and built for Ethiopian labor law.
              </p>
              <div className="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row">
                <Button size="lg" asChild>
                  <Link href="/register">
                    Start Free Trial <ArrowRight className="ml-2 h-4 w-4" />
                  </Link>
                </Button>
                <Button variant="outline" size="lg" asChild>
                  <Link href="/features">Learn More</Link>
                </Button>
              </div>
              <p className="mt-4 text-sm text-muted-foreground">
                6-month free trial &middot; No credit card required
              </p>
            </div>
          </div>
        </section>

        {/* Features */}
        <section className="border-t bg-muted/30 py-20">
          <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div className="mx-auto max-w-2xl text-center">
              <h2 className="text-3xl font-bold tracking-tight text-foreground">
                Everything You Need
              </h2>
              <p className="mt-3 text-muted-foreground">
                One platform to manage your entire workforce
              </p>
            </div>
            <div className="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
              {features.map((feature) => {
                const Icon = feature.icon;
                return (
                  <Card key={feature.title} className="border-0 bg-background shadow-sm">
                    <CardHeader className="pb-3">
                      <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                        <Icon className="h-5 w-5 text-primary" />
                      </div>
                      <CardTitle className="mt-3 text-lg">{feature.title}</CardTitle>
                    </CardHeader>
                    <CardContent>
                      <p className="text-sm text-muted-foreground">{feature.description}</p>
                    </CardContent>
                  </Card>
                );
              })}
            </div>
          </div>
        </section>

        {/* Industries */}
        <section className="py-20">
          <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div className="mx-auto max-w-2xl text-center">
              <h2 className="text-3xl font-bold tracking-tight text-foreground">
                Built for Ethiopian Industries
              </h2>
              <p className="mt-3 text-muted-foreground">
                Pre-configured templates for your sector
              </p>
            </div>
            <div className="mt-12 grid grid-cols-2 gap-4 sm:grid-cols-4">
              {industries.map((industry) => {
                const Icon = industry.icon;
                return (
                  <div
                    key={industry.name}
                    className="flex flex-col items-center gap-3 rounded-xl border bg-card p-6 text-center shadow-sm transition-shadow hover:shadow-md"
                  >
                    <div className={`flex h-12 w-12 items-center justify-center rounded-xl ${industry.color}`}>
                      <Icon className="h-6 w-6" />
                    </div>
                    <span className="text-sm font-medium">{industry.name}</span>
                  </div>
                );
              })}
            </div>
          </div>
        </section>

        {/* CTA */}
        <section className="border-t bg-primary py-20">
          <div className="mx-auto max-w-7xl px-4 text-center sm:px-6 lg:px-8">
            <h2 className="text-3xl font-bold tracking-tight text-primary-foreground">
              Start Your 6-Month Free Trial
            </h2>
            <p className="mt-3 text-primary-foreground/80">
              No credit card required. Full access to all features.
            </p>
            <div className="mt-8">
              <Button size="lg" variant="secondary" asChild>
                <Link href="/register">
                  Get Started <ArrowRight className="ml-2 h-4 w-4" />
                </Link>
              </Button>
            </div>
          </div>
        </section>
      </main>
      <MarketingFooter />
    </div>
  );
}
