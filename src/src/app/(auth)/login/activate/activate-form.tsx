"use client";

import { Suspense, useEffect, useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { ArrowLeft, CheckCircle2, Eye, EyeOff, Loader2 } from "lucide-react";
import axios from "axios";
import { z } from "zod";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { FormField } from "@/components/patterns/FormField";
import { FormErrorSummary } from "@/components/patterns/FormErrorSummary";
import { OrgMark } from "@/components/shared/org-mark";
import { PasswordStrengthMeter } from "@/components/shared/password-strength";
import { prepareCsrfCookie } from "@/features/auth/sign-in";
import { rules, fieldMessage } from "@/lib/forms/rules";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { queryOrganisation, signInHref } from "@/lib/auth/entry-organisation";
import { useAuthHostContext } from "@/lib/auth/use-auth-host-context";
import { useT } from "@/lib/i18n/useT";

const activateSchema = z
  .object({
    employee_code: z.string().trim().min(1, "validation.required"),
    code: z.string().trim().min(1, "validation.required"),
    password: rules.password(),
    password_confirmation: z.string().min(1, "validation.required"),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: "auth.passwords_no_match",
    path: ["password_confirmation"],
  });
type ActivateValues = z.infer<typeof activateSchema>;

/**
 * Where an employee with no email and no phone redeems the one-time code HR
 * gave them and chooses their own password.
 *
 * The QR carries the code in the address fragment (`#c=...&e=...`), so it is
 * never sent to a server or a log. It is read once on arrival and removed from
 * the address bar. Typing the code by hand works the same way, and HR never
 * sees the password.
 */
function Activate() {
  const params = useSearchParams();
  const router = useRouter();
  const { t } = useT();

  const tenant = params.get("tenant") ?? "";
  const carryOrganisation = queryOrganisation(params.toString());

  const [showPassword, setShowPassword] = useState(false);
  const [done, setDone] = useState(false);
  const { context, tenantName, tenantBrand } = useAuthHostContext();
  const organisationName = context === "tenant" ? tenantName : null;

  const {
    register,
    setValue,
    submit,
    watch,
    setRootError,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<ActivateValues>({
    schema: activateSchema,
    defaultValues: {
      employee_code: "",
      code: "",
      password: "",
      password_confirmation: "",
    },
  });

  // Read the code from the fragment, then take it out of the address bar.
  useEffect(() => {
    const hash = window.location.hash.replace(/^#/, "");
    if (!hash) return;
    const fragment = new URLSearchParams(hash);
    const code = fragment.get("c");
    const employeeCode = fragment.get("e");
    if (code) setValue("code", code);
    if (employeeCode) setValue("employee_code", employeeCode);
    window.history.replaceState(
      null,
      "",
      window.location.pathname + window.location.search,
    );
  }, [setValue]);

  const password = watch("password");

  if (!tenant) {
    return (
      <div className="w-full max-w-sm text-center">
        <h1 className="text-2xl font-bold text-foreground">
          {t("auth.activate_invalid_link_title", "Invalid activation link")}
        </h1>
        <p className="mt-2 text-sm text-muted-foreground">
          {t(
            "auth.activate_invalid_link_body",
            "This link does not say which organisation it is for. Ask HR to show you the code again.",
          )}
        </p>
        <Button asChild className="mt-6 w-full">
          <Link prefetch={false} href={signInHref(carryOrganisation)}>
            {t("auth.back_to_sign_in", "Back to sign in")}
          </Link>
        </Button>
      </div>
    );
  }

  async function onSubmit(data: ActivateValues) {
    // A signed-out POST needs the XSRF cookie, and this page is usually the
    // first one a phone opens (the 2026-10-09 reset lesson).
    await prepareCsrfCookie();
    try {
      await axios.post(
        "/api/v1/auth/activation/redeem",
        {
          employee_code: data.employee_code,
          code: data.code,
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
    } catch (error) {
      const problem = axios.isAxiosError(error) ? error.response?.data : null;
      if (
        typeof problem?.type === "string" &&
        problem.type.endsWith("invalid-activation-code")
      ) {
        setRootError(
          t(
            "auth.activate_invalid",
            "This code is not valid or has expired. Ask HR to issue a new one.",
          ),
        );
        return;
      }
      throw error;
    }
    setDone(true);
    setTimeout(() => router.push(signInHref(carryOrganisation)), 3000);
  }

  if (done) {
    return (
      <div className="w-full max-w-sm text-center">
        <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-success-soft">
          <CheckCircle2 className="h-7 w-7 text-success" />
        </div>
        <h1 className="mt-4 text-2xl font-bold text-foreground">
          {t("auth.activate_done_title", "Your account is ready")}
        </h1>
        <p className="mt-2 text-sm text-muted-foreground">
          {t(
            "auth.activate_done_body",
            "Sign in with your employee code and the password you just chose.",
          )}
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
          {t("auth.activate_title", "Activate your account")}
        </h1>
        <p className="mt-1 text-sm text-muted-foreground">
          {t(
            "auth.activate_intro",
            "Enter the code HR gave you and choose your own password. Type it yourself: HR must not see it.",
          )}
        </p>
      </div>

      <form
        onSubmit={submit(
          onSubmit,
          t("auth.activate_failed", "Could not activate the account."),
        )}
        className="space-y-4"
        noValidate
      >
        <FormErrorSummary message={rootError} />

        <FormField
          id="employee_code"
          label={t("auth.activate_employee_code", "Employee code")}
          required
          error={fieldMessage(t, errors.employee_code?.message)}
        >
          <Input
            {...register("employee_code")}
            autoComplete="username"
            autoCapitalize="characters"
            spellCheck={false}
          />
        </FormField>

        <FormField
          id="code"
          label={t("auth.activate_code", "Activation code")}
          required
          error={fieldMessage(t, errors.code?.message)}
        >
          <Input
            {...register("code")}
            autoComplete="off"
            autoCapitalize="characters"
            spellCheck={false}
            className="font-mono tracking-widest"
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
              {t("auth.activate_submitting", "Activating…")}
            </>
          ) : (
            t("auth.activate_submit", "Activate account")
          )}
        </Button>

        <Link
          prefetch={false}
          href={signInHref(carryOrganisation)}
          className="block text-center text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="mr-1 inline h-3 w-3" />{" "}
          {t("auth.back_to_sign_in", "Back to sign in")}
        </Link>
      </form>
    </div>
  );
}

/** `useSearchParams` forces a Suspense boundary. */
export function ActivateForm() {
  return (
    <Suspense fallback={<ActivateFallback />}>
      <Activate />
    </Suspense>
  );
}

function ActivateFallback() {
  const { t } = useT();
  return (
    <div className="text-sm text-muted-foreground">
      {t("common.loading", "Loading...")}
    </div>
  );
}
