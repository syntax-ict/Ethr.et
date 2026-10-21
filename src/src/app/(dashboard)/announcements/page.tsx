"use client";

import { useEffect, useRef, useState } from "react";
import {
  Archive,
  ArchiveRestore,
  Eye,
  FileText,
  Globe,
  ImageUp,
  Loader2,
  Megaphone,
  Pencil,
  Plus,
  Send,
  Star,
  Trash2,
  Undo2,
  X,
} from "lucide-react";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { usePermissions } from "@/lib/hooks/usePermissions";
import {
  useAllAnnouncements,
  useCreateAnnouncement,
  downloadAnnouncementFile,
  useDeleteAnnouncement,
  useManagedAnnouncements,
  useRemoveAnnouncementCover,
  useRemoveAnnouncementFile,
  useUpdateAnnouncement,
  useUploadAnnouncementCover,
  useUploadAnnouncementFile,
  type Announcement,
  type AnnouncementPriority,
  type CreateAnnouncementPayload,
  type UpdateAnnouncementPayload,
} from "@/features/announcements/api";
import {
  NEWS_CATEGORIES,
  type NewsCategory,
} from "@/features/auth/landing-feed";
import { ArticleView } from "@/features/news/components/article-view";
import type { NewsCoverImage } from "@/features/news/components/news-card";
import { fileSize } from "@/features/news/news-format";
import { useUploadLimit } from "@/features/auth/api";
import {
  overServerLimit,
  SERVER_LIMIT_FALLBACK,
  SERVER_LIMIT_KEY,
  shrinkImage,
  sizeLabel,
} from "@/lib/uploads/upload-limit";

import { apiErrorDetail, apiErrorMessage } from "@/lib/api/error-message";
import { toast } from "sonner";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { cn } from "@/lib/utils";
import { ErrorState } from "@/components/shared/error-state";

type Publishing = "keep" | "now" | "schedule" | "draft";
type Status = "draft" | "scheduled" | "published" | "archived";

interface Form {
  title: string;
  body: string;
  priority: AnnouncementPriority;
  category: NewsCategory | "none";
  showOnLanding: boolean;
  featured: boolean;
  publishing: Publishing;
  /** `datetime-local` value, in the browser's time. */
  publishAt: string;
  /** A newly chosen cover photo, sent once the story is saved. */
  coverFile: File | null;
  /** A local preview of `coverFile`, revoked when it is replaced. */
  coverPreview: string | null;
  /** Take the story's current cover off when it is saved. */
  removeCover: boolean;
  /** PDFs chosen to attach, sent once the story is saved. */
  newFiles: File[];
  /** Ids of documents to take off when the story is saved. */
  removedFiles: string[];
}

const EMPTY_FORM: Form = {
  title: "",
  body: "",
  priority: "normal",
  category: "none",
  showOnLanding: false,
  featured: false,
  publishing: "now",
  publishAt: "",
  coverFile: null,
  coverPreview: null,
  removeCover: false,
  newFiles: [],
  removedFiles: [],
};

const COVER_TYPES = ["image/png", "image/jpeg", "image/webp"];
const COVER_MAX_BYTES = 5 * 1024 * 1024;
const FILES_MAX = 3;
const FILE_MAX_BYTES = 5 * 1024 * 1024;

const priorityColors: Record<string, string> = {
  urgent: "bg-destructive-soft text-destructive-on-soft",
  high: "bg-warning-soft text-warning-on-soft",
  normal: "bg-info-soft text-info-on-soft",
  low: "bg-muted text-muted-foreground",
};

const statusColors: Record<Status, string> = {
  draft: "bg-muted text-muted-foreground",
  scheduled: "bg-info-soft text-info-on-soft",
  published: "bg-success-soft text-success-on-soft",
  archived: "bg-muted text-muted-foreground",
};

