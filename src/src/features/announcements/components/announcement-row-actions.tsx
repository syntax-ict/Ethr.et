"use client";

import {
  Archive,
  ArchiveRestore,
  Eye,
  Pencil,
  Send,
  Trash2,
  Undo2,
} from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { Button } from "@/components/ui/button";
import type {
  Announcement,
  UpdateAnnouncementPayload,
} from "@/features/announcements/api";

export type Status = "draft" | "scheduled" | "published" | "archived";

/**
 * What a manager can do to one announcement from its row: the one-click
 * publishing actions (the server records each one), then preview, edit and
 * delete. The page owns the mutations; this only asks.
 */
export function AnnouncementRowActions({
  a,
  status,
  busy,
  onAct,
  onPublish,
  onPreview,
  onEdit,
  onDelete,
}: {
  a: Announcement;
  status: Status;
  busy: boolean;
  onAct: (
    a: Announcement,
    payload: UpdateAnnouncementPayload,
    done: string,
  ) => void;
  /** Asks first: publishing notifies the audience once and cannot be recalled. */
  onPublish: (a: Announcement) => void;
  onPreview: (a: Announcement) => void;
  onEdit: (a: Announcement) => void;
  onDelete: (a: Announcement) => void;
}) {
  const { t } = useT();

  return (
    <div className="flex shrink-0 flex-wrap gap-1">
      {(status === "draft" || status === "scheduled") && (
        <Button
          variant="outline"
          size="sm"
          disabled={busy}
          onClick={() => onPublish(a)}
        >
          <Send className="mr-1.5 h-3.5 w-3.5" aria-hidden="true" />
          {t("announcements.publish_now", "Publish now")}
        </Button>
      )}
      {(status === "published" || status === "scheduled") && (
        <Button
          variant="ghost"
          size="sm"
          disabled={busy}
          onClick={() =>
            onAct(
              a,
              { published_at: null },
              t("announcements.unpublished", "Moved back to drafts"),
            )
          }
        >
          <Undo2 className="mr-1.5 h-3.5 w-3.5" aria-hidden="true" />
          {t("announcements.unpublish", "Unpublish")}
        </Button>
      )}
      {status === "published" && (
        <Button
          variant="ghost"
          size="sm"
          disabled={busy}
          onClick={() =>
            onAct(
              a,
              { expires_at: new Date().toISOString() },
              t("announcements.archived", "Archived"),
            )
          }
        >
          <Archive className="mr-1.5 h-3.5 w-3.5" aria-hidden="true" />
          {t("announcements.archive", "Archive")}
        </Button>
      )}
      {status === "archived" && (
        <Button
          variant="ghost"
          size="sm"
          disabled={busy}
          onClick={() =>
            onAct(
              a,
              { expires_at: null },
              t("announcements.restored", "Restored"),
            )
          }
        >
          <ArchiveRestore className="mr-1.5 h-3.5 w-3.5" aria-hidden="true" />
          {t("announcements.restore", "Restore")}
        </Button>
      )}
      <Button
        variant="ghost"
        size="sm"
        aria-label={`${t("announcements.open", "Open")}: ${a.title}`}
        onClick={() => onPreview(a)}
      >
        <Eye className="h-4 w-4 text-muted-foreground" aria-hidden="true" />
      </Button>
      <Button
        variant="ghost"
        size="sm"
        aria-label={`${t("common.edit", "Edit")}: ${a.title}`}
        onClick={() => onEdit(a)}
      >
        <Pencil className="h-4 w-4 text-muted-foreground" aria-hidden="true" />
      </Button>
      <Button
        variant="ghost"
        size="sm"
        aria-label={`${t("common.delete", "Delete")}: ${a.title}`}
        onClick={() => onDelete(a)}
      >
        <Trash2 className="h-4 w-4 text-muted-foreground" aria-hidden="true" />
      </Button>
    </div>
  );
}
