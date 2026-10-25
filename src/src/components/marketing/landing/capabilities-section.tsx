"use client";

import Link from "next/link";
import { ArrowRight, CheckCircle2 } from "lucide-react";
import { SolutionLinks } from "@/components/marketing/solution-links";
import { pickLocalised } from "@/features/marketing/api";
import type { MarketingSection } from "@/lib/marketing/content";
import { useLocaleHref } from "@/lib/i18n/route-locale";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";
import { ItemPicture } from "./item-picture";
import { marketingIcon } from "./marketing-icons";
import { SectionIntro } from "./section-intro";

/**
 * What ETHR does, one capability per row, each beside the work it takes off
 * an HR team and a picture of the product doing it. Rows alternate sides so
 * the eye travels down the page rather than down one column.
 */
export function CapabilitiesSection({
  section,
}: {
  section: MarketingSection;
}) {
  const { t, locale } = useT();
  const href = useLocaleHref();

  return (
    <section aria-labelledby="capabilities-heading" className="py-20 sm:py-24">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <SectionIntro
          id="capabilities-heading"
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

        <ol className="mt-14 space-y-16 sm:space-y-20">
          {section.items.map((item, index) => {
            const Icon = marketingIcon(item.icon);
            const title =
              pickLocalised(locale, item.title_am, item.title) ?? item.title;
            const body = pickLocalised(locale, item.body_am, item.body);
            const value = pickLocalised(locale, item.detail_am, item.detail);
            const flipped = index % 2 === 1;

            return (
              <li
                key={item.public_id}
                className="grid items-center gap-8 lg:grid-cols-12 lg:gap-12"
              >
                <div
                  className={cn(
                    "lg:col-span-5",
                    flipped ? "lg:order-2 lg:col-start-8" : "lg:col-start-1",
                  )}
                >
                  {Icon && (
                    <span className="flex h-11 w-11 items-center justify-center rounded-lg bg-primary/10">
                      <Icon
                        className="h-5 w-5 text-primary"
                        aria-hidden="true"
                      />
                    </span>
                  )}
                  <h3 className="mt-4 text-2xl font-semibold leading-snug tracking-[-0.01em] text-foreground">
                    {title}
                  </h3>
                  {body && (
                    <p className="mt-3 text-base leading-relaxed text-muted-foreground">
                      {body}
                    </p>
                  )}
                  {value && (
                    <p className="mt-4 flex items-start gap-2 text-sm font-medium text-foreground">
                      <CheckCircle2
                        className="mt-0.5 h-4 w-4 shrink-0 text-primary"
                        aria-hidden="true"
                      />
                      <span>
                        <span className="sr-only">
                          {t(
                            "marketing.capabilities.value_label",
                            "Business value",
                          )}
                          :{" "}
                        </span>
                        {value}
                      </span>
                    </p>
                  )}
                  {item.link_path && (
                    <Link
                      href={href(item.link_path)}
                      className="mt-4 inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                    >
                      {t("marketing.landing.learn_more", "Read more")}
                      <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
                    </Link>
                  )}
                </div>
                <div
                  className={cn(
                    "aspect-[16/10] w-full overflow-hidden rounded-2xl border border-border/60 bg-card lg:col-span-7",
                    flipped ? "lg:order-1 lg:col-start-1" : "lg:col-start-6",
                  )}
                >
                  <ItemPicture
                    kind="capabilities"
                    slug={item.slug}
                    image={item.image}
                    locale={locale}
                    sizes="(min-width: 1024px) 55vw, 100vw"
                    className="h-full rounded-none"
                  />
                </div>
              </li>
            );
          })}
        </ol>

        <SolutionLinks />
      </div>
    </section>
  );
}
