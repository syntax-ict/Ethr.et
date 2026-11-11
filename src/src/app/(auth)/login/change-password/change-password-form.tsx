"use client";

import { useState } from "react";
import { CheckCircle2, Eye, EyeOff, Loader2, LogOut } from "lucide-react";
import { useQueryClient } from "@tanstack/react-query";
import { z } from "zod";
import { apiClient } from "@/api/client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { FormField } from "@/components/patterns/FormField";
import { FormErrorSummary } from "@/components/patterns/FormErrorSummary";
import { OrgMark } from "@/components/shared/org-mark";
import { PasswordStrengthMeter } from "@/components/shared/password-strength";
import { rules, fieldMessage } from "@/lib/forms/rules";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { useAuthHostContext } from "@/lib/auth/use-auth-host-context";
import { useT } from "@/lib/i18n/useT";

const changeSchema = z
  .object({
    current_password: z.string().min(1, "validation.required"),
    password: rules.password(),
    password_confirmation: z.string().min(1, "validation.required"),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: "auth.passwords_no_match",
    path: ["password_confirmation"],
  })
  .refine((data) => data.password !== data.current_password, {
    message: "auth.change_password_same",
    path: ["password"],
  });
type ChangeValues = z.infer<typeof changeSchema>;

/**
 * Where someone who signed in with the starting password HR gave their batch
 * chooses their own. The API refuses everything else until they do
 * (`RequirePasswordChange`), so this screen stands outside the app shell and
 * calls only the password change and sign-out.
 *
 * A full page load follows the change, not a client navigation: the session is
 * the same one, and whatever the refused requests left in memory goes with the
 * page.
 */
export function ChangePasswordForm() {
  const { t } = useT();
  const queryClient = useQueryClient();
  const [showPassword, setShowPassword] = useState(false);
  const [done, setDone] = useState(false);
  const [leaving, setLeaving] = useState(false);
  const { context, tenantName, tenantBrand } = useAuthHostContext();
  const organisationName = context === "tenant" ? tenantName : null;

  const {
    register,
    submit,
    watch,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<ChangeValues>({
    schema: changeSchema,
    defaultValues: {
      current_password: "",
      password: "",
      password_confirmation: "",
    },
  });

  const password = watch("password");

  async function onSubmit(data: ChangeValues) {
    await apiClient.post("/auth/password/change", data);
    queryClient.clear();
    setDone(true);
    window.location.assign("/dashboard");
  }

  async function signOut() {
    setLeaving(true);
    try {
      await apiClient.post("/auth/logout");
    } catch {
      // Leaving is a decision about this device; the request only asks the
      // server to end the session as well.
    }
    queryClient.clear();
    window.location.assign("/login");
  }

  if (done) {
    return (
      <div className="w-full max-w-sm text-center">
        <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-success-soft">
          <CheckCircle2 className="h-7 w-7 text-success" />
        </div>
        <h1 className="mt-4 text-2xl font-bold text-foreground">
          {t("auth.change_password_done_title", "Your password is set")}
        </h1>
        <p className="mt-2 text-sm text-muted-foreground">
          {t("auth.change_password_done_body", "Taking you in.")}
        </p>
      </div>
    );
  }

  return (
    <div className="w-full max-w-sm">
      <div className="mb-8 text-center">
        {organisationName ? (
          <OrgMark
            name={organisationName}
            logoUrl={tenantBrand?.logo_url}
            className="mx-auto h-12 w-12"
            initialClassName="text-lg"
          />
        ) : (
          <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-primary">
            <span className="text-lg font-bold text-primary-foreground">E</span>
          </div>
        )}
        <h1 className="mt-4 text-2xl font-bold text-foreground">
          {t("auth.change_password_title", "Choose your own password")}
        </h1>
        <p className="mt-1 text-sm text-muted-foreground">
          {t(
            "auth.change_password_intro",
            "You signed in with a starting password that other people know. Choose one only you know before you continue.",
          )}
        </p>
      </div>

      <form
        onSubmit={submit(
          onSubmit,
          t("auth.change_password_failed", "Your password was not changed."),
        )}
        className="space-y-4"
        noValidate
      >
        <FormErrorSummary message={rootError} />

        <FormField
          id="current_password"
          label={t("auth.change_password_current", "Starting password")}
          required
          error={fieldMessage(t, errors.current_password?.message)}
        >
          <Input
            {...register("current_password")}
            type={showPassword ? "text" : "password"}
            autoComplete="current-password"
            autoFocus
          />
        </FormField>

        <FormField
          id="password"
          label={t("auth.reset_new_password", "New password")}
          required
          error={fieldMessage(t, errors.password?.message)}
        >
          {(control) => (
            <div className="relative">
              <Input
                {...register("password")}
                {...control}
                type={showPassword ? "text" : "password"}
                autoComplete="new-password"
                className="pr-10"
              />
              <button
                type="button"
                onClick={() => setShowPassword(!showPassword)}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                tabIndex={-1}
                aria-label={
                  showPassword
                    ? t("auth.hide_password", "Hide password")
                    : t("auth.show_password", "Show password")
                }
              >
                {showPassword ? (
                  <EyeOff className="h-4 w-4" />
                ) : (
                  <Eye className="h-4 w-4" />
                )}
              </button>
            </div>
          )}
        </FormField>

        <div className="space-y-2">
          <PasswordStrengthMeter password={password} />
        </div>

        <FormField
          id="password_confirmation"
          label={t("auth.reset_confirm_password", "Confirm password")}
          required
          error={fieldMessage(t, errors.password_confirmation?.message)}
        >
          <Input
            {...register("password_confirmation")}
            type={showPassword ? "text" : "password"}
            autoComplete="new-password"
          />
        </FormField>

        <Button type="submit" className="w-full" disabled={isSubmitting}>
          {isSubmitting ? (
            <>
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />{" "}
              {t("auth.change_password_saving", "Saving…")}
            </>
          ) : (
            t("auth.change_password_submit", "Save and continue")
          )}
        </Button>

        <button
          type="button"
          onClick={signOut}
          disabled={leaving}
          className="block w-full text-center text-sm text-muted-foreground hover:text-foreground"
        >
          <LogOut className="mr-1 inline h-3 w-3" />{" "}
          {t("auth.sign_out", "Sign Out")}
        </button>
      </form>
    </div>
  );
}
