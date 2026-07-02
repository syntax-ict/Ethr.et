"use client";

import { useState } from "react";
import {
  Save,
  Loader2,
  RotateCcw,
  Mail,
  CheckCircle2,
  Edit2,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  DialogDescription,
} from "@/components/ui/dialog";
import { PageHeader } from "@/components/shared/page-header";
import { RoleGate } from "@/components/shared/role-gate";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { toast } from "sonner";

interface NotificationTemplate {
  type: string;
  subject_en: string;
  subject_am: string;
  body_en: string;
  body_am: string;
  is_customized: boolean;
  variables: string[];
}

const typeLabels: Record<string, string> = {
  leave_requested: "Leave Requested",
  leave_approved: "Leave Approved",
  leave_rejected: "Leave Rejected",
  payslip_available: "Payslip Available",
  missing_punch: "Missing Punch",
  trial_expiring: "Trial Expiring",
};

export default function NotificationTemplatesPage() {
  const queryClient = useQueryClient();
  const [editing, setEditing] = useState<NotificationTemplate | null>(null);
  const [form, setForm] = useState({
    subject_en: "",
    subject_am: "",
    body_en: "",
    body_am: "",
  });

  const { data, isLoading } = useQuery<{ templates: NotificationTemplate[] }>({
    queryKey: ["settings", "notification-templates"],
    queryFn: async () => {
      const { data } = await apiClient.get("/settings/notification-templates");
      return data;
    },
  });

  const update = useMutation({
    mutationFn: async ({
      type,
      payload,
    }: {
      type: string;
      payload: typeof form;
    }) => {
      const { data } = await apiClient.put(
        `/settings/notification-templates/${type}`,
        payload,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ["settings", "notification-templates"],
      });
      setEditing(null);
      toast.success("Template updated");
    },
    onError: () => toast.error("Failed to update template"),
  });

  function openEdit(t: NotificationTemplate) {
    setForm({
      subject_en: t.subject_en,
      subject_am: t.subject_am,
      body_en: t.body_en,
      body_am: t.body_am,
    });
    setEditing(t);
  }

  function handleSave() {
    if (!editing) return;
    update.mutate({ type: editing.type, payload: form });
  }

  return (
    <RoleGate minRole="tenant_admin">
      <div className="space-y-6">
        <PageHeader
          title="Notification Templates"
          description="Customize the content of notification emails sent to employees and managers"
        />

        {isLoading ? (
          <div className="space-y-4">
            {Array.from({ length: 4 }).map((_, i) => (
              <Skeleton key={i} className="h-24" />
            ))}
          </div>
        ) : (
          <div className="space-y-3">
            {data?.templates.map((t) => (
              <Card key={t.type}>
                <CardContent className="flex items-start justify-between gap-4 p-5">
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                      <Mail className="h-4 w-4 text-muted-foreground" />
                      <p className="font-medium">
                        {typeLabels[t.type] ?? t.type}
                      </p>
                      {t.is_customized && (
                        <Badge
                          variant="outline"
                          className="text-[10px] text-green-600"
                        >
                          <CheckCircle2 className="mr-1 h-3 w-3" />
                          Customized
                        </Badge>
                      )}
                    </div>
                    <p className="mt-1 text-sm text-muted-foreground truncate">
                      {t.subject_en}
                    </p>
                    {t.variables.length > 0 && (
                      <div className="mt-2 flex flex-wrap gap-1">
                        {t.variables.map((v) => (
                          <Badge
                            key={v}
                            variant="secondary"
                            className="font-mono text-[10px]"
                          >
                            {`{${v}}`}
                          </Badge>
                        ))}
                      </div>
                    )}
                  </div>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => openEdit(t)}
                  >
                    <Edit2 className="mr-1 h-3 w-3" />
                    Edit
                  </Button>
                </CardContent>
              </Card>
            ))}
          </div>
        )}

        <Dialog open={!!editing} onOpenChange={() => setEditing(null)}>
          <DialogContent className="max-w-2xl">
            <DialogHeader>
              <DialogTitle>
                Edit:{" "}
                {editing ? (typeLabels[editing.type] ?? editing.type) : ""}
              </DialogTitle>
              <DialogDescription>
                Customize the notification content. Use variables like{" "}
                {editing?.variables.map((v) => `{${v}}`).join(", ")} in the
                body.
              </DialogDescription>
            </DialogHeader>

            <div className="space-y-4 py-2">
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <Label>Subject (English)</Label>
                  <Input
                    value={form.subject_en}
                    onChange={(e) =>
                      setForm((p) => ({ ...p, subject_en: e.target.value }))
                    }
                  />
                </div>
                <div className="space-y-2">
                  <Label>Subject (Amharic)</Label>
                  <Input
                    value={form.subject_am}
                    onChange={(e) =>
                      setForm((p) => ({ ...p, subject_am: e.target.value }))
                    }
                  />
                </div>
              </div>

              <div className="space-y-2">
                <Label>Body (English)</Label>
                <Textarea
                  rows={4}
                  value={form.body_en}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, body_en: e.target.value }))
                  }
                />
              </div>

              <div className="space-y-2">
                <Label>Body (Amharic)</Label>
                <Textarea
                  rows={4}
                  value={form.body_am}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, body_am: e.target.value }))
                  }
                />
              </div>
            </div>

            <DialogFooter>
              <Button variant="outline" onClick={() => setEditing(null)}>
                Cancel
              </Button>
              <Button onClick={handleSave} disabled={update.isPending}>
                {update.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <Save className="mr-2 h-4 w-4" />
                )}
                Save Template
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </RoleGate>
  );
}
