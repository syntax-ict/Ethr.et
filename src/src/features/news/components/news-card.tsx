"use client";

import { Star } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";
import type { LandingNewsItem } from "@/features/auth/landing-feed";
import { newsDate } from "../news-format";

type NewsSummary = Pick<
  LandingNewsItem,
  "public_id" | "title" | "excerpt" | "published_at" | "priority"
> &
  Partial<Pick<LandingNewsItem, "category" | "featured">>;

/**
 * The date, category and flags above a story's title, shared by every place
 * a story is listed.
 */
export function NewsMeta({ item }: { item: NewsSummary }) {
  const { t, locale } = useT();

  return (
    <span className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
      {item.published_at && (
        <time dateTime={item.published_at}>
          {newsDate(item.published_at, locale)}
        </time>
      )}
      {item.category && (
        <span className="rounded-full bg-primary-soft px-2 py-0.5 text-[11px] font-medium text-primary-on-soft">
          {t(`news.category_${item.category}`, item.category)}
        </span>
      )}
      {item.featured && (
        <span className="inline-flex items-center gap-1 rounded-full bg-brand-soft px-2 py-0.5 text-[11px] font-medium text-brand-on-soft">
          <Star className="h-3 w-3" aria-hidden="true" />
          {t("news.featured", "Featured")}
        </span>
      )}
      {(item.priority === "urgent" || item.priority === "high") && (
        <span
          className={cn(
            "rounded-full px-2 py-0.5 text-[11px] font-semibold",
            item.priority === "urgent"
              ? "bg-destructive-soft text-destructive-on-soft"
              : "bg-warning-soft text-warning-on-soft",
          )}
        >
          {item.priority === "urgent"
            ? t("landing.urgent", "Urgent")
            : t("landing.important", "Important")}
        </span>
      )}
    </span>
  );
}

/**
 * One story as a card: on the portal's news section, the news page and an
 * article's related list. A link to its article page; inert (no href) in the
 * settings preview, where nothing should navigate away.
 *
 * `size="feature"` is the news page's lead story: the same content, given
 * the width and a larger title.
 */
export function NewsCard({
  item,
  href,
  size = "card",
}: {
  item: NewsSummary;
  href: string | null;
  size?: "card" | "feature";
}) {
  const { t } = useT();
  const feature = size === "feature";
  const className = cn(
    "group flex min-w-0 flex-col rounded-xl border border-border bg-card text-left transition-colors",
    feature ? "p-6 @lg:p-8" : "p-4",
    href &&
      "hover:border-border-strong hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
  );
  const body = (
    <>
      <NewsMeta item={item} />
      <span
        className={cn(
          "mt-2 font-semibold text-foreground",
          feature ? "text-xl leading-snug @lg:text-2xl" : "text-sm",
        )}
      >
        {item.title}
      </span>
      <span
        className={cn(
          "mt-1 whitespace-pre-line text-muted-foreground",
          feature
            ? "line-clamp-5 text-base leading-relaxed"
            : "line-clamp-4 text-sm",
        )}
      >
        {item.excerpt}
      </span>
      {href && (
        <span className="mt-3 text-xs font-medium text-primary group-hover:underline">
          {t("landing.read_more", "Read more")}
          <span className="sr-only">: {item.title}</span>
        </span>
      )}
    </>
  );

  return href ? (
    <a href={href} className={className}>
      {body}
    </a>
  ) : (
    <div className={className}>{body}</div>
  );
}
