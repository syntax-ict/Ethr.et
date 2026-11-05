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
import { RoleGate } from "@/components/shared/role-gate";
import { EmptyState } from "@/components/shared/empty-state";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  useNotificationTemplates,
  useUpdateNotificationTemplate,
  type NotificationTemplate,
} from "@/features/settings/api";
import { toast } from "sonner";
import { fieldErrors, toastError, type FieldErrors } from "@/lib/errors";
import { useT } from "@/lib/i18n/useT";

type TemplateField = "subject_en" | "subject_am" | "body_en" | "body_am";

/**
 * Template type → label. Four reuse the notification-preference labels.
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
        return t("settings.template_type_missing_punch", "Missing punch");
      case "trial_expiring":
        return t("settings.template_type_trial_expiring", "Trial expiring");
      default:
        return type;
    }
  };
}

/** The variables a template may use, as the `{name}` an admin types. */
function VariableList({ variables }: { variables: string[] }) {
  return (
    <div className="flex flex-wrap gap-1">
      {variables.map((v) => (
        <Badge key={v} variant="secondary" className="font-mono text-caption">
          {`{${v}}`}
        </Badge>
      ))}
    </div>
  );
}

const BODY_MAX = 2000;

export default function NotificationTemplatesPage() {
  const { t, locale } = useT();
  const [editing, setEditing] = useState<NotificationTemplate | null>(null);
  const [form, setForm] = useState<Record<TemplateField, string>>({
    subject_en: "",
    subject_am: "",
    body_en: "",
    body_am: "",
  });
  const [errors, setErrors] = useState<FieldErrors>({});

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
    setErrors({});
    setEditing(template);
  }

  function setField(field: TemplateField, value: string) {
    setForm((p) => ({ ...p, [field]: value }));
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
        onError: (err) => {
          // An unknown {placeholder} comes back as a 422 on the field that
          // carries it; show it there rather than in a toast that vanishes.
          setErrors(fieldErrors(err));
          toastError(
            err,
            t(
              "settings.template_update_failed",
              "The template could not be saved.",
            ),
            { skipValidation: true },
          );
        },
      },
    );
  }

  /** Label, input and server error for one of the four fields. */
  function field(name: TemplateField, label: string, multiline: boolean) {
    const id = `template-${name.replace("_", "-")}`;
    const error = errors[name];
    const counterId = multiline ? `${id}-count` : undefined;
    const describedBy =
      [counterId, error ? `${id}-error` : undefined]
        .filter(Boolean)
        .join(" ") || undefined;
    const common = {
      id,
      value: form[name],
      "aria-invalid": error ? true : undefined,
      "aria-describedby": describedBy,
    };

    return (
      <div className="space-y-2">
        <Label htmlFor={id}>{label}</Label>
        {multiline ? (
          <>
            <Textarea
              {...common}
              maxLength={BODY_MAX}
              rows={8}
              onChange={(e) => setField(name, e.target.value)}
            />
            <p
              id={counterId}
              className="text-right text-caption text-muted-foreground"
            >
              {t("settings.template_char_count", ":count of :max characters", {
                count: form[name].length,
                max: BODY_MAX,
              })}
            </p>
          </>
        ) : (
          <Input
            {...common}
            maxLength={200}
            onChange={(e) => setField(name, e.target.value)}
          />
        )}
        {error && (
          <p id={`${id}-error`} className="text-xs text-destructive">
            {error}
          </p>
        )}
      </div>
    );
  }

  return (
    <RoleGate anyPermission={["manageSettings"]}>
      <div className="space-y-6">
        <QueryBoundary
          query={templatesQuery}
          loading={
            <div className="space-y-4">
              {Array.from({ length: 4 }).map((_, i) => (
                <Skeleton key={i} className="h-24" />
              ))}
            </div>
          }
          empty={
            <EmptyState
              icon={Mail}
              title={t("settings.no_templates", "No templates yet")}
            />
          }
        >
          {(templates) => (
            <div className="space-y-3">
              {templates.map((template) => (
                <Card key={template.type}>
                  <CardContent className="flex items-start justify-between gap-4 p-5">
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <Mail
                          className="h-4 w-4 text-muted-foreground"
                          aria-hidden="true"
                        />
                        <p className="font-medium">
                          {typeLabel(template.type)}
                        </p>
                        {template.is_customized && (
                          <Badge variant="success" className="text-caption">
                            <CheckCircle2
                              className="mr-1 h-3 w-3"
                              aria-hidden="true"
                            />
                            {t("settings.template_customized", "Customised")}
                          </Badge>
                        )}
                      </div>
                      {/* The preview follows the reader's language; an
                          Amharic reader saw the English subject even when an
                          Amharic one was set. */}
                      <p className="mt-1 text-sm text-muted-foreground truncate">
                        {locale === "am" && template.subject_am
                          ? template.subject_am
                          : template.subject_en}
                      </p>
                      {template.variables.length > 0 && (
                        <div className="mt-2">
                          <VariableList variables={template.variables} />
                        </div>
                      )}
                    </div>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => openEdit(template)}
                      // Six cards, one "Edit" each: the template's name tells
                      // them apart, as the dialog title does.
                      aria-label={`${t("common.edit")}: ${typeLabel(template.type)}`}
                    >
                      <Edit2 className="mr-1 h-3 w-3" aria-hidden="true" />
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
                {t(
                  "settings.template_variables_help",
                  "Use these variables in the subject or the body. Each is replaced when the email is sent, as plain text.",
                )}
              </DialogDescription>
            </DialogHeader>

            {editing && <VariableList variables={editing.variables} />}

            <div className="space-y-4 py-2">
              <div className="grid gap-4 sm:grid-cols-2">
                {field(
                  "subject_en",
                  t("settings.template_subject_en", "Subject (English)"),
                  false,
                )}
                {field(
                  "subject_am",
                  t("settings.template_subject_am", "Subject (Amharic)"),
                  false,
                )}
              </div>
              {field(
                "body_en",
                t("settings.template_body_en", "Body (English)"),
                true,
              )}
              {field(
                "body_am",
                t("settings.template_body_am", "Body (Amharic)"),
                true,
              )}
              <p className="text-xs text-muted-foreground">
                {t(
                  "settings.template_empty_hint",
                  "Leave a field empty to send the built-in text.",
                )}
              </p>
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
