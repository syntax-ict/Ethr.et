"use client";

import { FileText } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { fileSize, paragraphs, segments } from "../news-format";
import { NewsCoverPicture, NewsMeta, type NewsCoverImage } from "./news-card";

export interface ArticleContent {
  public_id: string;
  title: string;
  body: string;
  category: string | null;
  featured?: boolean;
  priority: string;
  published_at: string | null;
  cover?: NewsCoverImage | null;
  /** Its documents; `url` is null where nothing may be downloaded (previews). */
  attachments?: Array<{
    id: string;
    name: string;
    size: number;
    url: string | null;
  }>;
}

/**
 * A story in full: its date, category and flags, its title, its cover photo
 * when it has one, its text as paragraphs with its https links live, and its
 * documents to download. The public article page and the
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
      {article.cover && (
        <figure className="mt-6 max-w-3xl overflow-hidden rounded-xl border border-border bg-muted">
          <NewsCoverPicture
            cover={article.cover}
            sizes="(min-width: 768px) 48rem, 100vw"
            eager
            className="h-auto"
          />
        </figure>
      )}
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
      {article.attachments && article.attachments.length > 0 && (
        <section
          aria-labelledby={`documents-${article.public_id}`}
          className="mt-8 max-w-prose"
        >
          <h2
            id={`documents-${article.public_id}`}
            className="text-sm font-semibold text-foreground"
          >
            {t("news.documents", "Documents")}
          </h2>
          <ul className="mt-2 divide-y divide-border overflow-hidden rounded-xl border border-border">
            {article.attachments.map((file) => {
              const body = (
                <>
                  <FileText
                    className="h-5 w-5 shrink-0 text-primary"
                    aria-hidden="true"
                  />
                  <span className="min-w-0 flex-1 truncate text-sm font-medium text-foreground">
                    {file.name}
                  </span>
                  <span className="shrink-0 text-xs text-muted-foreground">
                    PDF, {fileSize(file.size)}
                  </span>
                </>
              );
              return (
                <li key={file.id}>
                  {file.url ? (
                    <a
                      href={file.url}
                      download={file.name}
                      className="flex min-h-11 items-center gap-3 px-4 py-2.5 transition-colors hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
                    >
                      {body}
                      <span className="sr-only">
                        {" "}
                        {t("news.download", "Download")}
                      </span>
                    </a>
                  ) : (
                    <div className="flex min-h-11 items-center gap-3 px-4 py-2.5">
                      {body}
                    </div>
                  )}
                </li>
              );
            })}
          </ul>
        </section>
      )}
    </article>
  );
}
