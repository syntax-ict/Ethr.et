"use client";

import { useState } from "react";
import { FileText, Globe, Megaphone, Plus } from "lucide-react";
import { toast } from "sonner";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import { PageActions } from "@/components/shared/page-actions";
import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { usePermissions } from "@/lib/hooks/usePermissions";
import {
  downloadAnnouncementFile,
  useAnnouncements,
  useDeleteAnnouncement,
  useManagedAnnouncements,
  useUpdateAnnouncement,
  type Announcement,
  type UpdateAnnouncementPayload,
} from "@/features/announcements/api";
import { AnnouncementEditor } from "@/features/announcements/components/announcement-editor";
import { AnnouncementReadingPane } from "@/features/announcements/components/announcement-reading-pane";
import {
  AnnouncementRowActions,
  type Status,
} from "@/features/announcements/components/announcement-row-actions";
import { fileSize } from "@/features/news/news-format";
import { apiErrorDetail, apiErrorMessage } from "@/lib/api/error-message";
import { NewsMeta } from "@/features/news/components/news-card";
import { StatusBadge } from "@/components/shared/status-badge";
import { PaginationControls } from "@/components/shared/pagination-controls";
import { useQueryFilters } from "@/lib/hooks/useQueryFilters";

/**
 * Announcements: the reading list for everyone, and the publishing desk for
 * whoever may manage them. A manager sees every state (draft, scheduled,
 * published, archived), writes and edits, marks a story for the
 * organisation's public website and features it there, schedules it, takes
 * it back to drafts, archives it, and previews exactly what the public page
 * will show. Every rule is the server's; this screen only asks.
 */
