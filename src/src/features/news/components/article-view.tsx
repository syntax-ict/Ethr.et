"use client";

import { useT } from "@/lib/i18n/useT";
import { paragraphs, segments } from "../news-format";
import { NewsMeta } from "./news-card";

export interface ArticleContent {
  public_id: string;
  title: string;
  body: string;
  category: string | null;
  featured?: boolean;
  priority: string;
  published_at: string | null;
}

/**
 * A story in full: its date, category and flags, its title, and its text as
 * paragraphs with its https links live. The public article page and the
 * publisher's preview both draw this, so what an editor previews is exactly
 * what the public will read.
 *
 * Text only: paragraphs come from blank lines and links from https
 * addresses, and everything else is rendered as text (React escapes it), so
 * nothing a story contains can become markup. Links open in a new tab and
 * carry no referrer and no endorsement (`nofollow ugc`).
 */
export function ArticleView({ article }: { article: ArticleContent }) {
  const { t } = useT();

  return (
    <article className="min-w-0">
      <NewsMeta
        item={{
          ...article,
          excerpt: "",
          featured: article.featured ?? false,
        }}
      />
      <h1 className="mt-3 text-balance text-2xl font-bold leading-tight text-foreground @sm:text-3xl">
        {article.title}
      </h1>
      <div className="mt-6 max-w-prose space-y-4 text-base leading-relaxed text-foreground">
        {paragraphs(article.body).map((paragraph, i) => (
          <p key={i} className="whitespace-pre-line">
            {segments(paragraph).map((segment, j) =>
              segment.kind === "link" ? (
                <a
                  key={j}
                  href={segment.href}
                  target="_blank"
                  rel="noopener noreferrer nofollow ugc"
                  className="break-words text-primary underline underline-offset-4 hover:no-underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                  {segment.href}
                  <span className="sr-only">
                    {" "}
                    {t("news.opens_new_tab", "(opens in a new tab)")}
                  </span>
                </a>
              ) : (
                <span key={j}>{segment.text}</span>
              ),
            )}
          </p>
        ))}
      </div>
    </article>
  );
}
