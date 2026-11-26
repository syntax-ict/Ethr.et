"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { Globe, Megaphone, Plus } from "lucide-react";
import { toast } from "sonner";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { Button } from "@/components/ui/button";
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
import { AttachmentList } from "@/features/news/components/attachment-list";
import { apiErrorDetail, apiErrorMessage } from "@/lib/api/error-message";
import { NewsCard } from "@/features/news/components/news-card";
import {
  StoryBrowser,
  useStoryFilters,
} from "@/features/news/components/story-browser";
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
  const [pendingDelete, setPendingDelete] = useState<Announcement | null>(null);
  const [pendingPublish, setPendingPublish] = useState<Announcement | null>(
    null,
  );

  // The open story and the page live in the address, so a reader can be sent
  // straight to one and the back button closes the panel. `useQueryFilters`
  // (under `useStoryFilters`) reads the address through
  // `useSyncExternalStore` rather than `useSearchParams`, which is what keeps
  // this page prerenderable for the static export without a Suspense
  // boundary.
  const filters = useStoryFilters({ extra: { read: "" } });
  const { set } = filters;
  const reading = filters.values.read === "" ? null : filters.values.read;
  const page = filters.query.page;

  // Opening a story adds a history entry, so Back closes the panel. Closing it
  // by the X or Escape must undo that entry rather than replace it: replacing
  // would leave two entries for the same list, and the next Back press would
  // visibly do nothing. Someone who arrived straight on `?read=` has no entry
  // of ours to undo, so for them the address is rewritten in place and Back
  // still leaves the page.
  const openedHere = useRef(false);
  // What the reader was on when they opened it, so closing puts them back
  // there. Read from the document rather than passed in: a click and an Enter
  // both leave the control focused, and every opener on this page is a button.
  const opener = useRef<HTMLElement | null>(null);
  const openStory = useCallback(
    (publicId: string) => {
      opener.current =
        document.activeElement instanceof HTMLElement
          ? document.activeElement
          : null;
      openedHere.current = true;
      set({ read: publicId }, { push: true });
    },
    [set],
  );
  const closeStory = useCallback(() => {
    if (openedHere.current) {
      openedHere.current = false;
      window.history.back();
      return;
    }
    set({ read: "" });
  }, [set]);

  // Put focus back on the story that was opened, by Escape, by the X and by
  // the back button alike. The panel closes a step after the address changes,
  // so the dialog's own focus restore has already run against a page state
  // that no longer holds it, and a keyboard reader was left at the top of the
  // list having to find their place again.
  const wasReading = useRef<string | null>(null);
  useEffect(() => {
    const closed = wasReading.current !== null && reading === null;
    wasReading.current = reading;
    if (!closed) return;
    const target = opener.current;
    opener.current = null;
    if (target !== null && target.isConnected) target.focus();
  }, [reading]);

  const live = useAnnouncements({ page });
  const managed = useManagedAnnouncements(manager, page);
  const query = manager ? managed : live;
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

      <StoryBrowser
        filters={filters}
        query={query}
        layout="list"
        loading={
          <div className="space-y-3">
            {Array.from({ length: 3 }).map((_, i) => (
              <Skeleton key={i} className="h-24" />
            ))}
          </div>
        }
        error={<ErrorState onRetry={() => query.refetch()} />}
        empty={() => (
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
                : t(
                    "announcements.empty_desc",
                    "No announcements published yet",
                  )
            }
          />
        )}
        renderItem={(a) => {
          const status = (a.status ?? "published") as Status;
          const files = a.attachments ?? [];
          return (
            <NewsCard
              item={{ ...a, featured: manager && a.featured }}
              density="row"
              // The title opens the story. A button rather than a link: the
              // panel is a query on this same page, and the filters hook
              // writes the address itself. The whole row is not the target,
              // because a manager's row carries its own buttons.
              onOpen={() => openStory(a.public_id)}
              badges={
                manager && (
                  <>
                    <StatusBadge
                      status={status}
                      domain="announcement"
                      label={statusLabel(a)}
                      className="text-[11px]"
                    />
                    {a.show_on_landing && (
                      <Badge
                        variant="outline"
                        className="gap-1 border-0 bg-primary-soft text-[11px] text-primary-on-soft"
                      >
                        <Globe className="h-3 w-3" aria-hidden="true" />
                        {t("announcements.on_website", "On website")}
                      </Badge>
                    )}
                  </>
                )
              }
              footer={
                files.length > 0 && (
                  <AttachmentList
                    variant="chips"
                    files={files}
                    onDownload={(file) =>
                      downloadAnnouncementFile(a.public_id, file).catch(() =>
                        toast.error(
                          t(
                            "announcements.download_failed",
                            "The document could not be downloaded",
                          ),
                        ),
                      )
                    }
                  />
                )
              }
              actions={
                manager && (
                  <AnnouncementRowActions
                    a={a}
                    status={status}
                    busy={updateAnnouncement.isPending}
                    onAct={act}
                    onPublish={setPendingPublish}
                    onPreview={(story) => openStory(story.public_id)}
                    onEdit={setEditor}
                    onDelete={setPendingDelete}
                  />
                )
              }
            />
          );
        }}
      />

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

      <AnnouncementReadingPane publicId={reading} onClose={closeStory} />

      {editor !== undefined && (
        <AnnouncementEditor
          editing={editor}
          onClose={() => setEditor(undefined)}
        />
      )}
    </div>
  );
}