/** `datetime-local` for a stored time, in the browser's time zone. */
function toLocalInput(iso: string | null | undefined): string {
  if (!iso) return "";
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

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
  const { formatDate, formatDateTime } = useDateFormatters();
  const { can } = usePermissions();
  const manager = can.manageAnnouncements;
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<Announcement | null>(null);
  const [form, setForm] = useState<Form>(EMPTY_FORM);
  const [preview, setPreview] = useState<Announcement | Form | null>(null);
  const [pendingDelete, setPendingDelete] = useState<Announcement | null>(null);

  const reading = useAllAnnouncements();
  const managed = useManagedAnnouncements(manager);
  const { data, isLoading, isError, refetch } = manager ? managed : reading;
  const createAnnouncement = useCreateAnnouncement();
  const updateAnnouncement = useUpdateAnnouncement();
  const deleteAnnouncement = useDeleteAnnouncement();
  const uploadCover = useUploadAnnouncementCover();
  const removeCover = useRemoveAnnouncementCover();
  const uploadFile = useUploadAnnouncementFile();
  const removeFile = useRemoveAnnouncementFile();
  const fileInput = useRef<HTMLInputElement>(null);
  const coverInput = useRef<HTMLInputElement>(null);
  const serverLimit = useUploadLimit();

  /** A file over the server's limit, said plainly before it is sent. */
  function refuseOverServer(file: File, formMax: number): boolean {
    const limit = overServerLimit(file, formMax, serverLimit);
    if (limit === null) return false;
    toast.error(
      t(SERVER_LIMIT_KEY, SERVER_LIMIT_FALLBACK, {
        size: sizeLabel(file.size),
        max: sizeLabel(limit),
      }),
    );
    return true;
  }

  // A chosen photo's preview lives until it is replaced or the form closes.
  useEffect(() => {
    const url = form.coverPreview;
    return () => {
      if (url) URL.revokeObjectURL(url);
    };
  }, [form.coverPreview]);

  function openNew() {
    setEditing(null);
    setForm(EMPTY_FORM);
    setDialogOpen(true);
  }

  function openEdit(a: Announcement) {
    setEditing(a);
    setForm({
      title: a.title,
      body: a.body,
      // The resource types these as plain strings; the request rules only
      // ever store one of the listed values.
      priority: a.priority as AnnouncementPriority,
      category: (a.category as NewsCategory | null) ?? "none",
      showOnLanding: a.show_on_landing,
      featured: a.featured,
      publishing: "keep",
      publishAt: toLocalInput(a.published_at),
      coverFile: null,
      coverPreview: null,
      removeCover: false,
      newFiles: [],
      removedFiles: [],
    });
    setDialogOpen(true);
  }

  function closeDialog() {
    setDialogOpen(false);
    setEditing(null);
    setForm(EMPTY_FORM);
  }

  /** Only a whole-organisation announcement can be public (server rule). */
  const canBePublic = !editing || editing.target_type === "all";

  function publishedAt(): {
    published_at?: string | null;
    publish_now?: boolean;
  } {
    switch (form.publishing) {
      case "now":
        return editing
          ? { published_at: new Date().toISOString() }
          : { publish_now: true };
      case "draft":
        return editing ? { published_at: null } : { publish_now: false };
      case "schedule":
        return { published_at: new Date(form.publishAt).toISOString() };
      default:
        return {};
    }
  }

  /** The cover the story will have once saved, for the form and its preview. */
  function formCover(): NewsCoverImage | null {
    if (form.coverPreview) {
      // A local file has no narrower copies; the size only reserves space.
      return { url: form.coverPreview, srcset: "", width: 1600, height: 900 };
    }
    return form.removeCover ? null : (editing?.cover ?? null);
  }

  /** The documents the story will carry once saved, for the form and preview. */
  function formFiles(): Array<{
    id: string;
    name: string;
    size: number;
    url: null;
    pending: boolean;
  }> {
    const kept = (editing?.attachments ?? [])
      .filter((f) => !form.removedFiles.includes(f.id))
      .map((f) => ({ ...f, url: null, pending: false }));
    const added = form.newFiles.map((f, i) => ({
      id: `new-${i}`,
      name: f.name,
      size: f.size,
      url: null,
      pending: true,
    }));
    return [...kept, ...added];
  }

  function chooseFile(file: File | undefined) {
    if (!file) return;
    if (file.type !== "application/pdf") {
      toast.error(t("announcements.file_type", "Choose a PDF document."));
      return;
    }
    if (file.size > FILE_MAX_BYTES) {
      toast.error(
        t(
          "announcements.file_too_large",
          "A document must be 5 MB or smaller.",
        ),
      );
      return;
    }
    if (refuseOverServer(file, FILE_MAX_BYTES)) return;
    setForm((p) => ({ ...p, newFiles: [...p.newFiles, file] }));
  }

  function dropFile(file: { id: string; pending: boolean }) {
    setForm((p) =>
      file.pending
        ? {
            ...p,
            newFiles: p.newFiles.filter((_, i) => `new-${i}` !== file.id),
          }
        : { ...p, removedFiles: [...p.removedFiles, file.id] },
    );
  }

  async function chooseCover(chosen: File | undefined) {
    if (!chosen) return;
    if (!COVER_TYPES.includes(chosen.type)) {
      toast.error(
        t("settings.brand_image_type", "Choose a PNG, JPG or WebP image."),
      );
      return;
    }
    // Shrunk in the browser first: a phone photo is several MB, and the
    // server re-encodes to 1920 px anyway.
    const file = await shrinkImage(chosen, {
      maxEdge: 1920,
      type: "image/jpeg",
    });
    if (file.size > COVER_MAX_BYTES) {
      toast.error(
        t(
          "settings.brand_image_too_large",
          "The image must be 5 MB or smaller.",
        ),
      );
      return;
    }
    if (refuseOverServer(file, COVER_MAX_BYTES)) return;
    setForm((p) => ({
      ...p,
      coverFile: file,
      coverPreview: URL.createObjectURL(file),
      removeCover: false,
    }));
  }

  /**
   * Send the cover and document changes once the story exists. A refused
   * photo or document leaves the story saved and the form open on it, with
   * whatever was not sent still chosen, so trying again is one click.
   */
  async function saveExtras(saved: Announcement): Promise<boolean> {
    let latest = saved;
    let coverDone = !form.coverFile && !form.removeCover;
    const removing = [...form.removedFiles];
    const pending = [...form.newFiles];
    try {
      if (form.coverFile) {
        latest = await uploadCover.mutateAsync({
          publicId: saved.public_id,
          file: form.coverFile,
        });
      } else if (form.removeCover && saved.cover) {
        latest = await removeCover.mutateAsync(saved.public_id);
      }
      coverDone = true;
      while (removing.length > 0) {
        latest = await removeFile.mutateAsync({
          publicId: saved.public_id,
          fileId: removing[0],
        });
        removing.shift();
      }
      while (pending.length > 0) {
        latest = await uploadFile.mutateAsync({
          publicId: saved.public_id,
          file: pending[0],
        });
        pending.shift();
      }
      return true;
    } catch (error) {
      setEditing(latest);
      setForm((p) => ({
        ...p,
        ...(coverDone
          ? { coverFile: null, coverPreview: null, removeCover: false }
          : {}),
        removedFiles: removing,
        newFiles: pending,
        publishing: "keep",
      }));
      toast.error(
        apiErrorDetail(error) ??
          t(
            "announcements.extras_failed",
            "The story was saved, but a photo or document could not be",
          ),
      );
      return false;
    }
  }

  function submit() {
    if (form.publishing === "schedule" && !form.publishAt) {
      toast.error(
        t("announcements.schedule_needs_time", "Choose when to publish"),
      );
      return;
    }
    const content = {
      title: form.title,
      body: form.body,
      priority: form.priority,
      category: form.category === "none" ? null : form.category,
      show_on_landing: canBePublic && form.showOnLanding,
      featured: canBePublic && form.showOnLanding && form.featured,
      ...publishedAt(),
    };

    const onError = (error: unknown) =>
      toast.error(
        apiErrorDetail(error) ??
          t("announcements.save_failed", "The announcement could not be saved"),
      );

    if (editing) {
      updateAnnouncement.mutate(
        {
          publicId: editing.public_id,
          payload: content as UpdateAnnouncementPayload,
        },
        {
          onSuccess: async (saved) => {
            if (!(await saveExtras(saved))) return;
            toast.success(t("announcements.updated", "Announcement updated"));
            closeDialog();
          },
          onError,
        },
      );
      return;
    }
    createAnnouncement.mutate(content as CreateAnnouncementPayload, {
      onSuccess: async (saved) => {
        if (!(await saveExtras(saved))) return;
        toast.success(
          form.publishing === "draft"
            ? t("announcements.saved_draft", "Saved as a draft")
            : form.publishing === "schedule"
              ? t("announcements.scheduled", "Scheduled")
              : t("announcements.published", "Announcement published"),
        );
        closeDialog();
      },
      onError,
    });
  }

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
  const saving =
    createAnnouncement.isPending ||
    updateAnnouncement.isPending ||
    uploadCover.isPending ||
    removeCover.isPending ||
    uploadFile.isPending ||
    removeFile.isPending;
  const cover = formCover();
  const files = formFiles();

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
      <PageHeader
        title={t("announcements.title", "Announcements")}
        description={
          manager
            ? t(
                "announcements.description_manage",
                "Write announcements for your people, and publish news on your organisation's public website.",
              )
            : t(
                "announcements.description",
                "Company-wide announcements and updates",
              )
        }
        actions={
          manager && (
            <Button onClick={openNew}>
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
                        <div className="flex flex-wrap items-center gap-2">
                          <h3 className="text-sm font-semibold text-foreground">
                            {a.title}
                          </h3>
                          <Badge
                            variant="outline"
                            className={cn(
                              "border-0 text-[11px]",
                              priorityColors[a.priority] ?? "",
                            )}
                          >
                            {t(`common.${a.priority}`, a.priority)}
                          </Badge>
                          {manager && (
                            <Badge
                              variant="outline"
                              className={cn(
                                "border-0 text-[11px]",
                                statusColors[status],
                              )}
                            >
                              {statusLabel(a)}
                            </Badge>
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
                          {manager && a.featured && (
                            <Badge
                              variant="outline"
                              className="gap-1 border-0 bg-brand-soft text-[11px] text-brand-on-soft"
                            >
                              <Star className="h-3 w-3" aria-hidden="true" />
                              {t("news.featured", "Featured")}
                            </Badge>
                          )}
                          {a.category && (
                            <Badge variant="outline" className="text-[11px]">
                              {t(`news.category_${a.category}`, a.category)}
                            </Badge>
                          )}
                        </div>
                        <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">
                          {a.body}
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
                        <p className="mt-2 text-xs text-muted-foreground">
                          {a.published_at
                            ? formatDate(a.published_at)
                            : t("common.draft", "Draft")}
                        </p>
                      </div>
                      {manager && (
                        <div className="flex shrink-0 flex-wrap gap-1">
                          {(status === "draft" || status === "scheduled") && (
                            <Button
                              variant="outline"
                              size="sm"
                              disabled={updateAnnouncement.isPending}
                              onClick={() =>
                                act(
                                  a,
                                  { published_at: new Date().toISOString() },
                                  t(
                                    "announcements.published",
                                    "Announcement published",
                                  ),
                                )
                              }
                            >
                              <Send
                                className="mr-1.5 h-3.5 w-3.5"
                                aria-hidden="true"
                              />
                              {t("announcements.publish_now", "Publish now")}
                            </Button>
                          )}
                          {(status === "published" ||
                            status === "scheduled") && (
                            <Button
                              variant="ghost"
                              size="sm"
                              disabled={updateAnnouncement.isPending}
                              onClick={() =>
                                act(
                                  a,
                                  { published_at: null },
                                  t(
                                    "announcements.unpublished",
                                    "Moved back to drafts",
                                  ),
                                )
                              }
                            >
                              <Undo2
                                className="mr-1.5 h-3.5 w-3.5"
                                aria-hidden="true"
                              />
                              {t("announcements.unpublish", "Unpublish")}
                            </Button>
                          )}
                          {status === "published" && (
                            <Button
                              variant="ghost"
                              size="sm"
                              disabled={updateAnnouncement.isPending}
                              onClick={() =>
                                act(
                                  a,
                                  { expires_at: new Date().toISOString() },
                                  t("announcements.archived", "Archived"),
                                )
                              }
                            >
                              <Archive
                                className="mr-1.5 h-3.5 w-3.5"
                                aria-hidden="true"
                              />
                              {t("announcements.archive", "Archive")}
                            </Button>
                          )}
                          {status === "archived" && (
                            <Button
                              variant="ghost"
                              size="sm"
                              disabled={updateAnnouncement.isPending}
                              onClick={() =>
                                act(
                                  a,
                                  { expires_at: null },
                                  t("announcements.restored", "Restored"),
                                )
                              }
                            >
                              <ArchiveRestore
                                className="mr-1.5 h-3.5 w-3.5"
                                aria-hidden="true"
                              />
                              {t("announcements.restore", "Restore")}
                            </Button>
                          )}
                          <Button
                            variant="ghost"
                            size="sm"
                            aria-label={`${t("announcements.preview", "Preview")}: ${a.title}`}
                            onClick={() => setPreview(a)}
                          >
                            <Eye
                              className="h-4 w-4 text-muted-foreground"
                              aria-hidden="true"
                            />
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
                            aria-label={`${t("common.edit", "Edit")}: ${a.title}`}
                            onClick={() => openEdit(a)}
                          >
                            <Pencil
                              className="h-4 w-4 text-muted-foreground"
                              aria-hidden="true"
                            />
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
                            aria-label={`${t("common.delete", "Delete")}: ${a.title}`}
                            onClick={() => setPendingDelete(a)}
                          >
                            <Trash2
                              className="h-4 w-4 text-muted-foreground"
                              aria-hidden="true"
                            />
                          </Button>
                        </div>
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

      <Dialog
        open={preview !== null}
        onOpenChange={(open) => !open && setPreview(null)}
      >
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
          {preview && (
            <div className="@container rounded-lg border border-border p-5">
              <ArticleView
                article={
                  "public_id" in preview
                    ? {
                        public_id: preview.public_id,
                        title: preview.title,
                        body: preview.body,
                        category: preview.category ?? null,
                        featured: preview.featured,
                        priority: preview.priority,
                        published_at: preview.published_at ?? null,
                        cover: preview.cover,
                        attachments: (preview.attachments ?? []).map((f) => ({
                          ...f,
                          url: null,
                        })),
                      }
                    : {
                        public_id: "preview",
                        title:
                          preview.title ||
                          t("announcements.untitled", "Untitled"),
                        body: preview.body,
                        category:
                          preview.category === "none" ? null : preview.category,
                        featured: preview.showOnLanding && preview.featured,
                        priority: preview.priority,
                        published_at: new Date().toISOString(),
                        cover,
                        attachments: files,
                      }
                }
              />
            </div>
          )}
        </DialogContent>
      </Dialog>

      <Dialog
        open={dialogOpen}
        onOpenChange={(open) => (open ? setDialogOpen(true) : closeDialog())}
      >
        <DialogContent className="max-w-xl">
          <DialogHeader>
            <DialogTitle>
              {editing
                ? t("announcements.edit", "Edit Announcement")
                : t("announcements.new", "New Announcement")}
            </DialogTitle>
          </DialogHeader>
          <form
            onSubmit={(e) => {
              e.preventDefault();
              submit();
            }}
            className="space-y-4"
          >
            <div>
              <Label htmlFor="announcement-title">
                {t("common.title", "Title")}
              </Label>
              <Input
                id="announcement-title"
                value={form.title}
                maxLength={255}
                onChange={(e) =>
                  setForm((p) => ({ ...p, title: e.target.value }))
                }
                required
                className="mt-1"
              />
            </div>
            <div>
              <Label htmlFor="announcement-body">
                {t("announcements.content", "Content")}
              </Label>
              <Textarea
                id="announcement-body"
                value={form.body}
                onChange={(e) =>
                  setForm((p) => ({ ...p, body: e.target.value }))
                }
                required
                rows={7}
                aria-describedby="announcement-body-hint"
                className="mt-1"
              />
              <p
                id="announcement-body-hint"
                className="mt-1 text-xs text-muted-foreground"
              >
                {t(
                  "announcements.content_hint",
                  "Leave a blank line between paragraphs. Addresses that start with https:// become links.",
                )}
              </p>
            </div>
            <div>
              <p
                id="announcement-cover-label"
                className="text-sm font-medium text-foreground"
              >
                {t("announcements.cover", "Cover photo")}
              </p>
              <div className="mt-1 flex flex-wrap items-center gap-3 rounded-lg border border-dashed border-border p-3">
                <div className="flex aspect-[16/9] w-32 shrink-0 items-center justify-center overflow-hidden rounded-md bg-muted">
                  {cover ? (
                    /* eslint-disable-next-line @next/next/no-img-element */
                    <img
                      src={cover.url}
                      alt={t("announcements.cover", "Cover photo")}
                      className="h-full w-full object-cover"
                    />
                  ) : (
                    <ImageUp
                      className="h-5 w-5 text-muted-foreground"
                      aria-hidden="true"
                    />
                  )}
                </div>
                <p
                  id="announcement-cover-hint"
                  className="min-w-[10rem] flex-1 basis-40 text-xs text-muted-foreground"
                >
                  {t(
                    "announcements.cover_hint",
                    "Shown above the story on your news page. A wide photo, at least 960 px wide and up to 5 MB.",
                  )}
                </p>
                <div className="flex gap-2">
                  <input
                    ref={coverInput}
                    type="file"
                    accept={COVER_TYPES.join(",")}
                    className="sr-only"
                    aria-labelledby="announcement-cover-label"
                    aria-describedby="announcement-cover-hint"
                    onChange={(e) => {
                      void chooseCover(e.target.files?.[0]);
                      e.target.value = "";
                    }}
                  />
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => coverInput.current?.click()}
                  >
                    {cover
                      ? t("settings.logo_replace", "Replace")
                      : t("settings.logo_choose", "Choose image")}
                  </Button>
                  {cover && (
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() =>
                        setForm((p) => ({
                          ...p,
                          coverFile: null,
                          coverPreview: null,
                          removeCover: true,
                        }))
                      }
                    >
                      {t("settings.logo_remove", "Remove")}
                    </Button>
                  )}
                </div>
              </div>
            </div>
            <div>
              <p
                id="announcement-files-label"
                className="text-sm font-medium text-foreground"
              >
                {t("announcements.files", "Documents")}
              </p>
              <p
                id="announcement-files-hint"
                className="text-xs text-muted-foreground"
              >
                {t(
                  "announcements.files_hint",
                  "Up to three PDFs, 5 MB each, such as a circular or a form. Readers download them with the story.",
                )}
              </p>
              {files.length > 0 && (
                <ul className="mt-2 divide-y divide-border rounded-lg border border-border">
                  {files.map((f) => (
                    <li
                      key={f.id}
                      className="flex items-center gap-2 px-3 py-2 text-sm"
                    >
                      <FileText
                        className="h-4 w-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                      />
                      <span className="min-w-0 flex-1 truncate">{f.name}</span>
                      <span className="shrink-0 text-xs text-muted-foreground">
                        {fileSize(f.size)}
                      </span>
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        aria-label={`${t("settings.logo_remove", "Remove")}: ${f.name}`}
                        onClick={() => dropFile(f)}
                      >
                        <X className="h-4 w-4" aria-hidden="true" />
                      </Button>
                    </li>
                  ))}
                </ul>
              )}
              <input
                ref={fileInput}
                type="file"
                accept="application/pdf"
                className="sr-only"
                aria-labelledby="announcement-files-label"
                aria-describedby="announcement-files-hint"
                onChange={(e) => {
                  chooseFile(e.target.files?.[0]);
                  e.target.value = "";
                }}
              />
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="mt-2"
                disabled={files.length >= FILES_MAX}
                onClick={() => fileInput.current?.click()}
              >
                <Plus className="mr-2 h-4 w-4" aria-hidden="true" />
                {t("announcements.add_file", "Add a PDF")}
              </Button>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <Label htmlFor="announcement-category">
                  {t("announcements.category", "Category")}
                </Label>
                <Select
                  value={form.category}
                  onValueChange={(v) =>
                    setForm((p) => ({ ...p, category: v as Form["category"] }))
                  }
                >
                  <SelectTrigger id="announcement-category" className="mt-1">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">
                      {t("announcements.category_none", "No category")}
                    </SelectItem>
                    {NEWS_CATEGORIES.map((c) => (
                      <SelectItem key={c} value={c}>
                        {t(`news.category_${c}`, c)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div>
                <Label htmlFor="announcement-priority">
                  {t("announcements.priority", "Priority")}
                </Label>
                <Select
                  value={form.priority}
                  onValueChange={(v) =>
                    setForm((p) => ({
                      ...p,
                      priority: v as AnnouncementPriority,
                    }))
                  }
                >
                  <SelectTrigger id="announcement-priority" className="mt-1">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="low">
                      {t("common.low", "Low")}
                    </SelectItem>
                    <SelectItem value="normal">
                      {t("common.normal", "Normal")}
                    </SelectItem>
                    <SelectItem value="high">
                      {t("common.high", "High")}
                    </SelectItem>
                    <SelectItem value="urgent">
                      {t("common.urgent", "Urgent")}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>

            <fieldset className="space-y-3 rounded-lg border border-border p-3">
              <legend className="px-1 text-sm font-medium text-foreground">
                {t("announcements.website", "Public website")}
              </legend>
              <div className="flex items-start justify-between gap-4">
                <div>
                  <Label htmlFor="announcement-public">
                    {t(
                      "announcements.show_on_website",
                      "Show on the organisation's public website",
                    )}
                  </Label>
                  <p className="text-xs text-muted-foreground">
                    {canBePublic
                      ? t(
                          "announcements.show_on_website_hint",
                          "Anyone can read it on your news page once it is published.",
                        )
                      : t(
                          "announcements.show_on_website_targeted",
                          "Only announcements for the whole organisation can be public.",
                        )}
                  </p>
                </div>
                <Switch
                  id="announcement-public"
                  checked={canBePublic && form.showOnLanding}
                  disabled={!canBePublic}
                  onCheckedChange={(checked) =>
                    setForm((p) => ({
                      ...p,
                      showOnLanding: checked,
                      featured: checked ? p.featured : false,
                    }))
                  }
                />
              </div>
              <div className="flex items-start justify-between gap-4">
                <div>
                  <Label htmlFor="announcement-featured">
                    {t("announcements.feature", "Feature it")}
                  </Label>
                  <p className="text-xs text-muted-foreground">
                    {t(
                      "announcements.feature_hint",
                      "Featured stories come first on the website.",
                    )}
                  </p>
                </div>
                <Switch
                  id="announcement-featured"
                  checked={form.showOnLanding && form.featured}
                  disabled={!canBePublic || !form.showOnLanding}
                  onCheckedChange={(checked) =>
                    setForm((p) => ({ ...p, featured: checked }))
                  }
                />
              </div>
            </fieldset>

            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <Label htmlFor="announcement-publishing">
                  {t("announcements.publishing", "Publishing")}
                </Label>
                <Select
                  value={form.publishing}
                  onValueChange={(v) =>
                    setForm((p) => ({ ...p, publishing: v as Publishing }))
                  }
                >
                  <SelectTrigger id="announcement-publishing" className="mt-1">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {editing && (
                      <SelectItem value="keep">
                        {t("announcements.publishing_keep", "Keep as it is")}
                      </SelectItem>
                    )}
                    <SelectItem value="now">
                      {t("announcements.publish_now", "Publish now")}
                    </SelectItem>
                    <SelectItem value="schedule">
                      {t("announcements.schedule", "Schedule")}
                    </SelectItem>
                    <SelectItem value="draft">
                      {editing
                        ? t("announcements.move_to_drafts", "Move to drafts")
                        : t("announcements.save_draft", "Save as draft")}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </div>
              {form.publishing === "schedule" && (
                <div>
                  <Label htmlFor="announcement-publish-at">
                    {t("announcements.publish_at", "Publish at")}
                  </Label>
                  <Input
                    id="announcement-publish-at"
                    type="datetime-local"
                    value={form.publishAt}
                    min={toLocalInput(new Date().toISOString())}
                    onChange={(e) =>
                      setForm((p) => ({ ...p, publishAt: e.target.value }))
                    }
                    required
                    className="mt-1"
                  />
                </div>
              )}
            </div>

            <DialogFooter className="gap-2 sm:gap-0">
              <Button
                type="button"
                variant="outline"
                onClick={() => setPreview(form)}
              >
                <Eye className="mr-2 h-4 w-4" aria-hidden="true" />
                {t("announcements.preview", "Preview")}
              </Button>
              <Button type="button" variant="outline" onClick={closeDialog}>
                {t("common.cancel", "Cancel")}
              </Button>
              <Button type="submit" disabled={saving}>
                {saving && (
                  <Loader2
                    className="mr-2 h-4 w-4 animate-spin"
                    aria-hidden="true"
                  />
                )}
                {editing
                  ? t("common.save_changes", "Save Changes")
                  : form.publishing === "draft"
                    ? t("announcements.save_draft", "Save as draft")
                    : form.publishing === "schedule"
                      ? t("announcements.schedule", "Schedule")
                      : t("announcements.publish", "Publish")}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
