"use client";

import { useEffect, useRef, useState } from "react";
import { FileText, ImageUp, Loader2, Plus, X, Eye } from "lucide-react";
import { toast } from "sonner";
import { useT } from "@/lib/i18n/useT";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
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
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { DateTimeInput } from "@/components/shared/date-time-input";
import {
  useCreateAnnouncement,
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
import { apiErrorDetail } from "@/lib/api/error-message";
import { AnnouncementPreviewDialog } from "./announcement-preview-dialog";

type Publishing = "keep" | "now" | "schedule" | "draft";

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

/** `datetime-local` for a stored time, in the browser's time zone. */
function toLocalInput(iso: string | null | undefined): string {
  if (!iso) return "";
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** The form as it opens: blank for a new story, filled for an existing one. */
function formFor(a: Announcement | null): Form {
  if (!a) return EMPTY_FORM;
  return {
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
  };
}

/**
 * The write form for one announcement, new or existing: text, cover photo,
 * documents, category and priority, the public-website switches and the
 * publishing choice. It is mounted when the editor opens and unmounted when
 * it closes, so every opening starts from `editing`. Every rule is the
 * server's; this form only asks.
 */
export function AnnouncementEditor({
  editing: initial,
  onClose,
}: {
  editing: Announcement | null;
  onClose: () => void;
}) {
  const { t } = useT();
  const [editing, setEditing] = useState<Announcement | null>(initial);
  const [form, setForm] = useState<Form>(() => formFor(initial));
  const [previewing, setPreviewing] = useState(false);
  const createAnnouncement = useCreateAnnouncement();
  const updateAnnouncement = useUpdateAnnouncement();
  const uploadCover = useUploadAnnouncementCover();
  const removeCover = useRemoveAnnouncementCover();
  const uploadFile = useUploadAnnouncementFile();
  const removeFile = useRemoveAnnouncementFile();
  const closeDialog = onClose;
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
    // The native datetime-local input refused a past time through `min`; the
    // separate date and time fields cannot, so the check is made here.
    if (
      form.publishing === "schedule" &&
      new Date(form.publishAt).getTime() <= Date.now()
    ) {
      toast.error(
        t(
          "announcements.schedule_in_past",
          "Choose a time that has not passed yet",
        ),
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

  const saving =
    createAnnouncement.isPending ||
    updateAnnouncement.isPending ||
    uploadCover.isPending ||
    removeCover.isPending ||
    uploadFile.isPending ||
    removeFile.isPending;
  const cover = formCover();
  const files = formFiles();

  return (
    <>
      <Dialog
        open
        onOpenChange={(open) => {
          if (!open) closeDialog();
        }}
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
                  <DateTimeInput
                    id="announcement-publish-at"
                    value={form.publishAt}
                    minDate={toLocalInput(new Date().toISOString()).slice(
                      0,
                      10,
                    )}
                    onChange={(publishAt) =>
                      setForm((p) => ({ ...p, publishAt }))
                    }
                    required
                    timeLabel={t(
                      "announcements.publish_time",
                      "Time to publish",
                    )}
                  />
                </div>
              )}
            </div>

            <DialogFooter className="gap-2 sm:gap-0">
              <Button
                type="button"
                variant="outline"
                onClick={() => setPreviewing(true)}
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
      <AnnouncementPreviewDialog
        article={
          previewing
            ? {
                public_id: "preview",
                title: form.title || t("announcements.untitled", "Untitled"),
                body: form.body,
                category: form.category === "none" ? null : form.category,
                featured: form.showOnLanding && form.featured,
                priority: form.priority,
                published_at: new Date().toISOString(),
                cover,
                attachments: files,
              }
            : null
        }
        onClose={() => setPreviewing(false)}
      />
    </>
  );
}
