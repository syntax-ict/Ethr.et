"use client";

import { useState } from "react";
import { ArrowDown, ArrowUp, Loader2, Plus, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { RoleGate } from "@/components/shared/role-gate";
import {
  useExitClearanceTemplate,
  useSaveExitClearanceTemplate,
  type ExitClearanceOwner,
  type ExitClearanceTemplate,
  type ExitClearanceTemplateLine,
} from "@/features/offboarding/api";
import { useOwnerLabel } from "@/features/offboarding/components/exit-clearance-tab";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";

const OWNERS: ExitClearanceOwner[] = ["hr", "supervisor", "finance"];
const MAX_LINES = 20;

/**
 * The lines every exit clearance starts with. A change applies to
 * clearances started afterwards; one already open keeps its lines.
 */
export default function ExitClearanceSettingsPage() {
  return (
    <RoleGate anyPermission={["manageSettings"]}>
      <TemplateLoader />
    </RoleGate>
  );
}

/** Inside the gate, so a reader who is refused never asks for the template. */
function TemplateLoader() {
  const query = useExitClearanceTemplate();

  return (
    <QueryBoundary query={query}>
      {(template) => (
        // Remounted with what was saved, so the editor starts from the
        // stored lines rather than syncing them in an effect.
        <TemplateEditor
          key={JSON.stringify(template.items)}
          template={template}
        />
      )}
    </QueryBoundary>
  );
}

function TemplateEditor({ template }: { template: ExitClearanceTemplate }) {
  const { t } = useT();
  const ownerLabel = useOwnerLabel();
  const save = useSaveExitClearanceTemplate();
  const [lines, setLines] = useState<ExitClearanceTemplateLine[]>(
    template.items,
  );

  const balanceIndex = lines.findIndex((l) => l.kind === "obligations");
  const invalid = lines.some((l) => l.label.trim().length < 2);

  function update(index: number, patch: Partial<ExitClearanceTemplateLine>) {
    setLines((prev) =>
      prev.map((l, i) => (i === index ? { ...l, ...patch } : l)),
    );
  }

  function move(index: number, by: -1 | 1) {
    setLines((prev) => {
      const next = [...prev];
      const [line] = next.splice(index, 1);
      next.splice(index + by, 0, line);
      return next;
    });
  }

  function submit() {
    save.mutate(
      lines.map((l) => ({ ...l, label: l.label.trim() })),
      {
        onSuccess: () =>
          toast.success(t("exit_clearance.template_saved", "Checklist saved")),
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t(
                "exit_clearance.template_save_failed",
                "The checklist could not be saved.",
              ),
            ),
          ),
      },
    );
  }

  return (
    <div className="space-y-4">
      <p className="text-sm text-muted-foreground">
        {t(
          "exit_clearance.template_intro",
          "When someone resigns, is terminated or retires, their exit clearance starts with these lines. HR can tick any line, finance its own, and the leaver's supervisor theirs. Changes apply to clearances started afterwards.",
        )}
      </p>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">
            {t("exit_clearance.template_lines", "Checklist lines")}
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          {lines.map((line, index) => {
            const n = index + 1;
            return (
              <div key={index} className="space-y-2 rounded-lg border p-3">
                <Input
                  aria-label={t("exit_clearance.line_label", "Line :n", { n })}
                  value={line.label}
                  maxLength={160}
                  onChange={(e) => update(index, { label: e.target.value })}
                />
                <div className="flex flex-wrap items-center gap-2">
                  <Select
                    value={line.owner}
                    onValueChange={(v) =>
                      update(index, { owner: v as ExitClearanceOwner })
                    }
                  >
                    <SelectTrigger
                      className="w-40"
                      aria-label={t(
                        "exit_clearance.line_owner",
                        "Who ticks line :n",
                        {
                          n,
                        },
                      )}
                    >
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {OWNERS.map((o) => (
                        <SelectItem key={o} value={o}>
                          {ownerLabel(o)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <label className="flex items-center gap-2 text-sm">
                    <Checkbox
                      checked={line.kind === "obligations"}
                      disabled={balanceIndex !== -1 && balanceIndex !== index}
                      onCheckedChange={(v) =>
                        update(index, {
                          kind: v === true ? "obligations" : "manual",
                        })
                      }
                    />
                    {t("exit_clearance.shows_balance", "Shows what is owed")}
                  </label>
                  <div className="ml-auto flex gap-1">
                    <Button
                      variant="ghost"
                      size="icon"
                      disabled={index === 0}
                      onClick={() => move(index, -1)}
                      aria-label={t(
                        "exit_clearance.move_up",
                        "Move line :n up",
                        {
                          n,
                        },
                      )}
                    >
                      <ArrowUp className="h-4 w-4" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon"
                      disabled={index === lines.length - 1}
                      onClick={() => move(index, 1)}
                      aria-label={t(
                        "exit_clearance.move_down",
                        "Move line :n down",
                        { n },
                      )}
                    >
                      <ArrowDown className="h-4 w-4" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon"
                      disabled={lines.length === 1}
                      onClick={() =>
                        setLines((prev) => prev.filter((_, i) => i !== index))
                      }
                      aria-label={t("exit_clearance.remove", "Remove line :n", {
                        n,
                      })}
                    >
                      <Trash2 className="h-4 w-4" />
                    </Button>
                  </div>
                </div>
              </div>
            );
          })}

          <div className="flex flex-wrap gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={lines.length >= MAX_LINES}
              onClick={() =>
                setLines((prev) => [
                  ...prev,
                  { key: null, label: "", owner: "hr", kind: "manual" },
                ])
              }
            >
              <Plus className="mr-2 h-4 w-4" />
              {t("exit_clearance.add_line", "Add a line")}
            </Button>
            <Button
              size="sm"
              disabled={invalid || save.isPending}
              onClick={submit}
            >
              {save.isPending && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              {t("exit_clearance.save_template", "Save checklist")}
            </Button>
          </div>
          {invalid && (
            <p className="text-sm text-muted-foreground">
              {t(
                "exit_clearance.label_required",
                "Every line needs at least two characters.",
              )}
            </p>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
