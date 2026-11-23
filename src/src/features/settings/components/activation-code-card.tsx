"use client";

import { useState } from "react";
import { Loader2, QrCode, Save } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { fieldErrors, toastError, type FieldErrors } from "@/lib/errors";
import { useT } from "@/lib/i18n/useT";
import { useUpdateSettings } from "../api";

/** `ActivationCodeService::MAX_TTL_HOURS`: the longest a code issued for one person lives. */
const MAX_HOURS = 72;

/**
 * Settings > General > Security: how long an activation code issued for one
 * person lasts. Printed sheets carry their own lifetime and ignore this.
 */
export function ActivationCodeCard({ hours }: { hours: number }) {
  const { t } = useT();
  const update = useUpdateSettings();
  const [draft, setDraft] = useState<string | undefined>();
  const [errors, setErrors] = useState<FieldErrors>({});

  const number = Number(draft);
  const invalid =
    draft !== undefined &&
    !(
      draft.trim() !== "" &&
      Number.isInteger(number) &&
      number >= 1 &&
      number <= MAX_HOURS
    );
  const serverError = errors["settings.activation_code_hours"];
  const showError = invalid || !!serverError;

  function save() {
    if (draft === undefined || invalid) return;
    setErrors({});
    update.mutate(
      { activation_code_hours: number },
      {
        onSuccess: () => {
          setDraft(undefined);
          toast.success(t("settings.saved", "Settings saved"));
        },
        onError: (error) => {
          setErrors(fieldErrors(error));
          toastError(
            error,
            t("settings.save_failed", "Failed to save settings"),
            { skipValidation: true },
          );
        },
      },
    );
  }

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center gap-2">
          <QrCode className="h-4 w-4 text-muted-foreground" />
          <CardTitle className="text-base">
            {t("settings.activation_code_title", "Activation codes")}
          </CardTitle>
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        <div>
          <Label htmlFor="activation-code-hours">
            {t("settings.activation_code_hours", "Code lifetime (hours)")}
          </Label>
          <Input
            id="activation-code-hours"
            type="number"
            min={1}
            max={MAX_HOURS}
            value={draft ?? String(hours)}
            onChange={(e) => setDraft(e.target.value)}
            aria-invalid={showError ? true : undefined}
            aria-describedby={
              showError ? "activation-code-hours-error" : undefined
            }
            className="mt-1 sm:max-w-40"
          />
          {showError && (
            <p
              id="activation-code-hours-error"
              className="mt-1 text-xs text-destructive"
              role="alert"
            >
              {invalid
                ? t(
                    "settings.activation_code_hours_invalid",
                    "Enter a whole number from 1 to 72.",
                  )
                : serverError}
            </p>
          )}
          <p className="mt-1 text-xs text-muted-foreground">
            {t(
              "settings.activation_code_hours_help",
              "How long a code lasts when you issue it for one person, from 1 to 72 hours. Printed sheets set their own lifetime. A change applies to codes issued from now on.",
            )}
          </p>
        </div>
        <div className="flex justify-end">
          <Button
            onClick={save}
            disabled={draft === undefined || invalid || update.isPending}
          >
            {update.isPending ? (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            ) : (
              <Save className="mr-2 h-4 w-4" />
            )}
            {t("settings.save_activation_code", "Save code lifetime")}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
