"use client";

import { useState } from "react";
import { Hash, Loader2, Save } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { FormField } from "@/components/patterns/FormField";
import {
  useUpdateSettings,
  type TenantSettings,
} from "@/features/settings/api";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

/** The server's rule (EmployeeNumbering::SHAPE): one {SEQ:n}, n from 1 to 8. */
const SHAPE = /^([A-Za-z0-9/._-]{0,20})\{SEQ:([1-8])\}([A-Za-z0-9/._-]{0,10})$/;

/** The format with a sample number in it, to show what codes look like. */
function sample(format: string, n: number): string | null {
  const m = SHAPE.exec(format);
  if (!m) return null;
  return `${m[1]}${String(n).padStart(Number(m[2]), "0")}${m[3]}`;
}

/**
 * The organisation's employee number format (2026-10-15). Templates set one at
 * onboarding and nothing read it; now a new employee or an imported row with
 * no code takes the next number in it.
 */
export function EmployeeNumberCard({
  employees,
}: {
  employees: TenantSettings["employees"];
}) {
  const { t } = useT();
  const save = useUpdateSettings();
  const [draft, setDraft] = useState<string | null>(null);
  const format = draft ?? employees.number_format;
  const valid = SHAPE.test(format);
  const isDirty = draft !== null && draft !== employees.number_format;

  function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!valid) return;
    save.mutate(
      { employee_number_format: format },
      {
        onSuccess: () => {
          setDraft(null);
          toast.success(
            t("settings.employee_number_saved", "Employee number format saved"),
          );
        },
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t(
                "settings.employee_number_save_failed",
                "Couldn't save the employee number format",
              ),
            ),
          ),
      },
    );
  }

  const example = sample(format, 1);

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center gap-2">
          <Hash className="h-4 w-4 text-muted-foreground" />
          <CardTitle className="text-base">
            {t("settings.employee_numbers", "Employee numbers")}
          </CardTitle>
        </div>
        <p className="text-sm text-muted-foreground">
          {t(
            "settings.employee_numbers_desc",
            "A new employee or an imported row with no code gets the next number in this format.",
          )}
        </p>
      </CardHeader>
      <CardContent>
        <form
          onSubmit={submit}
          className="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-start"
        >
          <FormField
            id="employee_number_format"
            label={t("settings.employee_number_format", "Format")}
            hint={
              valid
                ? t(
                    "settings.employee_number_hint",
                    "{SEQ:4} is the number, at least 4 digits. Next code: :next. Codes look like :example.",
                    {
                      next: isDirty
                        ? t(
                            "settings.employee_number_after_save",
                            "after you save",
                          )
                        : employees.next_code,
                      example: example ?? "",
                    },
                  )
                : undefined
            }
            error={
              valid
                ? undefined
                : t(
                    "settings.employee_number_invalid",
                    "Use one {SEQ:n} with n from 1 to 8, and only letters, digits, - _ . or / around it.",
                  )
            }
          >
            <Input
              value={format}
              onChange={(e) => setDraft(e.target.value)}
              maxLength={40}
              className="font-mono"
              autoComplete="off"
            />
          </FormField>
          {isDirty && (
            <Button
              type="submit"
              size="sm"
              className="sm:mt-6"
              disabled={!valid || save.isPending}
            >
              {save.isPending ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : (
                <Save className="mr-2 h-4 w-4" />
              )}
              {t("common.save", "Save")}
            </Button>
          )}
        </form>
      </CardContent>
    </Card>
  );
}
