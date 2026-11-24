"use client";

import type { ComponentType, ElementType, ReactNode } from "react";
import { Star } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";
import type { LandingNewsItem } from "@/features/auth/landing-feed";
import { newsDate } from "../news-format";

/** What the date, category and flags above a title need. */
type NewsMetaItem = Pick<LandingNewsItem, "published_at" | "priority"> &
  Partial<Pick<LandingNewsItem, "category" | "featured">>;

type NewsSummary = NewsMetaItem &
  Pick<LandingNewsItem, "public_id" | "title" | "excerpt"> &
  Partial<Pick<LandingNewsItem, "cover">>;

/** A story's cover photo as the API gives it: sized, with narrower copies. */
export type NewsCoverImage = NonNullable<LandingNewsItem["cover"]>;

/**
 * A story's cover photo. Decorative (`alt=""`): the story's title is beside
 * it, and the photo says nothing the title does not. Its width and height
 * reserve the space before it loads, so the page does not jump.
 */
export function NewsCoverPicture({
  cover,
  sizes,
  eager = false,
  className,
}: {
  cover: NewsCoverImage;
  sizes: string;
  eager?: boolean;
  className?: string;
}) {
  return (
    /* eslint-disable-next-line @next/next/no-img-element */
    <img
      src={cover.url}
      srcSet={cover.srcset || undefined}
      sizes={cover.srcset ? sizes : undefined}
      width={cover.width}
      height={cover.height}
      alt=""
      loading={eager ? "eager" : "lazy"}
      decoding="async"
      className={cn("block w-full object-cover", className)}
    />
  );
}

/**
 * The date, category and flags above a story's title, shared by every place
 * a story is listed.
 */
export function NewsMeta({ item }: { item: NewsMetaItem }) {
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
 * How much of a story a card shows, and how it sits:
 * - `card`: a grid cell on the public pages, with its cover above.
 * - `feature`: the news page's lead story, wide, with a larger title.
 * - `row`: one line of a list a person works in: no cover, no clamp, room
 *   for badges beside the title and actions beside the card.
 * - `compact`: a dashboard tile: no cover, two lines of text.
 *
 * The clamp lives here, once per density, over the server's excerpt (which
 * is already cut to one length for every reader).
 */
export type NewsDensity = "card" | "feature" | "row" | "compact";

const DENSITY: Record<
  NewsDensity,
  { title: string; text: string; pad: string }
> = {
  feature: {
    title: "text-xl leading-snug @lg:text-2xl",
    text: "line-clamp-5 text-base leading-relaxed",
    pad: "p-6 @lg:p-8",
  },
  card: { title: "text-sm", text: "line-clamp-4 text-sm", pad: "p-4" },
  row: { title: "text-sm break-words", text: "text-sm", pad: "p-4" },
  compact: {
    title: "text-sm break-words",
    text: "line-clamp-2 text-xs leading-relaxed",
    pad: "p-3.5",
  },
};

const TITLE_BUTTON =
  "rounded-sm text-left hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring";

/**
 * One story, drawn the same wherever it is listed: the public news pages,
 * the portal's news section, an article's related stories, the signed-in
 * list and the dashboard.
 *
 * Its title is a heading (`headingLevel`, chosen to fit the page's outline).
 * How it opens:
 * - `href`: the whole card is a link (`linkComponent` where the app's own
 *   router should draw it).
 * - `onOpen`: the title is a button, so the card can carry its own buttons
 *   (`actions`) without nesting one control in another.
 * - neither: inert, as in the settings preview, where nothing should
 *   navigate away.
 *
 * `badges` sit beside the title, `footer` below the text, `actions` beside
 * the card, on the `row` density.
 */
export function NewsCard({
  item,
  href = null,
  linkComponent,
  onOpen,
  density = "card",
  headingLevel = 2,
  badges,
  footer,
  actions,
  className,
}: {
  item: NewsSummary;
  href?: string | null;
  /** Draws the link, for an in-app address that should not reload the page. */
  linkComponent?: ComponentType<{
    href: string;
    className?: string;
    children?: ReactNode;
  }>;
  onOpen?: () => void;
  density?: NewsDensity;
  headingLevel?: 2 | 3 | 4;
  badges?: ReactNode;
  footer?: ReactNode;
  actions?: ReactNode;
  className?: string;
}) {
  const { t } = useT();
  const look = DENSITY[density];
  const feature = density === "feature";
  const row = density === "row";
  const cover = density === "card" || feature ? (item.cover ?? null) : null;
  const Heading: ElementType = `h${headingLevel}`;
  const link = href !== null;

  const heading = (
    <Heading
      className={cn(
        "font-semibold text-foreground",
        row ? "min-w-0" : "mt-2",
        look.title,
      )}
    >
      {onOpen && !link ? (
        <button type="button" onClick={onOpen} className={TITLE_BUTTON}>
          {item.title}
        </button>
      ) : (
        item.title
      )}
    </Heading>
  );

  const words = (
    <div className={cn("min-w-0", row && "flex-1")}>
      <NewsMeta item={item} />
      {row ? (
        <div className="mt-1 flex flex-wrap items-center gap-2">
          {heading}
          {badges}
        </div>
      ) : (
        heading
      )}
      <p
        className={cn(
          "mt-1 whitespace-pre-line text-muted-foreground",
          look.text,
        )}
      >
        {item.excerpt}
      </p>
      {footer && <div className="mt-2">{footer}</div>}
      {link && (density === "card" || feature) && (
        <span className="mt-3 block text-xs font-medium text-primary group-hover:underline">
          {t("landing.read_more", "Read more")}
          <span className="sr-only">: {item.title}</span>
        </span>
      )}
    </div>
  );

  const body = (
    <div
      className={cn(
        "flex min-w-0 flex-1 flex-col",
        feature && cover && "@4xl/news:flex-row",
      )}
    >
      {cover && (
        <div
          className={cn(
            "block aspect-[16/9] shrink-0 overflow-hidden bg-muted",
            feature && "@4xl/news:aspect-auto @4xl/news:w-1/2",
          )}
        >
          <NewsCoverPicture
            cover={cover}
            sizes={
              feature
                ? "(min-width: 1024px) 36rem, 100vw"
                : "(min-width: 1024px) 24rem, (min-width: 640px) 50vw, 100vw"
            }
            className="h-full transition-transform duration-300 group-hover:scale-[1.02] motion-reduce:transition-none motion-reduce:group-hover:scale-100"
          />
        </div>
      )}
      <div
        className={cn(
          "flex min-w-0 flex-1 flex-col",
          look.pad,
          row && "gap-3 sm:flex-row sm:items-start sm:justify-between",
        )}
      >
        {words}
        {actions}
      </div>
    </div>
  );

  const root = cn(
    "group @container/news flex min-w-0 flex-col overflow-hidden rounded-xl border border-border bg-card text-left transition-colors",
    link &&
      "hover:border-border-strong hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
    className,
  );

  const Anchor: ElementType = linkComponent ?? "a";

  return link ? (
    <Anchor href={href} className={root}>
      {body}
    </Anchor>
  ) : (
    <div className={root}>{body}</div>
  );
}
