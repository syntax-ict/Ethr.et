"use client";

import Link from "next/link";
import { ArrowRight } from "lucide-react";
import { pickLocalised } from "@/features/marketing/api";
import type { MarketingItem, MarketingSection } from "@/lib/marketing/content";
import { revealClass, useRevealOnce } from "@/lib/hooks/useRevealOnce";
import { useLocaleHref } from "@/lib/i18n/route-locale";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";
import { ItemPicture } from "./item-picture";
import { MarketingIconGlyph } from "./marketing-icon-glyph";
import { ProblemStory } from "./problem-stories";
import { SectionIntro } from "./section-intro";

/** Today, the story between them, and what ETHR does: one grid for the
 * column labels and every row, so the three line up down the section. */
const LEDGER_COLUMNS =
  "lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.3fr)_minmax(0,0.95fr)]";

/**
 * The ledger: what goes wrong today on the left, what ETHR does with it on
 * the right, one row per problem, and between them a short looping story
 * that plays the one into the other (`problem-stories.tsx`). The right
 * column of each row arrives once when the row scrolls into view.
 *
 * An uploaded picture still takes the story's place, because it is the
 * operator's deliberate choice at /admin/marketing. The seed uploads none,
 * so as shipped every row animates.
 */
export function ProblemsSection({ section }: { section: MarketingSection }) {
  const { t, locale } = useT();
  const heading = pickLocalised(locale, section.heading_am, section.heading);

  return (
    <section
      aria-labelledby="problems-heading"
      className="bg-sidebar py-20 text-sidebar-foreground sm:py-24"
    >
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <SectionIntro
          id="problems-heading"
          heading={heading ?? section.heading}
          subheading={pickLocalised(
            locale,
            section.subheading_am,
            section.subheading,
          )}
          tone="inverse"
        />

        <div
          className={cn(
            "mt-10 hidden gap-x-8 text-sm font-medium text-sidebar-foreground/60 lg:grid",
            LEDGER_COLUMNS,
          )}
        >
          <p>{t("marketing.landing.today_label", "Today")}</p>
          <p aria-hidden="true" />
          <p>{t("marketing.landing.with_ethr_label", "With ETHR")}</p>
        </div>

        <ol className="mt-4 divide-y divide-sidebar-border">
          {section.items.map((item) => (
            <LedgerRow key={item.public_id} item={item} locale={locale} />
          ))}
        </ol>
      </div>
    </section>
  );
}

function LedgerRow({ item, locale }: { item: MarketingItem; locale: string }) {
  const { t } = useT();
  const href = useLocaleHref();
  const { ref, state } = useRevealOnce<HTMLLIElement>();
  const title = pickLocalised(locale, item.title_am, item.title) ?? item.title;
  const body = pickLocalised(locale, item.body_am, item.body);
  const detail = pickLocalised(locale, item.detail_am, item.detail);

  return (
    <li
      ref={ref}
      className={cn(
        "grid gap-6 py-10 lg:items-center lg:gap-x-8",
        LEDGER_COLUMNS,
      )}
    >
      <div className="flex gap-4">
        {item.icon && (
          <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-sidebar-accent">
            <MarketingIconGlyph
              name={item.icon}
              className="h-5 w-5 text-sidebar-foreground"
            />
          </span>
        )}
        <div className="min-w-0">
          <p className="text-xs font-medium text-sidebar-foreground/60 lg:hidden">
            {t("marketing.landing.today_label", "Today")}
          </p>
          <h3 className="text-lg font-semibold leading-snug text-sidebar-foreground">
            {title}
          </h3>
          {body && (
            <p className="mt-2 text-base leading-relaxed text-sidebar-foreground/75">
              {body}
            </p>
          )}
        </div>
      </div>

      <div className="min-w-0">
        {item.image ? (
          <ItemPicture
            kind="problems"
            slug={item.slug}
            image={item.image}
            locale={locale}
            sizes="(min-width: 1024px) 34rem, 100vw"
            className="rounded-2xl"
          />
        ) : (
          <ProblemStory slug={item.slug} />
        )}
      </div>

      <div
        className={cn(
          "rounded-2xl bg-card p-5 text-card-foreground shadow-sm sm:p-6",
          revealClass(state),
        )}
      >
        <p className="text-xs font-medium text-muted-foreground lg:hidden">
          {t("marketing.landing.with_ethr_label", "With ETHR")}
        </p>
        {detail && (
          <p className="text-base leading-relaxed text-foreground">{detail}</p>
        )}
        {item.link_path && (
          <Link
            href={href(item.link_path)}
            className="mt-3 inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
          >
            {t("marketing.landing.learn_more", "Read more")}
            <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
          </Link>
        )}
      </div>
    </li>
  );
}
