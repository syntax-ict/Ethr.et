"use client";

import Link from "next/link";
import { ArrowRight, CheckCircle2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { pickLocalised } from "@/features/marketing/api";
import type { MarketingSection } from "@/lib/marketing/content";
import { useLocaleHref } from "@/lib/i18n/route-locale";
import { useT } from "@/lib/i18n/useT";

/** The call to action, full bleed on the primary colour. */
export function CtaSection({ section }: { section: MarketingSection }) {
  const { t, locale } = useT();
  const href = useLocaleHref();

  return (
    <section
      aria-labelledby="cta-heading"
      className="relative isolate overflow-hidden border-t"
    >
      <div className="absolute inset-0 -z-10 bg-primary" />
      <div className="absolute inset-0 -z-10 bg-[linear-gradient(to_right,rgba(255,255,255,0.03)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.03)_1px,transparent_1px)] bg-[size:48px_48px]" />
      <div className="mx-auto max-w-7xl px-4 py-20 sm:px-6 sm:py-24 lg:px-8">
        <div className="max-w-2xl">
          <h2
            id="cta-heading"
            className="text-3xl font-semibold leading-tight tracking-[-0.015em] text-primary-foreground sm:text-4xl"
          >
            {pickLocalised(locale, section.heading_am, section.heading) ??
              section.heading}
          </h2>
          {(section.subheading || section.subheading_am) && (
            <p className="mt-4 text-lg leading-relaxed text-primary-foreground/80">
              {pickLocalised(locale, section.subheading_am, section.subheading)}
            </p>
          )}
        </div>
        <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
          <Button
            size="lg"
            variant="secondary"
            className="h-12 px-7 text-base"
            asChild
          >
            <Link href="/register">
              {t("marketing.hero.get_started", "Get Started")}
              <ArrowRight className="ml-2 h-4 w-4" aria-hidden="true" />
            </Link>
          </Button>
          <Button
            size="lg"
            variant="outline"
            className="h-12 border-primary-foreground/25 bg-transparent px-7 text-base text-primary-foreground hover:bg-primary-foreground/10 hover:text-primary-foreground"
            asChild
          >
            <Link href={href("/contact")}>
              {t("marketing.cta.talk_to_sales", "Talk to Sales")}
            </Link>
          </Button>
        </div>
        {section.items.length > 0 && (
          <ul className="mt-8 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-primary-foreground/75">
            {section.items.map((item) => (
              <li key={item.public_id} className="flex items-center gap-1.5">
                <CheckCircle2 className="h-4 w-4" aria-hidden="true" />
                {pickLocalised(locale, item.title_am, item.title) ?? item.title}
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  );
}
