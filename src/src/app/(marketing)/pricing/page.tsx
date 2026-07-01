import type { Metadata } from "next";
import Link from "next/link";
import { Check } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardFooter,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";

export const metadata: Metadata = {
  title: "Pricing",
  description:
    "Simple, transparent pricing for ETHR. Start free, upgrade when you grow.",
};

const plans = [
  {
    name: "Starter",
    price: "Free",
    period: "6-month trial",
    description: "For small teams getting started",
    features: [
      "Up to 50 employees",
      "5 branches",
      "10 devices",
      "Attendance tracking",
      "Basic payroll",
      "Leave management",
      "Email support",
    ],
    cta: "Start Free Trial",
    popular: false,
  },
  {
    name: "Professional",
    price: "2,500",
    period: "ETB/month",
    description: "For growing organizations",
    features: [
      "Up to 200 employees",
      "15 branches",
      "50 devices",
      "Advanced attendance",
      "Full payroll suite",
      "Leave management",
      "Custom reports",
      "API access",
      "Priority support",
    ],
    cta: "Start Free Trial",
    popular: true,
  },
  {
    name: "Enterprise",
    price: "Custom",
    period: "Contact us",
    description: "For large organizations",
    features: [
      "Unlimited employees",
      "Unlimited branches",
      "Unlimited devices",
      "All Professional features",
      "Custom integrations",
      "Dedicated support",
      "SLA guarantee",
      "On-premise option",
      "Training & onboarding",
    ],
    cta: "Contact Sales",
    popular: false,
  },
];

const faqs = [
  {
    q: "How long is the free trial?",
    a: "Every new organization gets a full 6-month free trial with access to all Starter plan features. No credit card required.",
  },
  {
    q: "Can I upgrade or downgrade my plan?",
    a: "Yes, you can change your plan at any time. Upgrades take effect immediately. Downgrades apply at the end of your billing period.",
  },
  {
    q: "Does ETHR work offline?",
    a: "Yes. Attendance data and key HR functions work offline. Data syncs automatically when internet connectivity returns.",
  },
  {
    q: "Is my data secure?",
    a: "Absolutely. Each organization gets fully isolated data with encryption at rest. We follow industry best practices for security.",
  },
  {
    q: "Do you support Ethiopian calendar?",
    a: "Yes. ETHR supports both Ethiopian (Ge'ez) and Gregorian calendars. You can toggle between them at any time.",
  },
  {
    q: "Can I host ETHR on my own servers?",
    a: "The Enterprise plan includes an on-premise deployment option. Contact our sales team for details.",
  },
];

export default function PricingPage() {
  return (
    <div className="py-20">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="mx-auto max-w-2xl text-center">
          <h1 className="text-4xl font-bold tracking-tight text-foreground">
            Simple, Transparent Pricing
          </h1>
          <p className="mt-3 text-lg text-muted-foreground">
            Start free, upgrade when you grow
          </p>
        </div>

        <div className="mt-16 grid gap-8 lg:grid-cols-3">
          {plans.map((plan) => (
            <Card
              key={plan.name}
              className={`relative flex flex-col ${plan.popular ? "border-primary shadow-lg" : ""}`}
            >
              {plan.popular && (
                <Badge className="absolute -top-3 left-1/2 -translate-x-1/2">
                  Most Popular
                </Badge>
              )}
              <CardHeader>
                <CardTitle className="text-xl">{plan.name}</CardTitle>
                <CardDescription>{plan.description}</CardDescription>
                <div className="mt-4">
                  <span className="text-4xl font-bold">{plan.price}</span>
                  {plan.period && (
                    <span className="ml-1 text-sm text-muted-foreground">
                      {plan.period}
                    </span>
                  )}
                </div>
              </CardHeader>
              <CardContent className="flex-1">
                <ul className="space-y-3">
                  {plan.features.map((feature) => (
                    <li key={feature} className="flex items-start gap-2">
                      <Check className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                      <span className="text-sm">{feature}</span>
                    </li>
                  ))}
                </ul>
              </CardContent>
              <CardFooter>
                <Button
                  className="w-full"
                  variant={plan.popular ? "default" : "outline"}
                  asChild
                >
                  <Link
                    href={plan.name === "Enterprise" ? "/contact" : "/register"}
                  >
                    {plan.cta}
                  </Link>
                </Button>
              </CardFooter>
            </Card>
          ))}
        </div>

        {/* FAQ */}
        <div className="mt-24">
          <h2 className="text-center text-3xl font-bold tracking-tight text-foreground">
            Frequently Asked Questions
          </h2>
          <div className="mx-auto mt-12 max-w-3xl divide-y">
            {faqs.map((faq) => (
              <details key={faq.q} className="group py-4">
                <summary className="flex cursor-pointer items-center justify-between text-sm font-medium text-foreground">
                  {faq.q}
                  <span className="ml-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-180">
                    &#9660;
                  </span>
                </summary>
                <p className="mt-3 text-sm text-muted-foreground">{faq.a}</p>
              </details>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}
