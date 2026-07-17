"use client";

import { useState } from "react";
import { Megaphone, Plus, Loader2, Trash2, Pencil } from "lucide-react";
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
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { toast } from "sonner";

interface Announcement {
  public_id: string;
  title: string;
  body: string;
  priority: string;
  published_at: string | null;
  created_at: string;
}

const priorityColors: Record<string, string> = {
  urgent: "bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300",
  high: "bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300",
  normal: "bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300",
  low: "bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400",
};

export default function AnnouncementsPage() {
  const { t } = useT();
  const queryClient = useQueryClient();
  const { can } = usePermissions();
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [form, setForm] = useState({ title: "", body: "", priority: "normal" });

  function openNew() {
    setEditingId(null);
    setForm({ title: "", body: "", priority: "normal" });
    setDialogOpen(true);
  }

  function openEdit(a: Announcement) {
    setEditingId(a.public_id);
    setForm({ title: a.title, body: a.body, priority: a.priority });
    setDialogOpen(true);
  }

  const { data, isLoading } = useQuery({
    queryKey: ["announcements"],
    queryFn: async () => {
      const { data } = await apiClient.get("/announcements");
      return data;
    },
  });

  const createAnnouncement = useMutation({
    mutationFn: async (payload: typeof form) => {
      const { data } = await apiClient.post("/announcements", {
        ...payload,
        publish_now: true,
      });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["announcements"] });
      toast.success(t("announcements.published", "Announcement published"));
      setDialogOpen(false);
      setForm({ title: "", body: "", priority: "normal" });
    },
    onError: () =>
      toast.error(
        t("announcements.create_failed", "Failed to create announcement"),
      ),
  });

  const updateAnnouncement = useMutation({
    mutationFn: async (payload: typeof form) => {
      const { data } = await apiClient.put(
        `/announcements/${editingId}`,
        payload,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["announcements"] });
      toast.success(t("announcements.updated", "Announcement updated"));
      setDialogOpen(false);
      setEditingId(null);
      setForm({ title: "", body: "", priority: "normal" });
    },
    onError: () =>
      toast.error(
        t("announcements.update_failed", "Failed to update announcement"),
      ),
  });

  const deleteAnnouncement = useMutation({
    mutationFn: async (id: string) => {
      await apiClient.delete(`/announcements/${id}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["announcements"] });
      toast.success(t("announcements.deleted", "Announcement deleted"));
    },
  });

  const announcements: Announcement[] = data?.data ?? [];

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("announcements.title", "Announcements")}
        description={t(
          "announcements.description",
          "Company-wide announcements and updates",
        )}
        actions={
          can.manageEmployees && (
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
                        {a.priority}
                      </Badge>
                    </div>
                    <p className="mt-1 text-sm text-muted-foreground line-clamp-2">
                      {a.body}
                    </p>
                    <p className="mt-2 text-xs text-muted-foreground">
                      {a.published_at
                        ? new Date(a.published_at).toLocaleDateString()
                        : t("common.draft", "Draft")}
                    </p>
                  </div>
                  {can.manageEmployees && (
                    <div className="flex gap-1 shrink-0">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => openEdit(a)}
                      >
                        <Pencil className="h-4 w-4 text-muted-foreground" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => deleteAnnouncement.mutate(a.public_id)}
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
              if (editingId) {
                updateAnnouncement.mutate(form);
              } else {
                createAnnouncement.mutate(form);
              }
            }}
            className="space-y-4"
          >
            <div>
              <Label>{t("common.title", "Title")}</Label>
              <Input
                value={form.title}
                onChange={(e) =>
                  setForm((p) => ({ ...p, title: e.target.value }))
                }
                required
                className="mt-1"
              />
            </div>
            <div>
              <Label>{t("announcements.content", "Content")}</Label>
              <Textarea
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
              <Label>{t("announcements.priority", "Priority")}</Label>
              <Select
                value={form.priority}
                onValueChange={(v) => setForm((p) => ({ ...p, priority: v }))}
              >
                <SelectTrigger className="mt-1">
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
