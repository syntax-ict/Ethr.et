"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { ArrowLeft, ShieldCheck, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { OtpCodeInput } from "@/components/shared/otp-code-input";
import { apiClient } from "@/api/client";
import { toast } from "sonner";
import { useT } from "@/lib/i18n/useT";

const CODE_LENGTH = 6;

export function MfaForm() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const { t } = useT();
  const [digits, setDigits] = useState<string[]>(Array(CODE_LENGTH).fill(""));
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  // Off by default: skipping MFA for 30 days is a security decision the user has
  // to make deliberately, not one they opt out of by not noticing a checkbox.
  const [trustDevice, setTrustDevice] = useState(false);
  const [resetKey, setResetKey] = useState(0);

  // Verify we have a temp MFA token; otherwise bounce back to login.
  useEffect(() => {
    if (typeof window === "undefined") return;
    const mfaPending = sessionStorage.getItem("mfa_pending");
    if (!mfaPending) {
      router.replace("/login");
    }
  }, [router]);

  async function verifyCode(code: string) {
    setLoading(true);
    setError("");

    const mfaPending = sessionStorage.getItem("mfa_pending");

    if (!mfaPending) {
      router.replace("/login");
      return;
    }

    try {
      // Through apiClient rather than a bare axios call: this step used to
      // attach `X-Tenant` from localStorage unconditionally, which on a tenant
      // host claimed the client was choosing the tenant when the hostname had
      // already decided it. The shared interceptor sends that header only where
      // the hostname is not authoritative — and adds Accept-Language, which the
      // hand-rolled headers dropped, so a rejected code came back in English.
      await apiClient.post("/auth/mfa/verify", {
        code,
        trust_device: trustDevice,
      });

      sessionStorage.removeItem("mfa_pending");
      toast.success(t("auth.mfa_authenticated", "Authenticated"));
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
          t("auth.mfa_invalid_code", "Invalid code. Try again."),
      );
      setDigits(Array(CODE_LENGTH).fill(""));
      // OtpCodeInput only autoFocuses on mount; remounting it via `key` is how
      // focus returns to the first box after a rejected code.
      setResetKey((k) => k + 1);
    } finally {
      setLoading(false);
    }
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const code = digits.join("");
    if (code.length !== CODE_LENGTH) return;
    verifyCode(code);
  }

  function cancel() {
    sessionStorage.removeItem("mfa_pending");
    router.replace("/login");
  }

  return (
    <div className="w-full max-w-sm">
      <div className="mb-8 text-center">
        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10">
          <ShieldCheck className="h-6 w-6 text-primary" />
        </div>
        <h1 className="mt-4 text-2xl font-bold text-foreground">
          {t("auth.mfa_title", "Two-factor authentication")}
        </h1>
        <p className="mt-1 text-sm text-muted-foreground">
          {t(
            "auth.mfa_subtitle",
            "Enter the 6-digit code from your authenticator app",
          )}
        </p>
      </div>

      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="space-y-2">
          <Label className="sr-only">
            {t("auth.mfa_code_label", "Verification code")}
          </Label>
          <OtpCodeInput
            key={resetKey}
            digits={digits}
            onChange={setDigits}
            // Small delay so the pasted digits are visible for a beat before the
            // loading state replaces them — matches the pre-extraction behavior.
            onPasteComplete={(code) =>
              void setTimeout(() => verifyCode(code), 100)
            }
            disabled={loading}
            autoFocus
          />
          <p className="text-center text-xs text-muted-foreground">
            {t("auth.mfa_paste_hint", "Paste a code or type each digit")}
          </p>
        </div>

        <label className="flex cursor-pointer items-start gap-2.5 rounded-lg border border-border-default p-3">
          <input
            type="checkbox"
            checked={trustDevice}
            onChange={(e) => setTrustDevice(e.target.checked)}
            className="mt-0.5 h-4 w-4 cursor-pointer rounded"
          />
          <span className="text-sm">
            <span className="font-medium text-foreground">
              {t("auth.mfa_trust_device", "Trust this device")}
            </span>
            <span className="block text-xs text-muted-foreground">
              {t(
                "auth.mfa_trust_device_hint",
                "Skip this step on this browser for 30 days. Only use it on a device that is yours.",
              )}
            </span>
          </span>
        </label>

        {error && (
          <div role="alert" className="rounded-lg bg-destructive/10 p-3">
            <p className="text-sm text-destructive">{error}</p>
          </div>
        )}

        <Button
          type="submit"
          className="w-full"
          disabled={loading || digits.join("").length !== CODE_LENGTH}
        >
          {loading ? (
            <>
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              {t("auth.mfa_verifying", "Verifying…")}
            </>
          ) : (
            t("auth.mfa_verify", "Verify")
          )}
        </Button>

        <div className="flex items-center justify-between text-xs">
          <button
            type="button"
            onClick={cancel}
            className="flex items-center gap-1 text-muted-foreground hover:text-foreground"
          >
            <ArrowLeft className="h-3 w-3" />{" "}
            {t("auth.back_to_sign_in", "Back to sign in")}
          </button>
          <Link href="/login/recovery" className="text-primary hover:underline">
            {t("auth.mfa_use_recovery", "Use a recovery code")}
          </Link>
        </div>
      </form>

      <div className="mt-8 rounded-lg border bg-muted/30 p-3 text-xs text-muted-foreground">
        💡{" "}
        <span className="font-medium text-foreground">
          {t("auth.mfa_lost_device", "Lost your device?")}
        </span>{" "}
        {t(
          "auth.mfa_lost_device_hint",
          "Use one of the recovery codes you saved when you enabled MFA, or contact your tenant admin.",
        )}
      </div>
    </div>
  );
}
