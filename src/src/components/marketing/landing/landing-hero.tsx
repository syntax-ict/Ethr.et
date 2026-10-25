"use client";

import Link from "next/link";
import { ArrowRight } from "lucide-react";
import { Button } from "@/components/ui/button";
import { ProductFlow } from "@/components/marketing/product-flow";
import { pickLocalised } from "@/features/marketing/api";
import type { MarketingSection } from "@/lib/marketing/content";
import { useLocaleHref } from "@/lib/i18n/route-locale";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";
import { HeroStory } from "./hero-story";
import { ItemPicture } from "./item-picture";

/**
 * The hero: the heading and sentence the platform operator wrote, two
 * buttons whose labels are interface chrome, and a picture on the right.
 *
 * When the operator has uploaded a hero picture it takes the right column;
 * otherwise the looping office story (`hero-story.tsx`) takes it, by the
 * owner's decision of 2026-10-25 that every illustration on the page moves.
 * The product sequence below is always on the page and still plays once: it
 * is the one place the page shows the offline claim rather than stating it.
 */
export function LandingHero({
  section,
  organisationsCount,
}: {
  section: MarketingSection;
  organisationsCount: number | null;
}) {
  const { t, locale } = useT();
  const href = useLocaleHref();
  const heading = pickLocalised(locale, section.heading_am, section.heading);
  const subheading = pickLocalised(
    locale,
    section.subheading_am,
    section.subheading,
  );

  return (
    <section
      className="relative isolate overflow-hidden"
      aria-labelledby="hero-heading"
    >
      <div className="pointer-events-none absolute inset-0 -z-10">
        <div className="absolute inset-0 bg-gradient-to-b from-primary/[0.04] via-transparent to-transparent" />
      </div>

      <div className="mx-auto max-w-7xl px-4 pb-12 pt-14 sm:px-6 sm:pt-20 lg:px-8 lg:pt-24">
        <div className="grid items-center gap-10 lg:grid-cols-12 lg:gap-12">
          <div className="lg:col-span-7">
            {organisationsCount !== null && (
              <div className="mb-6 inline-flex items-center gap-2 rounded-full border border-border bg-background/80 px-4 py-1.5 text-sm text-muted-foreground">
                <span className="relative flex h-2 w-2">
                  <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                </span>
                {t(
                  "marketing.hero.badge_count",
                  "Now serving :count Ethiopian organizations",
                  {
                    count: new Intl.NumberFormat("en-ET").format(
                      organisationsCount,
                    ),
                  },
                )}
              </div>
            )}

            <h1
              id="hero-heading"
              className={cn(
                "max-w-3xl text-[clamp(2.25rem,1.25rem+3.5vw,3.5rem)] font-semibold tracking-[-0.02em] text-foreground",
                locale === "am" ? "leading-[1.3]" : "leading-[1.1]",
              )}
            >
              {heading}
            </h1>
            {subheading && (
              <p className="mt-6 max-w-2xl text-lg leading-relaxed text-muted-foreground sm:text-xl">
                {subheading}
              </p>
            )}

            <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
              <Button size="lg" className="h-12 px-7 text-base" asChild>
                <Link href="/register">
                  {t("marketing.hero.cta", "Start Free Trial")}
                  <ArrowRight className="ml-2 h-4 w-4" aria-hidden="true" />
                </Link>
              </Button>
              <Button
                variant="outline"
                size="lg"
                className="h-12 px-7 text-base"
                asChild
              >
                <Link href={href("/features")}>
                  {t("marketing.hero.learn_more", "Explore Features")}
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

          <div className="lg:col-span-5">
            <div className="aspect-[16/9] w-full overflow-hidden rounded-2xl border border-border/60 bg-card shadow-sm">
              {section.image ? (
                <ItemPicture
                  kind="hero"
                  slug="hero"
                  image={section.image}
                  locale={locale}
                  sizes="(min-width: 1024px) 40vw, 100vw"
                  priority
                  className="h-full rounded-none"
                />
              ) : (
                <HeroStory />
              )}
            </div>
          </div>
        </div>

        <div className="mt-12 sm:mt-16">
          <ProductFlow />
        </div>
      </div>
    </section>
  );
}
