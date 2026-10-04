"use client";

import { useState } from "react";
import Link from "next/link";
import { ArrowLeft, Loader2, MailCheck } from "lucide-react";
import { z } from "zod";
import axios, { isAxiosError } from "axios";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { FormField } from "@/components/patterns/FormField";
import { FormErrorSummary } from "@/components/patterns/FormErrorSummary";
import { useT } from "@/lib/i18n/useT";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { rules, fieldMessage } from "@/lib/forms/rules";

const findSchema = z.object({ email: rules.email() });
type FindValues = z.infer<typeof findSchema>;

/**
 * "Find my organisation", for someone on the apex login who does not know
 * their organisation's subdomain.
 *
 * The page says one sentence whatever the address — it does not know, and
 * must not show, which organisations the address belongs to. The API emails
 * each organisation's sign-in link to the address itself. The confirmation is
 * this page's own text, never the response's, so nothing the API returns could
 * ever reach the screen.
 */
export function FindOrganisationForm() {
  const { t } = useT();
  const [sent, setSent] = useState(false);

  const {
    register,
    submit,
    setRootError,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<FindValues>({
    schema: findSchema,
    defaultValues: { email: "" },
  });

  async function onSubmit(data: FindValues) {
    try {
      await axios.post(
        "/api/v1/auth/find-organisation",
        { email: data.email },
        { headers: { Accept: "application/json" } },
      );
    } catch (error) {
      if (isAxiosError(error) && error.response?.status === 429) {
        setRootError(
          t(
            "auth.find_org_too_many",
            "Too many requests for this address. Try again in a few minutes.",
          ),
        );
        return;
      }
      throw error;
    }
    setSent(true);
  }

  if (sent) {
    return (
      <div className="w-full max-w-sm text-center">
        <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-success-soft">
          <MailCheck className="h-7 w-7 text-success" />
        </div>
        <h1 className="mt-4 text-2xl font-bold text-foreground">
          {t("auth.find_org_sent_title", "Check your email")}
        </h1>
        <p className="mt-2 text-sm text-muted-foreground">
          {t(
            "auth.find_org_sent_body",
            "If that address belongs to an organisation, we've emailed you its sign-in link.",
          )}
        </p>
        <div className="mt-6">
          <Button asChild variant="outline" className="w-full">
            <Link href="/login">
              <ArrowLeft className="mr-2 h-4 w-4" />{" "}
              {t("auth.back_to_sign_in", "Back to sign in")}
            </Link>
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
          {t("auth.find_org_title", "Find your organisation")}
        </h1>
        <p className="mt-1 text-sm text-muted-foreground">
          {t(
            "auth.find_org_subtitle",
            "Enter the email address you sign in with. We'll email you the sign-in link for each organisation it belongs to.",
          )}
        </p>
      </div>

      <form
        onSubmit={submit(
          onSubmit,
          t("auth.find_org_failed", "Something went wrong. Try again."),
        )}
        className="space-y-4"
        noValidate
      >
        <FormErrorSummary message={rootError} />

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
              {t("auth.find_org_sending", "Sending…")}
            </>
          ) : (
            t("auth.find_org_send", "Email me the link")
          )}
        </Button>

        <Link
          href="/login"
          className="block text-center text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="mr-1 inline h-3 w-3" />{" "}
          {t("auth.back_to_sign_in", "Back to sign in")}
        </Link>
      </form>
    </div>
  );
}
