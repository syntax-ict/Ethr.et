"use client";

import { useState, Suspense } from "react";
import Link from "next/link";
import { useSearchParams, useRouter } from "next/navigation";
import { ArrowLeft, CheckCircle2, Loader2, Eye, EyeOff } from "lucide-react";
import { z } from "zod";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import axios from "axios";
import { FormField } from "@/components/patterns/FormField";
import { FormErrorSummary } from "@/components/patterns/FormErrorSummary";
import { useT } from "@/lib/i18n/useT";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { rules, fieldMessage } from "@/lib/forms/rules";

const resetSchema = z
  .object({
    password: rules.password(),
    password_confirmation: z.string().min(1, "validation.required"),
  })
  // Reported on the confirmation field, not the password field: the password is
  // the one the user meant, so the mismatch is a defect of the copy they typed
  // second, and that is the box they need to return to.
  .refine((data) => data.password === data.password_confirmation, {
    message: "auth.passwords_no_match",
    path: ["password_confirmation"],
  });
type ResetValues = z.infer<typeof resetSchema>;

function ResetForm() {
  const params = useSearchParams();
  const router = useRouter();
  const { t } = useT();

  const token = params.get("token") ?? "";
  const email = params.get("email") ?? "";
  const tenant = params.get("tenant") ?? "";

  const [showPassword, setShowPassword] = useState(false);
  const [done, setDone] = useState(false);

  const {
    register,
    submit,
    watch,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<ResetValues>({
    schema: resetSchema,
    defaultValues: { password: "", password_confirmation: "" },
  });

  const password = watch("password");
  const strength = passwordStrength(password, t);

  if (!token || !email || !tenant) {
    return (
      <div className="w-full max-w-sm text-center">
        <h1 className="text-2xl font-bold text-foreground">
          {t("auth.reset_invalid_link_title", "Invalid reset link")}
        </h1>
        <p className="mt-2 text-sm text-muted-foreground">
          {t(
            "auth.reset_invalid_link_body",
            "This link is missing required information. Please request a new reset link.",
          )}
        </p>
        <Button asChild className="mt-6 w-full">
          <Link href="/login/forgot">
            {t("auth.reset_request_new_link", "Request new link")}
          </Link>
        </Button>
      </div>
    );
  }

  async function onSubmit(data: ResetValues) {
    await axios.post(
      "/api/v1/auth/password/reset",
      {
        token,
        email,
        password: data.password,
        password_confirmation: data.password_confirmation,
        tenant,
      },
      {
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "X-Tenant": tenant,
        },
      },
    );
    setDone(true);
    setTimeout(() => router.push("/login"), 2500);
  }

  if (done) {
    return (
      <div className="w-full max-w-sm text-center">
        <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-success-soft">
          <CheckCircle2 className="h-7 w-7 text-success" />
        </div>
        <h1 className="mt-4 text-2xl font-bold text-foreground">
          {t("auth.reset_done_title", "Password reset")}
        </h1>
        <p className="mt-2 text-sm text-muted-foreground">
          {t("auth.reset_done_body", "Redirecting you to sign in…")}
        </p>
      </div>
    );
  }

  return (
    <div className="w-full max-w-sm">
      <div className="mb-8 text-center">
        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-primary">
          <span className="text-lg font-bold text-primary-foreground">E</span>
        </div>
        <h1 className="mt-4 text-2xl font-bold text-foreground">
          {t("auth.reset_title", "Set a new password")}
        </h1>
        <p className="mt-1 text-sm text-muted-foreground">
          {t("auth.reset_for_email", "For :email", { email })}
        </p>
      </div>

      <form
        onSubmit={submit(onSubmit, t("auth.reset_failed", "Reset failed."))}
        className="space-y-4"
        noValidate
      >
        <FormErrorSummary message={rootError} />

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
                autoFocus
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
          {password.length > 0 && (
            <div className="space-y-1">
              <div className="flex gap-1 h-1">
                {[0, 1, 2, 3].map((i) => (
                  <div
                    key={i}
                    className={`flex-1 rounded-full ${
                      i < strength.score
                        ? strength.score < 2
                          ? "bg-destructive"
                          : strength.score < 3
                            ? "bg-warning"
                            : "bg-success"
                        : "bg-muted"
                    }`}
                  />
                ))}
              </div>
              <p className="text-xs text-muted-foreground">{strength.label}</p>
            </div>
          )}
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
              {t("auth.reset_resetting", "Resetting…")}
            </>
          ) : (
            t("auth.reset_submit", "Reset password")
          )}
        </Button>

        <Link
          href="/login"
          className="block text-center text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="inline h-3 w-3 mr-1" />{" "}
          {t("auth.back_to_sign_in", "Back to sign in")}
        </Link>
      </form>
    </div>
  );
}

function passwordStrength(
  pw: string,
  t: (key: string, fallback?: string) => string,
): { score: number; label: string } {
  if (pw.length === 0) return { score: 0, label: "" };
  let score = 0;
  if (pw.length >= 8) score++;
  if (pw.length >= 12) score++;
  if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
  if (/\d/.test(pw) && /[^A-Za-z0-9]/.test(pw)) score++;
  const labels = [
    t("auth.password_too_weak", "Too weak"),
    t("auth.password_weak", "Weak"),
    t("auth.password_fair", "Fair"),
    t("auth.password_good", "Good"),
    t("auth.password_strong", "Strong"),
  ];
  return { score, label: labels[score] };
}

/**
 * `useSearchParams` forces a Suspense boundary, so the inner `ResetForm` holds
 * the real form and this wrapper supplies the fallback.
 */
export function ResetPasswordForm() {
  return (
    <Suspense fallback={<ResetFormFallback />}>
      <ResetForm />
    </Suspense>
  );
}

function ResetFormFallback() {
  const { t } = useT();
  return (
    <div className="text-sm text-muted-foreground">
      {t("common.loading", "Loading...")}
    </div>
  );
}
