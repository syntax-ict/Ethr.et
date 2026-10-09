"use client";

import { useState } from "react";
import { Megaphone, Plus, Loader2, Trash2, Pencil } from "lucide-react";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
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
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { usePermissions } from "@/lib/hooks/usePermissions";
import {
  useAllAnnouncements,
  useCreateAnnouncement,
  useDeleteAnnouncement,
  useUpdateAnnouncement,
  type Announcement,
  type AnnouncementPriority,
} from "@/features/announcements/api";
import { toast } from "sonner";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";

const EMPTY_FORM: {
  title: string;
  body: string;
  priority: AnnouncementPriority;
} = { title: "", body: "", priority: "normal" };

const priorityColors: Record<string, string> = {
  urgent: "bg-destructive-soft text-destructive-on-soft",
  high: "bg-warning-soft text-warning-on-soft",
  normal: "bg-info-soft text-info-on-soft",
  low: "bg-muted text-muted-foreground",
};

export default function AnnouncementsPage() {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  const { can } = usePermissions();
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [form, setForm] = useState(EMPTY_FORM);

  function openNew() {
    setEditingId(null);
    setForm(EMPTY_FORM);
    setDialogOpen(true);
  }

  function openEdit(a: Announcement) {
    setEditingId(a.public_id);
    setForm({
      title: a.title,
      body: a.body,
      // The resource types priority as a plain string; the store rule only
      // ever writes one of these four.
      priority: a.priority as AnnouncementPriority,
    });
    setDialogOpen(true);
  }

  const { data, isLoading } = useAllAnnouncements();
  const createAnnouncement = useCreateAnnouncement();
  const updateAnnouncement = useUpdateAnnouncement();
  const deleteAnnouncement = useDeleteAnnouncement();

  function closeDialog() {
    setDialogOpen(false);
    setEditingId(null);
    setForm(EMPTY_FORM);
  }

  function submit() {
    if (editingId) {
      updateAnnouncement.mutate(
        { publicId: editingId, payload: form },
        {
          onSuccess: () => {
            toast.success(t("announcements.updated", "Announcement updated"));
            closeDialog();
          },
          onError: () =>
            toast.error(
              t("announcements.update_failed", "Failed to update announcement"),
            ),
        },
      );
      return;
    }
    createAnnouncement.mutate(
      { ...form, publish_now: true },
      {
        onSuccess: () => {
          toast.success(t("announcements.published", "Announcement published"));
          closeDialog();
        },
        onError: () =>
          toast.error(
            t("announcements.create_failed", "Failed to create announcement"),
          ),
      },
    );
  }

  // Deleting is permanent (announcements are not soft-deleted) and was one
  // click on a bare icon, so it is confirmed first (audit N70).
  const [pendingDelete, setPendingDelete] = useState<Announcement | null>(null);

  function remove(publicId: string) {
    deleteAnnouncement.mutate(publicId, {
      onSuccess: () => {
        setPendingDelete(null);
        toast.success(t("announcements.deleted", "Announcement deleted"));
      },
      onError: () =>
        toast.error(
          t("announcements.delete_failed", "Failed to delete announcement"),
        ),
    });
  }

  const announcements: Announcement[] = data ?? [];

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("announcements.title", "Announcements")}
        description={t(
          "announcements.description",
          "Company-wide announcements and updates",
        )}
        actions={
          can.manageAnnouncements && (
            <Button onClick={openNew}>
              <Plus className="mr-2 h-4 w-4" />{" "}
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
        <div className="space-y-3">
          {announcements.map((a) => (
            <Card key={a.public_id}>
              <CardContent className="p-4">
                <div className="flex items-start justify-between gap-3">
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2">
                      <h3 className="text-sm font-semibold text-foreground">
                        {a.title}
                      </h3>
                      <Badge
                        variant="outline"
                        className={`border-0 text-[10px] ${priorityColors[a.priority] ?? ""}`}
                      >
                        {t(`common.${a.priority}`, a.priority)}
                      </Badge>
                    </div>
                    <p className="mt-1 text-sm text-muted-foreground line-clamp-2">
                      {a.body}
                    </p>
                    <p className="mt-2 text-xs text-muted-foreground">
                      {a.published_at
                        ? formatDate(a.published_at)
                        : t("common.draft", "Draft")}
                    </p>
                  </div>
                  {can.manageAnnouncements && (
                    <div className="flex gap-1 shrink-0">
                      <Button
                        variant="ghost"
                        size="sm"
                        aria-label={t("common.edit", "Edit")}
                        onClick={() => openEdit(a)}
                      >
                        <Pencil className="h-4 w-4 text-muted-foreground" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        aria-label={t("common.delete", "Delete")}
                        onClick={() => setPendingDelete(a)}
                      >
                        <Trash2 className="h-4 w-4 text-muted-foreground" />
                      </Button>
                    </div>
                  )}
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
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

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {editingId
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
                rows={4}
                className="mt-1"
              />
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
                  <SelectItem value="low">{t("common.low", "Low")}</SelectItem>
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
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setDialogOpen(false)}
              >
                {t("common.cancel", "Cancel")}
              </Button>
              <Button
                type="submit"
                disabled={
                  createAnnouncement.isPending || updateAnnouncement.isPending
                }
              >
                {(createAnnouncement.isPending ||
                  updateAnnouncement.isPending) && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                {editingId
                  ? t("common.save_changes", "Save Changes")
                  : t("announcements.publish", "Publish")}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
