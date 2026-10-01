"use client";

import { useState } from "react";
import { Save, Loader2, Mail, CheckCircle2, Edit2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
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
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  useNotificationTemplates,
  useUpdateNotificationTemplate,
  type NotificationTemplate,
} from "@/features/settings/api";
import { toast } from "sonner";
import { useT } from "@/lib/i18n/useT";

/**
 * Template type → label. Four reuse the notification-preference labels; the
 * other two have no translation key yet and stay English.
 */
function useTypeLabel() {
  const { t } = useT();

  return (type: NotificationTemplate["type"]): string => {
    switch (type) {
      case "leave_requested":
        return t("notification_prefs_page.type_leave_requested");
      case "leave_approved":
        return t("notification_prefs_page.type_leave_approved");
      case "leave_rejected":
        return t("notification_prefs_page.type_leave_rejected");
      case "payslip_available":
        return t("notification_prefs_page.type_payslip_available");
      case "missing_punch":
        return "Missing Punch";
      case "trial_expiring":
        return "Trial Expiring";
      default:
        return type;
    }
  };
}

export default function NotificationTemplatesPage() {
  const { t } = useT();
  const [editing, setEditing] = useState<NotificationTemplate | null>(null);
  const [form, setForm] = useState({
    subject_en: "",
    subject_am: "",
    body_en: "",
    body_am: "",
  });

  const templatesQuery = useNotificationTemplates();
  const update = useUpdateNotificationTemplate();
  const typeLabel = useTypeLabel();

  function openEdit(template: NotificationTemplate) {
    setForm({
      subject_en: template.subject_en,
      subject_am: template.subject_am,
      body_en: template.body_en,
      body_am: template.body_am,
    });
    setEditing(template);
  }

  function handleSave() {
    if (!editing) return;
    update.mutate(
      { type: editing.type, payload: form },
      {
        onSuccess: () => {
          setEditing(null);
          toast.success(t("settings.template_updated", "Template updated"));
        },
        onError: () =>
          toast.error(
            t("settings.template_update_failed", "Failed to update template"),
          ),
      },
    );
  }

  return (
    <RoleGate anyPermission={["manageSettings"]}>
      <div className="space-y-6">
        <PageHeader
          title={t("nav.notification_templates")}
          description="Customize the content of notification emails sent to employees and managers"
        />

        <QueryBoundary
          query={templatesQuery}
          loading={
            <div className="space-y-4">
              {Array.from({ length: 4 }).map((_, i) => (
                <Skeleton key={i} className="h-24" />
              ))}
            </div>
          }
        >
          {(templates) => (
            <div className="space-y-3">
              {templates.map((template) => (
                <Card key={template.type}>
                  <CardContent className="flex items-start justify-between gap-4 p-5">
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2">
                        <Mail className="h-4 w-4 text-muted-foreground" />
                        <p className="font-medium">
                          {typeLabel(template.type)}
                        </p>
                        {template.is_customized && (
                          <Badge
                            variant="outline"
                            className="text-[10px] text-status-success"
                          >
                            <CheckCircle2 className="mr-1 h-3 w-3" />
                            Customized
                          </Badge>
                        )}
                      </div>
                      <p className="mt-1 text-sm text-muted-foreground truncate">
                        {template.subject_en}
                      </p>
                      {template.variables.length > 0 && (
                        <div className="mt-2 flex flex-wrap gap-1">
                          {template.variables.map((v) => (
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
                      onClick={() => openEdit(template)}
                    >
                      <Edit2 className="mr-1 h-3 w-3" />
                      {t("common.edit")}
                    </Button>
                  </CardContent>
                </Card>
              ))}
            </div>
          )}
        </QueryBoundary>

        <Dialog open={!!editing} onOpenChange={() => setEditing(null)}>
          <DialogContent className="max-w-2xl">
            <DialogHeader>
              <DialogTitle>
                {t("common.edit")}: {editing ? typeLabel(editing.type) : ""}
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
                  <Label htmlFor="template-subject-en">Subject (English)</Label>
                  <Input
                    id="template-subject-en"
                    maxLength={200}
                    value={form.subject_en}
                    onChange={(e) =>
                      setForm((p) => ({ ...p, subject_en: e.target.value }))
                    }
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="template-subject-am">Subject (Amharic)</Label>
                  <Input
                    id="template-subject-am"
                    maxLength={200}
                    value={form.subject_am}
                    onChange={(e) =>
                      setForm((p) => ({ ...p, subject_am: e.target.value }))
                    }
                  />
                </div>
              </div>

              <div className="space-y-2">
                <Label htmlFor="template-body-en">Body (English)</Label>
                <Textarea
                  id="template-body-en"
                  maxLength={2000}
                  rows={4}
                  value={form.body_en}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, body_en: e.target.value }))
                  }
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="template-body-am">Body (Amharic)</Label>
                <Textarea
                  id="template-body-am"
                  maxLength={2000}
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
                {t("common.cancel")}
              </Button>
              <Button onClick={handleSave} disabled={update.isPending}>
                {update.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <Save className="mr-2 h-4 w-4" />
                )}
                {t("common.save")}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </RoleGate>
  );
}
