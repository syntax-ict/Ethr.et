"use client";

import { useState } from "react";
import { Loader2, LockKeyhole, Save } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { useSaveStatus } from "@/components/shared/save-status";
import { fieldErrors, toastError, type FieldErrors } from "@/lib/errors";
import { useUnsavedChangesWarning } from "@/lib/hooks/useUnsavedChangesWarning";
import { useT } from "@/lib/i18n/useT";
import {
  useUpdateSettings,
  type SettingsUpdate,
  type TenantSettings,
} from "../api";

export type PasswordPolicy = TenantSettings["security"]["password_policy"];

/** The platform's floor and ceiling (`PasswordPolicy::MIN_LENGTH_FLOOR` and `MIN_LENGTH_CEILING`). */
const MIN_LENGTH_FLOOR = 8;
const MIN_LENGTH_CEILING = 128;

type Rule =
  | "require_uppercase"
  | "require_lowercase"
  | "require_number"
  | "require_symbol";

/**
 * Settings > General > Security: the rules a new password must meet.
 *
 * There is no expiry control on purpose. The server can store `expiry_days`,
 * but all it does is set an advisory `password_expired` flag in the sign-in
 * response; no screen or middleware acts on it. A setting that does nothing is
 * not offered, and `PUT /settings` does not accept it.
 */
export function PasswordPolicyCard({ policy }: { policy: PasswordPolicy }) {
  const { t } = useT();
  const update = useUpdateSettings();
  const { announceSaved, announceError } = useSaveStatus();
  const [lengthDraft, setLengthDraft] = useState<string | undefined>();
  const [flags, setFlags] = useState<Partial<Record<Rule, boolean>>>({});
  const [errors, setErrors] = useState<FieldErrors>({});

  const rules: { key: Rule; label: string }[] = [
    {
      key: "require_uppercase",
      label: t(
        "settings.password_require_uppercase",
        "Require an uppercase letter",
      ),
    },
    {
      key: "require_lowercase",
      label: t(
        "settings.password_require_lowercase",
        "Require a lowercase letter",
      ),
    },
    {
      key: "require_number",
      label: t("settings.password_require_number", "Require a number"),
    },
    {
      key: "require_symbol",
      label: t("settings.password_require_symbol", "Require a symbol"),
    },
  ];

  const lengthNumber = Number(lengthDraft);
  const lengthInvalid =
    lengthDraft !== undefined &&
    !(
      lengthDraft.trim() !== "" &&
      Number.isInteger(lengthNumber) &&
      lengthNumber >= MIN_LENGTH_FLOOR &&
      lengthNumber <= MIN_LENGTH_CEILING
    );
  const dirty = lengthDraft !== undefined || Object.keys(flags).length > 0;
  // Differs from what was loaded: a value typed or toggled back is not a change.
  useUnsavedChangesWarning(
    (lengthDraft !== undefined && lengthDraft !== String(policy.min_length)) ||
      (Object.entries(flags) as [Rule, boolean][]).some(
        ([key, value]) => value !== policy[key],
      ),
  );
  const lengthError = errors["settings.password_policy.min_length"];
  const showLengthError = lengthInvalid || !!lengthError;

  function save() {
    if (lengthInvalid) return;
    const next: NonNullable<SettingsUpdate["password_policy"]> = {
      min_length: lengthDraft === undefined ? policy.min_length : lengthNumber,
      require_uppercase: flags.require_uppercase ?? policy.require_uppercase,
      require_lowercase: flags.require_lowercase ?? policy.require_lowercase,
      require_number: flags.require_number ?? policy.require_number,
      require_symbol: flags.require_symbol ?? policy.require_symbol,
    };
    setErrors({});
    update.mutate(
      { password_policy: next },
      {
        onSuccess: () => {
          setLengthDraft(undefined);
          setFlags({});
          toast.success(t("settings.saved", "Settings saved"));
          announceSaved();
        },
        onError: (error) => {
          setErrors(fieldErrors(error));
          announceError(error);
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
          <LockKeyhole className="h-4 w-4 text-muted-foreground" />
          <CardTitle as="h2" className="text-base">
            {t("settings.password_rules_title", "Password rules")}
          </CardTitle>
        </div>
        <p className="text-sm text-muted-foreground">
          {t(
            "settings.password_rules_help",
            "A stricter rule applies to the next password someone sets. Passwords already set are not checked again.",
          )}
        </p>
      </CardHeader>
      <CardContent className="grid gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <Label htmlFor="password-min-length">
            {t("settings.password_min_length", "Minimum length")}
          </Label>
          <Input
            id="password-min-length"
            type="number"
            min={MIN_LENGTH_FLOOR}
            max={MIN_LENGTH_CEILING}
            value={lengthDraft ?? String(policy.min_length)}
            onChange={(e) => setLengthDraft(e.target.value)}
            aria-invalid={showLengthError ? true : undefined}
            aria-describedby={
              showLengthError ? "password-min-length-error" : undefined
            }
            className="mt-1 sm:max-w-40"
          />
          {showLengthError && (
            <p
              id="password-min-length-error"
              className="mt-1 text-xs text-destructive"
              role="alert"
            >
              {lengthInvalid
                ? t(
                    "settings.password_min_length_invalid",
                    "Enter a whole number from 8 to 128.",
                  )
                : lengthError}
            </p>
          )}
          <p className="mt-1 text-xs text-muted-foreground">
            {t(
              "settings.password_min_length_help",
              "From 8 to 128 characters. 8 is the lowest ETHR allows.",
            )}
          </p>
        </div>
        {rules.map(({ key, label }) => (
          <div key={key} className="flex items-center justify-between gap-3">
            <Label htmlFor={`password-${key}`}>{label}</Label>
            <Switch
              id={`password-${key}`}
              checked={flags[key] ?? policy[key]}
              onCheckedChange={(v) => setFlags((p) => ({ ...p, [key]: v }))}
            />
          </div>
        ))}
        <div className="flex justify-end sm:col-span-2">
          <Button
            onClick={save}
            disabled={!dirty || lengthInvalid || update.isPending}
          >
            {update.isPending ? (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            ) : (
              <Save className="mr-2 h-4 w-4" />
            )}
            {t("settings.save_password_rules", "Save password rules")}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
