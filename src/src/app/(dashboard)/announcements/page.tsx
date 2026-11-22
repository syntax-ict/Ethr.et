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
  useAllAnnouncements,
  useDeleteAnnouncement,
  useManagedAnnouncements,
  useUpdateAnnouncement,
  type Announcement,
  type UpdateAnnouncementPayload,
} from "@/features/announcements/api";
import { AnnouncementEditor } from "@/features/announcements/components/announcement-editor";
import {
  AnnouncementPreviewDialog,
  articleOf,
} from "@/features/announcements/components/announcement-preview-dialog";
import {
  AnnouncementRowActions,
  type Status,
} from "@/features/announcements/components/announcement-row-actions";
import { fileSize } from "@/features/news/news-format";
import { apiErrorDetail, apiErrorMessage } from "@/lib/api/error-message";
import { NewsMeta } from "@/features/news/components/news-card";
import { StatusBadge } from "@/components/shared/status-badge";

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
  const [preview, setPreview] = useState<Announcement | null>(null);
  const [pendingDelete, setPendingDelete] = useState<Announcement | null>(null);

  const reading = useAllAnnouncements();
  const managed = useManagedAnnouncements(manager);
  const { data, isLoading, isError, refetch } = manager ? managed : reading;
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

  const announcements: Announcement[] = data ?? [];
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
          description={t(
            "announcements.empty_desc",
            "No announcements published yet",
          )}
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
                            {a.title}
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
                          onPreview={setPreview}
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

      <AnnouncementPreviewDialog
        article={preview ? articleOf(preview) : null}
        onClose={() => setPreview(null)}
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
