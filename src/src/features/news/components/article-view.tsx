"use client";

import { useT } from "@/lib/i18n/useT";
import type { LandingAnnouncement } from "@/features/auth/landing-feed";
import { AttachmentList, type StoryFile } from "./attachment-list";
import { NewsCoverPicture, NewsMeta } from "./news-card";
import { PlainText } from "./plain-text";

/**
 * A story as the article draws it: the generated public article's own keys
 * (the signed-in resource carries the same ones), `featured` optional where
 * a reader has no business seeing it, and `attachments` open to the
 * editor's pending files.
 */
export type StoryArticle = Pick<
  LandingAnnouncement,
  "public_id" | "title" | "body" | "category" | "priority" | "published_at"
> &
  Partial<Pick<LandingAnnouncement, "featured" | "cover">> & {
    attachments?: StoryFile[];
  };

/**
 * A story in full: its date, category and flags, its title, its cover photo
 * when it has one, its text as paragraphs with its https links live, and its
 * documents to download. The public article page, the reading pane and the
 * publisher's preview all draw this, so what an editor previews is what the
 * public will read.
 *
 * `headingLevel` is the title's level: 1 on the article's own page, lower in
 * the reading pane and the preview, which sit on a page that has its own
 * h1. The documents' heading is one level below the title.
 */
export function ArticleView({
  article,
  headingLevel = 1,
  onDownload,
}: {
  article: StoryArticle;
  headingLevel?: 1 | 2 | 3;
  /**
   * How to fetch a document that has no public address. In the app a signed-in
   * reader downloads through the API as a blob, so without this the reading
   * pane listed documents nobody could open. The public article page passes
   * nothing and keeps its links.
   */
  onDownload?: (file: { id: string; name: string }) => void;
}) {
  const { t } = useT();
  const Title = `h${headingLevel}` as const;
  const Documents = `h${headingLevel + 1}` as "h2" | "h3" | "h4";

  return (
    <article className="min-w-0">
      <NewsMeta item={article} />
      <Title className="mt-3 text-balance text-2xl font-bold leading-tight text-foreground @sm:text-3xl">
        {article.title}
      </Title>
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
      <PlainText
        text={article.body}
        className="mt-6 max-w-prose space-y-4 text-base leading-relaxed text-foreground"
      />
      {article.attachments && article.attachments.length > 0 && (
        <section
          aria-labelledby={`documents-${article.public_id}`}
          className="mt-8 max-w-prose"
        >
          <Documents
            id={`documents-${article.public_id}`}
            className="text-sm font-semibold text-foreground"
          >
            {t("news.documents", "Documents")}
          </Documents>
          <AttachmentList
            files={article.attachments}
            onDownload={onDownload}
            className="mt-2"
          />
        </section>
      )}
    </article>
  );
}
