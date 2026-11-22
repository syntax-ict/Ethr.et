"use client";

import { useT } from "@/lib/i18n/useT";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  ArticleView,
  type ArticleContent,
} from "@/features/news/components/article-view";
import type { Announcement } from "../api";

/** A saved announcement as the shared article view draws it. */
export function articleOf(a: Announcement): ArticleContent {
  return {
    public_id: a.public_id,
    title: a.title,
    body: a.body,
    category: a.category ?? null,
    featured: a.featured,
    priority: a.priority,
    published_at: a.published_at ?? null,
    cover: a.cover,
    attachments: (a.attachments ?? []).map((f) => ({ ...f, url: null })),
  };
}

/**
 * How a story reads on the organisation's news page, drawn by the same
 * `ArticleView` the public page uses, so what an editor previews is exactly
 * what the public will read.
 */
export function AnnouncementPreviewDialog({
  article,
  onClose,
}: {
  article: ArticleContent | null;
  onClose: () => void;
}) {
  const { t } = useT();

  return (
    <Dialog open={article !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>{t("announcements.preview", "Preview")}</DialogTitle>
          <DialogDescription>
            {t(
              "announcements.preview_desc",
              "This is how the story reads on your organisation's news page. Only stories marked for the website and published appear there.",
            )}
          </DialogDescription>
        </DialogHeader>
        {article && (
          <div className="@container rounded-lg border border-border p-5">
            <ArticleView article={article} />
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}
