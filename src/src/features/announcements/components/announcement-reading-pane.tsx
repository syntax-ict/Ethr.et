"use client";

import axios from "axios";
import { Megaphone } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { Sheet } from "@/components/ui/sheet";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { Skeleton } from "@/components/ui/skeleton";
import { EmptyState } from "@/components/shared/empty-state";
import { toast } from "sonner";
import { ArticleView } from "@/features/news/components/article-view";
import { downloadAnnouncementFile, useAnnouncement } from "../api";
import { articleOf } from "../article";
import { StatusBadge } from "@/components/shared/status-badge";
import { usePermissions } from "@/lib/hooks/usePermissions";

/**
 * One announcement, read in full, for everyone.
 *
 * Until this existed a reader could not open an announcement at all: the list
 * clamped the body to two lines, and only a manager had the preview. So the
 * notification that told someone an announcement was published led to a list
 * of first lines, and a long notice was unreadable in the product that sent
 * it.
 *
 * It opens on `?read={publicId}` rather than at a route of its own. A route
 * would need an entry in the shared-hosting rewrite rules (`.htaccess` group
 * 1), which is a deployment surface; a query string is the same shareable
 * address with none of that, and the browser's back button closes the panel.
 *
 * The story itself is drawn by `ArticleView`, the same component the public
 * article page uses, so a reader sees exactly what was published.
 */
export function AnnouncementReadingPane({
  publicId,
  onClose,
}: {
  publicId: string | null;
  onClose: () => void;
}) {
  const { t } = useT();
  const query = useAnnouncement(publicId);
  const { can } = usePermissions();
  const manager = can.manageAnnouncements;

  // Only a 404 means the story is not the reader's to see. A network failure
  // or a 500 must not be reported as a notice that was taken down: the reader
  // would believe a withdrawal that never happened, and this product is used
  // where the connection drops. `QueryBoundary`'s own error state says what is
  // true instead, and offers the retry.
  const notAvailable =
    axios.isAxiosError(query.error) && query.error.response?.status === 404;

  return (
    <Sheet
      open={publicId !== null}
      onOpenChange={(open) => !open && onClose()}
      title={t("announcements.reading_title", "Announcement")}
      description={t(
        "announcements.reading_desc",
        "The announcement in full, with anything attached to it.",
      )}
      side="right"
      className="w-full max-w-2xl overflow-y-auto"
    >
      <div className="@container p-5">
        <QueryBoundary
          query={query}
          loading={
            <div className="space-y-3">
              <Skeleton className="h-4 w-32" />
              <Skeleton className="h-7 w-3/4" />
              <Skeleton className="h-24 w-full" />
            </div>
          }
          error={
            // A story the reader may not see answers exactly as a missing one
            // does, so this one state says both without telling them which.
            notAvailable ? (
              <EmptyState
                icon={Megaphone}
                title={t(
                  "announcements.not_available_title",
                  "This announcement is not available",
                )}
                description={t(
                  "announcements.not_available_desc",
                  "It may have been taken down, or it was not addressed to you.",
                )}
              />
            ) : undefined
          }
          isEmpty={() => false}
        >
          {(announcement) => (
            <>
              {/*
               * A manager reads every state, so the pane has to say which one
               * it is drawing. Without it a draft and a scheduled story look
               * exactly like a published one, while the row behind the panel
               * carries a badge for the same story.
               */}
              {manager && announcement.status !== "published" && (
                <div className="mb-3">
                  <StatusBadge
                    status={announcement.status}
                    domain="announcement"
                  />
                </div>
              )}
              <ArticleView
                // Featured means "first on the organisation's public
                // website". It is the publishing desk's business, not a
                // reader's, and the dashboard card already withholds it.
                article={{ ...articleOf(announcement), featured: false }}
                onDownload={(file) =>
                  downloadAnnouncementFile(announcement.public_id, file).catch(
                    () =>
                      toast.error(
                        t(
                          "announcements.download_failed",
                          "The document could not be downloaded",
                        ),
                      ),
                  )
                }
              />
              {announcement.author?.name && (
                <p className="mt-6 border-t border-border pt-4 text-xs text-muted-foreground">
                  {t("announcements.posted_by", "Posted by :name", {
                    name: announcement.author.name,
                  })}
                </p>
              )}
            </>
          )}
        </QueryBoundary>
      </div>
    </Sheet>
  );
}
