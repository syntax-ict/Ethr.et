"use client";

import { pickLocalised } from "@/features/marketing/api";
import type { MarketingSection } from "@/lib/marketing/content";
import { useT } from "@/lib/i18n/useT";
import { SectionIntro } from "./section-intro";

/**
 * Getting started, as the sequence it is: the one section where a number
 * beside each step carries information.
 */
export function HowItWorksSection({ section }: { section: MarketingSection }) {
  const { t, locale } = useT();

  return (
    <section
      aria-labelledby="how-it-works-heading"
      className="border-y border-border/50 bg-muted/20 py-20 sm:py-24"
    >
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <SectionIntro
          id="how-it-works-heading"
          heading={
            pickLocalised(locale, section.heading_am, section.heading) ??
            section.heading
          }
          subheading={pickLocalised(
            locale,
            section.subheading_am,
            section.subheading,
          )}
        />

        <ol className="mt-12 grid gap-8 sm:grid-cols-3 sm:gap-6">
          {section.items.map((item, index) => (
            <li key={item.public_id} className="relative flex gap-4 sm:block">
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary text-base font-semibold tabular-nums text-primary-foreground">
                <span className="sr-only">
                  {t("marketing.landing.step_label", "Step :number", {
                    number: String(index + 1),
                  })}
                </span>
                <span aria-hidden="true">{index + 1}</span>
              </div>
              {index < section.items.length - 1 && (
                <div
                  className="absolute left-[calc(2.5rem+0.75rem)] top-5 hidden h-px w-[calc(100%-3.25rem)] bg-border sm:block"
                  aria-hidden="true"
                />
              )}
              <div className="sm:mt-5">
                <h3 className="text-lg font-semibold text-foreground">
                  {pickLocalised(locale, item.title_am, item.title) ??
                    item.title}
                </h3>
                {(item.body || item.body_am) && (
                  <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                    {pickLocalised(locale, item.body_am, item.body)}
                  </p>
                )}
              </div>
            </li>
          ))}
        </ol>
      </div>
    </section>
  );
}
