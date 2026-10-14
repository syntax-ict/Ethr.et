"use client";

import { signInHref } from "@/lib/auth/entry-organisation";
import { TenantAddressAffix } from "@/components/shared/tenant-address-affix";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { ArrowLeft, Loader2, Mail, MessageSquareText } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { FormField } from "@/components/patterns/FormField";
import { rules } from "@/lib/forms/rules";
import { PhoneInput } from "@/components/shared/phone-input";
import { OtpCodeInput } from "@/components/shared/otp-code-input";
import {
  prepareCsrfCookie,
  requestOtp,
  verifyOtp,
} from "@/features/auth/sign-in";
import { useT } from "@/lib/i18n/useT";
import { useAuthHostContext } from "@/lib/auth/use-auth-host-context";
import { TenantHostIndicator } from "@/components/shared/tenant-host-indicator";
import { TenantLookupErrorState } from "@/components/shared/tenant-lookup-error-state";

const CODE_LENGTH = 6;

type Step = "address" | "code";

/** Where the code goes: email always can; text message once SMS is set up. */
type Channel = "email" | "phone";

export function OtpForm() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const { t } = useT();
  const {
    context: hostAppContext,
    tenantSlug: subdomainFromHost,
    tenantName,
    tenantBrand,
    tenantContextLoading,
    tenantLookupError,
    tenantFieldVisible,
    carryOrganisation,
    tenantSignIn,
  } = useAuthHostContext();

  // What this organisation can deliver. Off an organisation's own page the
  // server decides, and answers 503 for a channel that cannot deliver.
  const known = hostAppContext === "tenant" && tenantSignIn !== null;
  const emailOffered = known ? Boolean(tenantSignIn?.email_code) : true;
  const smsOffered = known ? Boolean(tenantSignIn?.otp) : true;
  const [chosen, setChosen] = useState<Channel>("email");
  const channel: Channel =
    chosen === "email"
      ? emailOffered
        ? "email"
        : "phone"
      : smsOffered
        ? "phone"
        : "email";

  const [step, setStep] = useState<Step>("address");
  const [tenant, setTenant] = useState(() =>
    typeof window !== "undefined" ? (localStorage.getItem("tenant") ?? "") : "",
  );
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  const [digits, setDigits] = useState<string[]>(Array(CODE_LENGTH).fill(""));
  const [resetKey, setResetKey] = useState(0);
  const [requesting, setRequesting] = useState(false);
  const [verifying, setVerifying] = useState(false);
  const [error, setError] = useState("");
  const [info, setInfo] = useState("");
  const [unavailable, setUnavailable] = useState(false);
  // Per-field messages, so a bad subdomain or phone is reported under the
  // control that owns it rather than in the shared banner at the bottom of the
  // form — which on a two-field form is already ambiguous, and scrolls out of
  // view on a phone.
  const [fieldError, setFieldError] = useState<{
    tenant?: string;
    phone?: string;
    email?: string;
  }>({});

  const effectiveTenant = subdomainFromHost ?? tenant.trim().toLowerCase();

  function addressPayload() {
    return channel === "email"
      ? { email: email.trim(), tenant: effectiveTenant }
      : { phone, tenant: effectiveTenant };
  }

  async function requestCode() {
    const next: { tenant?: string; phone?: string; email?: string } = {};

    if (tenantFieldVisible && !effectiveTenant) {
      next.tenant = t(
        "auth.enter_subdomain",
        "Please enter your organization subdomain.",
      );
    }

    // The real subscriber rule, not a length check. `phone.length < 13`
    // accepted `+251012345678` — a leading zero after the country code, which
    // `EthiopianPhone::SUBSCRIBER` rejects — so the form let it through and the
    // server 422'd on a number the user was never told was malformed.
    if (channel === "email") {
      if (!rules.email().safeParse(email.trim()).success) {
        next.email = t("validation.email", "Enter a valid email address");
      }
    } else if (!rules.phone().safeParse(phone).success) {
      next.phone = t(
        "validation.phone",
        "Enter a valid Ethiopian phone number, e.g. 0911223344",
      );
    } else if (!rules.mobilePhone().safeParse(phone).success) {
      next.phone = t(
        "auth.sms_mobile_only",
        "Text-message codes go only to mobile numbers (starting 9 or 7). Use your mobile number, or get the code by email.",
      );
    }

    setFieldError(next);
    if (Object.keys(next).length > 0) return;

    setError("");
    setRequesting(true);

    try {
      // Primes the XSRF-TOKEN cookie axios reads for every stateful POST after
      // this. A user who lands here straight from /login (rather than having
      // just submitted a password) has never made a request that would set it.
      await prepareCsrfCookie();
    } catch {
      setError(
        t(
          "auth.server_error",
          "Unable to connect to the server. Please try again later.",
        ),
      );
      setRequesting(false);
      return;
    }

    try {
      const data = await requestOtp(addressPayload());
      localStorage.setItem("tenant", effectiveTenant);
      setInfo(data.message);
      setStep("code");
      setDigits(Array(CODE_LENGTH).fill(""));
      setResetKey((k) => k + 1);
    } catch (err: unknown) {
      const axiosError = err as { response?: { status?: number } };
      if (axiosError.response?.status === 503) {
        // A system-wide state, not an account signal — safe to say plainly,
        // unlike whether a given phone number has an account.
        setUnavailable(true);
      } else {
        setError(
          t(
            "auth.server_error",
            "Unable to connect to the server. Please try again later.",
          ),
        );
      }
    } finally {
      setRequesting(false);
    }
  }

  async function verifyCode(code: string) {
    setVerifying(true);
    setError("");

    try {
      const data = await verifyOtp({ ...addressPayload(), code });

      if (data.mfa_required) {
        sessionStorage.setItem("mfa_pending", "true");
        router.push("/login/mfa");
        return;
      }

      // One QueryClient serves the whole app (`app/providers.tsx` creates it in
      // `useState` and never replaces it), and reaching this form does not
      // necessarily mean the page was reloaded — `AuthGuard` sends a failed session
      // here with `router.replace`, which keeps the JS context alive. Navigating on
      // to /dashboard with `router.push` would then hand the next person whatever
      // the previous one had cached, including across tenants.
      //
      // Authenticating starts a new session, so it starts a new cache.
      queryClient.clear();
      router.push("/dashboard");
    } catch (err: unknown) {
      const axiosError = err as { response?: { data?: { detail?: string } } };
      setError(
        axiosError.response?.data?.detail ||
          t("auth.otp_invalid_code", "That code is invalid or has expired."),
      );
      setDigits(Array(CODE_LENGTH).fill(""));
      setResetKey((k) => k + 1);
    } finally {
      setVerifying(false);
    }
  }

  function handleRequestSubmit(e: React.FormEvent) {
    e.preventDefault();
    void requestCode();
  }

  function handleVerifySubmit(e: React.FormEvent) {
    e.preventDefault();
    const code = digits.join("");
    if (code.length !== CODE_LENGTH) return;
    void verifyCode(code);
  }

  if (tenantLookupError) {
    return <TenantLookupErrorState kind={tenantLookupError} />;
  }

  if (unavailable) {
    return (
      <div className="w-full max-w-sm mx-auto text-center">
        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-status-warning/10">
          {channel === "email" ? (
            <Mail className="h-6 w-6 text-status-warning" />
          ) : (
            <MessageSquareText className="h-6 w-6 text-status-warning" />
          )}
        </div>
        <h1 className="mt-4 text-2xl font-bold text-foreground">
          {channel === "email"
            ? t(
                "auth.otp_email_unavailable_title",
                "Email sign-in codes aren't available",
              )
            : t("auth.otp_unavailable_title", "SMS sign-in isn't available")}
        </h1>
        <p className="mt-2 text-sm text-muted-foreground">
          {channel === "email"
            ? t(
                "auth.otp_email_unavailable_body",
                "Email delivery isn't set up yet. Sign in with your password instead.",
              )
            : t(
                "auth.otp_unavailable_body",
                "This organization hasn't set up SMS delivery yet. Sign in with your password instead.",
              )}
        </p>
        <Button className="mt-6 w-full" asChild>
          <Link prefetch={false} href={signInHref(carryOrganisation)}>
            {t("auth.otp_back_to_password", "Back to password sign-in")}
          </Link>
        </Button>
      </div>
    );
  }

  return (
    <div className="w-full max-w-sm mx-auto">
      <div className="mb-8">
        <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary shadow-sm">
          {channel === "email" ? (
            <Mail className="h-5 w-5 text-primary-foreground" />
          ) : (
            <MessageSquareText className="h-5 w-5 text-primary-foreground" />
          )}
        </div>
        <h1 className="mt-6 text-2xl font-bold tracking-tight text-foreground">
          {t("auth.otp_title", "Sign in with a code")}
        </h1>
        <p className="mt-2 text-sm text-muted-foreground">
          {step === "code"
            ? t("auth.otp_code_subtitle", "Enter the code we sent you.")
            : channel === "email"
              ? t(
                  "auth.otp_email_subtitle",
                  "We'll email a 6-digit code to you.",
                )
              : t(
                  "auth.otp_phone_subtitle",
                  "We'll text a 6-digit code to your phone.",
                )}
        </p>
      </div>

      {step === "address" ? (
        <form onSubmit={handleRequestSubmit} className="space-y-5" noValidate>
          {emailOffered && smsOffered && (
            <div
              role="radiogroup"
              aria-label={t("auth.otp_channel", "Send the code by")}
              className="grid grid-cols-2 gap-1 rounded-lg bg-muted p-1"
            >
              {(["email", "phone"] as const).map((option) => (
                <button
                  key={option}
                  type="button"
                  role="radio"
                  aria-checked={channel === option}
                  onClick={() => {
                    setChosen(option);
                    setFieldError({});
                  }}
                  className={
                    channel === option
                      ? "rounded-md bg-background px-3 py-1.5 text-sm font-medium text-foreground shadow-sm"
                      : "rounded-md px-3 py-1.5 text-sm text-muted-foreground hover:text-foreground"
                  }
                >
                  {option === "email"
                    ? t("auth.otp_by_email", "Email")
                    : t("auth.otp_by_sms", "Text message")}
                </button>
              ))}
            </div>
          )}

          {hostAppContext === "tenant" && subdomainFromHost && (
            <TenantHostIndicator
              name={tenantName}
              slug={subdomainFromHost}
              loading={tenantContextLoading}
              logoUrl={tenantBrand?.logo_url}
            />
          )}

          {tenantFieldVisible && (
            <FormField
              id="tenant"
              label={t("auth.org_subdomain", "Organization subdomain")}
              required
              error={fieldError.tenant}
            >
              {(control) => (
                <div className="flex items-center rounded-md border border-input focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-1">
                  <TenantAddressAffix side="prefix" bordered />
                  <Input
                    {...control}
                    value={tenant}
                    onChange={(e) => setTenant(e.target.value)}
                    placeholder="acme"
                    autoComplete="organization"
                    className="border-0 focus-visible:ring-0"
                  />
                  <TenantAddressAffix side="suffix" bordered />
                </div>
              )}
            </FormField>
          )}

          {channel === "email" ? (
            <FormField
              id="email"
              label={t("common.email", "Email")}
              required
              error={fieldError.email}
            >
              {(control) => (
                <Input
                  {...control}
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder="name@example.com"
                  autoComplete="email"
                />
              )}
            </FormField>
          ) : (
            <FormField
              id="phone"
              label={t("common.phone", "Phone")}
              required
              error={fieldError.phone}
            >
              {(control) => (
                <PhoneInput {...control} value={phone} onChange={setPhone} />
              )}
            </FormField>
          )}

          {error && (
            <div role="alert" className="rounded-lg bg-destructive/10 p-3">
              <p className="text-sm text-destructive">{error}</p>
            </div>
          )}

          <Button
            type="submit"
            className="h-11 w-full shadow-sm"
            disabled={requesting}
          >
            {requesting ? (
              <>
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                {t("auth.otp_sending", "Sending code...")}
              </>
            ) : (
              t("auth.otp_send_code", "Send code")
            )}
          </Button>
        </form>
      ) : (
        <form onSubmit={handleVerifySubmit} className="space-y-5">
          {info && (
            <div className="rounded-lg bg-status-info/10 p-3">
              <p className="text-sm text-status-info">{info}</p>
            </div>
          )}

          <div className="space-y-2">
            <Label className="sr-only">
              {t("auth.otp_code_label", "Verification code")}
            </Label>
            <OtpCodeInput
              key={resetKey}
              digits={digits}
              onChange={setDigits}
              onPasteComplete={(code) =>
                void setTimeout(() => verifyCode(code), 100)
              }
              disabled={verifying}
              autoFocus
            />
          </div>

          {error && (
            <div role="alert" className="rounded-lg bg-destructive/10 p-3">
              <p className="text-sm text-destructive">{error}</p>
            </div>
          )}

          <Button
            type="submit"
            className="h-11 w-full shadow-sm"
            disabled={verifying || digits.join("").length !== CODE_LENGTH}
          >
            {verifying ? (
              <>
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                {t("auth.otp_verifying", "Verifying...")}
              </>
            ) : (
              t("auth.otp_verify", "Verify and sign in")
            )}
          </Button>

          <div className="flex items-center justify-between text-xs">
            <button
              type="button"
              onClick={() => setStep("address")}
              className="flex items-center gap-1 text-muted-foreground hover:text-foreground"
            >
              <ArrowLeft className="h-3 w-3" />
              {channel === "email"
                ? t("auth.otp_change_email", "Use a different email")
                : t("auth.otp_change_phone", "Use a different number")}
            </button>
            <button
              type="button"
              onClick={() => void requestCode()}
              disabled={requesting}
              className="text-primary hover:underline disabled:opacity-50"
            >
              {t("auth.otp_resend", "Resend code")}
            </button>
          </div>
        </form>
      )}

      <p className="mt-6 text-center text-sm text-muted-foreground">
        <Link
          prefetch={false}
          href={signInHref(carryOrganisation)}
          className="font-medium text-primary hover:underline"
        >
          {t("auth.otp_back_to_password", "Back to password sign-in")}
        </Link>
      </p>
    </div>
  );
}