export default function AnnouncementsPage() {
  const { t } = useT();
  const { formatDateTime } = useDateFormatters();
  const { can } = usePermissions();
  const manager = can.manageAnnouncements;
  // `undefined`: closed. `null`: a new story. An announcement: editing it.
  const [editor, setEditor] = useState<Announcement | null | undefined>(
    undefined,
  );
  const [pendingDelete, setPendingDelete] = useState<Announcement | null>(null);
  const [pendingPublish, setPendingPublish] = useState<Announcement | null>(
    null,
  );

  // The open story and the page live in the address, so a reader can be sent
  // straight to one and the back button closes the panel. `useQueryFilters`
  // reads the address through `useSyncExternalStore` rather than
  // `useSearchParams`, which is what keeps this page prerenderable for the
  // static export without a Suspense boundary.
  const { values, set } = useQueryFilters({ read: "", page: "1" });
  const reading = values.read === "" ? null : values.read;
  const page = Math.max(1, Number(values.page) || 1);

  const live = useAnnouncements({ page });
  const managed = useManagedAnnouncements(manager, page);
  const query = manager ? managed : live;
  const { data, isLoading, isError, refetch } = query;
  const updateAnnouncement = useUpdateAnnouncement();
  const deleteAnnouncement = useDeleteAnnouncement();

  /** One-click publishing actions on a row; the server records each one. */
  function act(
    a: Announcement,
    payload: UpdateAnnouncementPayload,
    done: string,
  ) {
    updateAnnouncement.mutate(
      { publicId: a.public_id, payload },
      {
        onSuccess: () => toast.success(done),
        onError: (error) =>
          toast.error(
            apiErrorDetail(error) ??
              t(
                "announcements.save_failed",
                "The announcement could not be saved",
              ),
          ),
      },
    );
  }

  function remove(publicId: string) {
    deleteAnnouncement.mutate(publicId, {
      onSuccess: () => {
        setPendingDelete(null);
        toast.success(t("announcements.deleted", "Announcement deleted"));
      },
      onError: (err) =>
        toast.error(
          apiErrorMessage(
            err,
            t("announcements.delete_failed", "Failed to delete announcement"),
          ),
        ),
    });
  }

  const announcements: Announcement[] = data?.data ?? [];
  const statusLabel = (a: Announcement): string => {
    switch (a.status as Status) {
      case "draft":
        return t("announcements.status_draft", "Draft");
      case "scheduled":
        return t("announcements.status_scheduled", "Scheduled for :date", {
          date: a.published_at ? formatDateTime(a.published_at) : "",
        });
      case "archived":
        return t("announcements.status_archived", "Archived");
      default:
        return t("announcements.status_published", "Published");
    }
  };

  return (
    <div className="space-y-6">
      <PageActions
        actions={
          manager && (
            <Button onClick={() => setEditor(null)}>
              <Plus className="mr-2 h-4 w-4" aria-hidden="true" />
              {t("announcements.new", "New Announcement")}
            </Button>
          )
        }
      />

      {isLoading ? (
        <div className="space-y-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-24" />
          ))}
        </div>
      ) : isError ? (
        <ErrorState onRetry={() => refetch()} />
      ) : announcements.length === 0 ? (
        <EmptyState
          icon={Megaphone}
          title={t("announcements.empty_title", "No announcements")}
          description={
            // A reader is told there is nothing to read; a manager is told
            // what to do about it. One sentence served both and spoke to
            // neither.
            manager
              ? t(
                  "announcements.empty_desc_manager",
                  "Write the first one with New Announcement. It reaches everyone you address it to.",
                )
              : t("announcements.empty_desc", "No announcements published yet")
          }
        />
      ) : (
        <ul className="space-y-3">
          {announcements.map((a) => {
            const status = (a.status ?? "published") as Status;
            return (
              <li key={a.public_id}>
                <Card>
                  <CardContent className="p-4">
                    <div className="flex flex-col gap-3 @container sm:flex-row sm:items-start sm:justify-between">
                      <div className="min-w-0 flex-1">
                        <NewsMeta
                          item={{ ...a, featured: manager && a.featured }}
                        />
                        <div className="mt-1 flex flex-wrap items-center gap-2">
                          <h3 className="min-w-0 break-words text-sm font-semibold text-foreground">
                            {/*
                             * The title opens the story. A button rather than
                             * a link: the panel is a query on this same page,
                             * and `useQueryFilters` writes the address itself.
                             * The whole row is not the target, because a
                             * manager's row carries its own buttons.
                             */}
                            <button
                              type="button"
                              onClick={() =>
                                set({ read: a.public_id }, { push: true })
                              }
                              className="rounded-sm text-left hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                              {a.title}
                            </button>
                          </h3>
                          {manager && (
                            <StatusBadge
                              status={status}
                              domain="announcement"
                              label={statusLabel(a)}
                              className="text-[11px]"
                            />
                          )}
                          {manager && a.show_on_landing && (
                            <Badge
                              variant="outline"
                              className="gap-1 border-0 bg-primary-soft text-[11px] text-primary-on-soft"
                            >
                              <Globe className="h-3 w-3" aria-hidden="true" />
                              {t("announcements.on_website", "On website")}
                            </Badge>
                          )}
                        </div>
                        <p className="mt-1 whitespace-pre-line text-sm text-muted-foreground">
                          {a.excerpt}
                        </p>
                        {(a.attachments ?? []).length > 0 && (
                          <ul className="mt-2 flex flex-wrap gap-2">
                            {(a.attachments ?? []).map((f) => (
                              <li key={f.id}>
                                <button
                                  type="button"
                                  onClick={() =>
                                    downloadAnnouncementFile(
                                      a.public_id,
                                      f,
                                    ).catch(() =>
                                      toast.error(
                                        t(
                                          "announcements.download_failed",
                                          "The document could not be downloaded",
                                        ),
                                      ),
                                    )
                                  }
                                  className="inline-flex min-h-9 max-w-full items-center gap-1.5 rounded-md border border-border px-2.5 py-1 text-xs font-medium text-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                  <FileText
                                    className="h-3.5 w-3.5 shrink-0 text-primary"
                                    aria-hidden="true"
                                  />
                                  <span className="truncate">{f.name}</span>
                                  <span className="shrink-0 text-muted-foreground">
                                    {fileSize(f.size)}
                                  </span>
                                </button>
                              </li>
                            ))}
                          </ul>
                        )}
                      </div>
                      {manager && (
                        <AnnouncementRowActions
                          a={a}
                          status={status}
                          busy={updateAnnouncement.isPending}
                          onAct={act}
                          onPublish={setPendingPublish}
                          onPreview={(story) =>
                            set({ read: story.public_id }, { push: true })
                          }
                          onEdit={setEditor}
                          onDelete={setPendingDelete}
                        />
                      )}
                    </div>
                  </CardContent>
                </Card>
              </li>
            );
          })}
        </ul>
      )}

      {!isLoading && !isError && (
        <PaginationControls
          meta={data?.meta}
          onPageChange={(next) => set({ page: String(next) })}
          disabled={query.isFetching}
        />
      )}

      <ConfirmDialog
        open={pendingDelete !== null}
        onOpenChange={(open) => {
          if (!open) setPendingDelete(null);
        }}
        title={t("announcements.confirm_delete_title", "Delete announcement?")}
        description={t(
          "announcements.confirm_delete_desc",
          "It is removed for everyone and cannot be recovered.",
        )}
        confirmLabel={t("common.delete", "Delete")}
        variant="destructive"
        loading={deleteAnnouncement.isPending}
        onConfirm={() => pendingDelete && remove(pendingDelete.public_id)}
      />

      {/*
       * Publishing is the one row action that cannot be taken back: the
       * audience is notified exactly once (`notified_at`), so unpublishing
       * afterwards hides the story but unsends nothing. Unpublish, archive and
       * restore stay one click, because each of them reverses.
       */}
      <ConfirmDialog
        open={pendingPublish !== null}
        onOpenChange={(open) => {
          if (!open) setPendingPublish(null);
        }}
        title={t("announcements.confirm_publish_title", "Publish now?")}
        description={t(
          "announcements.confirm_publish_desc",
          "Everyone it is addressed to is notified once. Taking it down afterwards does not unsend that.",
        )}
        confirmLabel={t("announcements.publish", "Publish")}
        loading={updateAnnouncement.isPending}
        onConfirm={() => {
          if (!pendingPublish) return;
          act(
            pendingPublish,
            { published_at: new Date().toISOString() },
            t("announcements.published", "Announcement published"),
          );
          setPendingPublish(null);
        }}
      />

      <AnnouncementReadingPane
        publicId={reading}
        onClose={() => set({ read: "" })}
      />

      {editor !== undefined && (
        <AnnouncementEditor
          editing={editor}
          onClose={() => setEditor(undefined)}
        />
      )}
    </div>
  );
}
