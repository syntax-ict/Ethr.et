"use client";

import { pickLocalised } from "@/features/marketing/api";
import type { MarketingSection } from "@/lib/marketing/content";
import { useT } from "@/lib/i18n/useT";
import { marketingIcon } from "./marketing-icons";
import { SectionIntro } from "./section-intro";

/**
 * The sectors the templates are built for, as a row of chips: a name and an
 * icon each, nothing to read inside a card.
 */
export function IndustriesSection({ section }: { section: MarketingSection }) {
  const { locale } = useT();

  return (
    <section aria-labelledby="industries-heading" className="py-20 sm:py-24">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="grid gap-10 lg:grid-cols-12 lg:items-start">
          <SectionIntro
            id="industries-heading"
            heading={
              pickLocalised(locale, section.heading_am, section.heading) ??
              section.heading
            }
            subheading={pickLocalised(
              locale,
              section.subheading_am,
              section.subheading,
            )}
            className="lg:col-span-5"
          />
          <ul className="flex flex-wrap gap-3 lg:col-span-7">
            {section.items.map((item) => {
              const Icon = marketingIcon(item.icon);
              return (
                <li
                  key={item.public_id}
                  className="inline-flex items-center gap-2 rounded-full border border-border/70 bg-card px-4 py-2.5 text-sm font-medium text-foreground"
                >
                  {Icon && (
                    <Icon className="h-4 w-4 text-primary" aria-hidden="true" />
                  )}
                  {pickLocalised(locale, item.title_am, item.title) ??
                    item.title}
                </li>
              );
            })}
          </ul>
        </div>
      </div>
    </section>
  );
}
