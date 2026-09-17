"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { ArrowLeft, MessageSquareText, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { FormField } from "@/components/patterns/FormField";
import { rules } from "@/lib/forms/rules";
import { PhoneInput } from "@/components/shared/phone-input";
import { OtpCodeInput } from "@/components/shared/otp-code-input";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { useAuthHostContext } from "@/lib/auth/use-auth-host-context";
import { TenantHostIndicator } from "@/components/shared/tenant-host-indicator";
import { TenantLookupErrorState } from "@/components/shared/tenant-lookup-error-state";

const CODE_LENGTH = 6;

type Step = "phone" | "code";

interface OtpVerifyResult {
  mfa_required: boolean;
}

export function OtpForm() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const { t } = useT();
  const {
    context: hostAppContext,
    tenantSlug: subdomainFromHost,
    tenantName,
    tenantContextLoading,
    tenantLookupError,
    tenantFieldVisible,
  } = useAuthHostContext();

  const [step, setStep] = useState<Step>("phone");
  const [tenant, setTenant] = useState(() =>
    typeof window !== "undefined" ? (localStorage.getItem("tenant") ?? "") : "",
  );
  const [phone, setPhone] = useState("");
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
  }>({});

  const effectiveTenant = subdomainFromHost ?? tenant.trim().toLowerCase();

  async function requestCode() {
    const next: { tenant?: string; phone?: string } = {};

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
    if (!rules.phone().safeParse(phone).success) {
      next.phone = t(
        "validation.phone",
        "Enter a valid Ethiopian phone number, e.g. 0911223344",
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
      await apiClient.get("/sanctum/csrf-cookie", { baseURL: "" });
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
      const { data } = await apiClient.post<{ message: string }>(
        "/auth/otp/request",
        { phone, tenant: effectiveTenant },
      );
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
      const { data } = await apiClient.post<OtpVerifyResult>(
        "/auth/otp/verify",
        {
          phone,
          code,
          tenant: effectiveTenant,
        },
      );

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
          <MessageSquareText className="h-6 w-6 text-status-warning" />
        </div>
        <h1 className="mt-4 text-2xl font-bold text-foreground">
          {t("auth.otp_unavailable_title", "SMS sign-in isn't available")}
        </h1>
        <p className="mt-2 text-sm text-muted-foreground">
          {t(
            "auth.otp_unavailable_body",
            "This organization hasn't set up SMS delivery yet. Sign in with your password instead.",
          )}
        </p>
        <Button className="mt-6 w-full" asChild>
          <Link href="/login">
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
          <MessageSquareText className="h-5 w-5 text-primary-foreground" />
        </div>
        <h1 className="mt-6 text-2xl font-bold tracking-tight text-foreground">
          {t("auth.otp_title", "Sign in with a code")}
        </h1>
        <p className="mt-2 text-sm text-muted-foreground">
          {step === "phone"
            ? t(
                "auth.otp_phone_subtitle",
                "We'll text a 6-digit code to your phone.",
              )
            : t("auth.otp_code_subtitle", "Enter the code we sent you.")}
        </p>
      </div>

      {step === "phone" ? (
        <form onSubmit={handleRequestSubmit} className="space-y-5">
          {hostAppContext === "tenant" && subdomainFromHost && (
            <TenantHostIndicator
              name={tenantName}
              slug={subdomainFromHost}
              loading={tenantContextLoading}
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
                  <Input
                    {...control}
                    value={tenant}
                    onChange={(e) => setTenant(e.target.value)}
                    placeholder="acme"
                    autoComplete="organization"
                    className="border-0 focus-visible:ring-0"
                  />
                  <span className="shrink-0 border-l px-3 text-sm text-muted-foreground">
                    .ethr.et
                  </span>
                </div>
              )}
            </FormField>
          )}

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
              onClick={() => setStep("phone")}
              className="flex items-center gap-1 text-muted-foreground hover:text-foreground"
            >
              <ArrowLeft className="h-3 w-3" />
              {t("auth.otp_change_phone", "Use a different number")}
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
          href="/login"
          className="font-medium text-primary hover:underline"
        >
          {t("auth.otp_back_to_password", "Back to password sign-in")}
        </Link>
      </p>
    </div>
  );
}
