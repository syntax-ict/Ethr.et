"use client";

import { useState } from "react";
import Link from "next/link";
import { ArrowLeft, MailCheck, Loader2 } from "lucide-react";
import { z } from "zod";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import axios from "axios";
import { FormField } from "@/components/patterns/FormField";
import { FormErrorSummary } from "@/components/patterns/FormErrorSummary";
import { useT } from "@/lib/i18n/useT";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { rules, fieldMessage } from "@/lib/forms/rules";
import { useAuthHostContext } from "@/lib/auth/use-auth-host-context";
import { TenantHostIndicator } from "@/components/shared/tenant-host-indicator";
import { TenantLookupErrorState } from "@/components/shared/tenant-lookup-error-state";

const forgotSchema = z.object({
  // Optional in the schema because the field is not always rendered: on a
  // tenant host the subdomain comes from the hostname and there is nothing to
  // type. The "you must supply one" rule therefore lives in `onSubmit`, where
  // it can know whether the control exists.
  tenant: z.string().optional(),
  email: rules.email(),
});
type ForgotValues = z.infer<typeof forgotSchema>;

export function ForgotForm() {
  const { t } = useT();
  const {
    context: hostAppContext,
    tenantSlug: subdomainFromHost,
    tenantName,
    tenantContextLoading,
    tenantLookupError,
    tenantFieldVisible,
  } = useAuthHostContext();
  const [sent, setSent] = useState(false);
  const [sentTo, setSentTo] = useState({ email: "", tenant: "" });

  const {
    register,
    submit,
    setError,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<ForgotValues>({
    schema: forgotSchema,
    defaultValues: {
      tenant:
        typeof window !== "undefined"
          ? (localStorage.getItem("tenant") ?? "")
          : "",
      email: "",
    },
  });

  async function onSubmit(data: ForgotValues) {
    const effectiveTenant =
      subdomainFromHost ?? (data.tenant ?? "").trim().toLowerCase();

    if (!effectiveTenant) {
      // Reported on the control rather than as a form-level message, so the
      // user is told which box to fill rather than that "something" is missing.
      setError("tenant", {
        type: "manual",
        message: t(
          "auth.enter_subdomain",
          "Please enter your organization subdomain.",
        ),
      });
      return;
    }

    const headers: Record<string, string> = {
      "Content-Type": "application/json",
      Accept: "application/json",
    };
    if (!subdomainFromHost) headers["X-Tenant"] = effectiveTenant;

    await axios.post(
      "/api/v1/auth/password/forgot",
      { email: data.email, tenant: effectiveTenant },
      { headers },
    );

    setSentTo({ email: data.email, tenant: effectiveTenant });
    setSent(true);
  }

  const { email, tenant: effectiveTenant } = sentTo;

  if (tenantLookupError) {
    return <TenantLookupErrorState kind={tenantLookupError} />;
  }

  if (sent) {
    return (
      <div className="w-full max-w-sm text-center">
        <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-success-soft">
          <MailCheck className="h-7 w-7 text-success" />
        </div>
        <h1 className="mt-4 text-2xl font-bold text-foreground">
          {t("auth.forgot_sent_title", "Check your email")}
        </h1>
        <p className="mt-2 text-sm text-muted-foreground">
          {t(
            "auth.forgot_sent_body",
            "If an account with :email exists in :tenant, we've sent a password reset link.",
            { email, tenant: effectiveTenant },
          )}
        </p>
        <p className="mt-1 text-xs text-muted-foreground">
          {t("auth.forgot_sent_expiry", "The link expires in 60 minutes.")}
        </p>
        <div className="mt-6 space-y-2">
          <Button asChild variant="outline" className="w-full">
            <Link href="/login">
              <ArrowLeft className="mr-2 h-4 w-4" />{" "}
              {t("auth.back_to_sign_in", "Back to sign in")}
            </Link>
          </Button>
          <Button
            variant="ghost"
            className="w-full text-xs text-muted-foreground"
            onClick={() => setSent(false)}
          >
            {t("auth.forgot_retry", "Didn't receive an email? Try again")}
          </Button>
        </div>
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
          {t("auth.forgot_title", "Forgot your password?")}
        </h1>
        <p className="mt-1 text-sm text-muted-foreground">
          {t(
            "auth.forgot_subtitle",
            "Enter your email and we'll send you a reset link.",
          )}
        </p>
      </div>

      <form
        onSubmit={submit(
          onSubmit,
          t("auth.forgot_failed", "Something went wrong. Try again."),
        )}
        className="space-y-4"
        noValidate
      >
        {hostAppContext === "tenant" && subdomainFromHost && (
          <TenantHostIndicator
            name={tenantName}
            slug={subdomainFromHost}
            loading={tenantContextLoading}
          />
        )}

        <FormErrorSummary message={rootError} />

        {tenantFieldVisible && (
          <FormField
            id="tenant"
            label={t("auth.org_subdomain", "Organization subdomain")}
            required
            error={fieldMessage(t, errors.tenant?.message)}
          >
            {(control) => (
              <div className="flex items-center rounded-md border border-input focus-within:ring-2 focus-within:ring-ring">
                <Input
                  {...register("tenant")}
                  {...control}
                  placeholder="acme"
                  className="border-0 focus-visible:ring-0"
                />
                <span className="px-3 text-sm text-muted-foreground border-l">
                  .ethr.et
                </span>
              </div>
            )}
          </FormField>
        )}

        <FormField
          id="email"
          label={t("auth.email", "Email")}
          required
          error={fieldMessage(t, errors.email?.message)}
        >
          <Input
            {...register("email")}
            id="email"
            type="email"
            autoComplete="email"
            autoFocus
          />
        </FormField>

        <Button type="submit" className="w-full" disabled={isSubmitting}>
          {isSubmitting ? (
            <>
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />{" "}
              {t("auth.forgot_sending", "Sending…")}
            </>
          ) : (
            t("auth.forgot_send", "Send reset link")
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
