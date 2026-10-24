"use client";

import { pickLocalised } from "@/features/marketing/api";
import type { MarketingSection } from "@/lib/marketing/content";
import { useT } from "@/lib/i18n/useT";
import { marketingIcon } from "./marketing-icons";
import { SectionIntro } from "./section-intro";

/**
 * Where the data is and what guards it, on the deep band: the heading and
 * its sentence on the left, the safeguards in two columns on the right.
 */
export function TrustSection({ section }: { section: MarketingSection }) {
  const { locale } = useT();

  return (
    <section
      aria-labelledby="trust-heading"
      className="bg-sidebar py-20 text-sidebar-foreground sm:py-24"
    >
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="grid gap-12 lg:grid-cols-12 lg:items-start">
          <SectionIntro
            id="trust-heading"
            heading={
              pickLocalised(locale, section.heading_am, section.heading) ??
              section.heading
            }
            subheading={pickLocalised(
              locale,
              section.subheading_am,
              section.subheading,
            )}
            tone="inverse"
            className="lg:col-span-5"
          />
          <ul className="grid gap-6 sm:grid-cols-2 lg:col-span-7">
            {section.items.map((item) => {
              const Icon = marketingIcon(item.icon);
              return (
                <li key={item.public_id} className="flex gap-4">
                  {Icon && (
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-sidebar-accent">
                      <Icon
                        className="h-5 w-5 text-sidebar-foreground"
                        aria-hidden="true"
                      />
                    </span>
                  )}
                  <div>
                    <h3 className="text-base font-semibold text-sidebar-foreground">
                      {pickLocalised(locale, item.title_am, item.title) ??
                        item.title}
                    </h3>
                    {(item.body || item.body_am) && (
                      <p className="mt-1.5 text-sm leading-relaxed text-sidebar-foreground/75">
                        {pickLocalised(locale, item.body_am, item.body)}
                      </p>
                    )}
                  </div>
                </li>
              );
            })}
          </ul>
        </div>
      </div>
    </section>
  );
}
