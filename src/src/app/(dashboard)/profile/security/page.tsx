"use client";

import { useState, useSyncExternalStore } from "react";
import { QRCodeSVG } from "qrcode.react";
import { useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import {
  ShieldCheck,
  ShieldOff,
  Loader2,
  Eye,
  EyeOff,
  KeyRound,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import {
  useChangePassword,
  useCurrentUser,
  useDisableMfa,
  useEnableMfa,
  useStartMfaSetup,
  type MfaSetup,
} from "@/features/auth/api";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import { PasswordStrengthMeter } from "@/components/shared/password-strength";
import {
  ActiveSessionsCard,
  TrustedDevicesCard,
} from "@/features/auth/components/active-sessions-card";

/** The URL never changes while this page is open, so there is nothing to subscribe to. */
function subscribeToNothing(): () => void {
  return () => {};
}

/**
 * True when the API sent the user here because their organization requires
 * two-factor authentication (`api/client.ts` adds `?mfa=required` on the
 * `mfa-enrolment-required` refusal). Read from `window.location` rather than
 * `useSearchParams`, which a statically exported page cannot render on the
 * server without a Suspense boundary; the server snapshot is simply "no".
 */
function useEnrolmentRequired(): boolean {
  return useSyncExternalStore(
    subscribeToNothing,
    () => new URLSearchParams(window.location.search).get("mfa") === "required",
    () => false,
  );
}

export default function SecurityPage() {
  const { t } = useT();
  const { data: user } = useCurrentUser();
  const enrolmentRequired = useEnrolmentRequired();
  const router = useRouter();
  const queryClient = useQueryClient();

  /**
   * Every query the shell made while enrolment was owed failed with the
   * enrolment refusal; reset them so the next screen fetches afresh rather
   * than rendering those errors.
   */
  function continueAfterEnrolment() {
    void queryClient.resetQueries();
    router.push("/dashboard");
  }
  const [setupOpen, setSetupOpen] = useState(false);
  const [setupData, setSetupData] = useState<MfaSetup | null>(null);
  const [code, setCode] = useState("");
  const [disableOpen, setDisableOpen] = useState(false);
  const [disableCode, setDisableCode] = useState("");
  const [changeOpen, setChangeOpen] = useState(false);
  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [showNew, setShowNew] = useState(false);
  const [changeError, setChangeError] = useState("");

  const startSetup = useStartMfaSetup();
  const enableMfa = useEnableMfa();
  const disableMfa = useDisableMfa();
  const changePassword = useChangePassword();

  function beginSetup() {
    startSetup.mutate(undefined, {
      onSuccess: (data) => {
        setSetupData(data);
        setSetupOpen(true);
      },
      onError: () => toast.error(t("security_page.mfa_setup_failed")),
    });
  }

  /**
   * Sends the secret back with the code. EnableMfaRequest requires both —
   * setup does not store the secret — and this sent only `{ code }`, so every
   * attempt was a 422 shown as "invalid code": two-factor authentication could
   * not be turned on from this page at all.
   */
  function confirmEnable() {
    if (!setupData) return;
    enableMfa.mutate(
      { secret: setupData.secret, code },
      {
        onSuccess: () => {
          toast.success(t("security_page.mfa_enabled"));
          setSetupOpen(false);
          setSetupData(null);
          setCode("");
        },
        onError: () => toast.error(t("security_page.invalid_code")),
      },
    );
  }

  function confirmDisable() {
    disableMfa.mutate(
      { code: disableCode },
      {
        onSuccess: () => {
          toast.success(t("security_page.mfa_disabled"));
          setDisableOpen(false);
          setDisableCode("");
        },
        onError: () => toast.error(t("security_page.invalid_code")),
      },
    );
  }

  function changePasswordNow() {
    changePassword.mutate(
      {
        current_password: currentPassword,
        password: newPassword,
        password_confirmation: confirmPassword,
      },
      {
        onSuccess: (data) => {
          toast.success(data.message ?? t("security_page.password_changed"));
          setChangeOpen(false);
          setCurrentPassword("");
          setNewPassword("");
          setConfirmPassword("");
          setChangeError("");
        },
        onError: (err: unknown) => {
          const e = err as {
            response?: {
              data?: {
                detail?: string;
                message?: string;
                errors?: Record<string, string[]>;
              };
            };
          };
          const errors = e.response?.data?.errors;
          const first = errors ? Object.values(errors)[0]?.[0] : undefined;
          setChangeError(
            first ||
              e.response?.data?.detail ||
              e.response?.data?.message ||
              t("security_page.change_failed"),
          );
        },
      },
    );
  }

  function submitChangePassword(e: React.FormEvent) {
    e.preventDefault();
    setChangeError("");
    if (newPassword !== confirmPassword) {
      setChangeError(t("security_page.passwords_no_match"));
      return;
    }
    if (newPassword.length < 8) {
      setChangeError(t("security_page.password_min_length"));
      return;
    }
    if (newPassword === currentPassword) {
      setChangeError(t("security_page.password_must_differ"));
      return;
    }
    changePasswordNow();
  }

  return (
    <div className="space-y-6">
      {/* The profile layout renders the page title and the section tabs. */}
      {enrolmentRequired && user && !user.mfa_enabled && (
        <Alert variant="warning">
          <AlertTitle>
            {t(
              "security_page.mfa_required_title",
              "Two-factor authentication is required",
            )}
          </AlertTitle>
          <AlertDescription>
            {t(
              "security_page.mfa_required_desc",
              "Your organization requires two-factor authentication. Set it up below to continue using ETHR.",
            )}
          </AlertDescription>
        </Alert>
      )}
      {enrolmentRequired && user?.mfa_enabled && (
        <Alert variant="success">
          <AlertTitle>
            {t(
              "security_page.mfa_required_done",
              "Two-factor authentication is set up",
            )}
          </AlertTitle>
          <AlertDescription>
            <Button className="mt-2" size="sm" onClick={continueAfterEnrolment}>
              {t("security_page.mfa_required_continue", "Continue to ETHR")}
            </Button>
          </AlertDescription>
        </Alert>
      )}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">
            {t("security_page.two_factor_auth")}
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex items-start justify-between gap-4">
            <div className="flex items-start gap-3">
              {user?.mfa_enabled ? (
                <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-status-success/10">
                  <ShieldCheck className="h-5 w-5 text-status-success" />
                </div>
              ) : (
                <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-status-warning/10">
                  <ShieldOff className="h-5 w-5 text-status-warning" />
                </div>
              )}
              <div>
                <div className="flex items-center gap-2">
                  <p className="font-semibold text-foreground">
                    {t("security_page.authenticator_app")}
                  </p>
                  {user?.mfa_enabled && (
                    <Badge
                      variant="outline"
                      className="bg-success-soft text-success-on-soft border-0"
                    >
                      {t("security_page.enabled")}
                    </Badge>
                  )}
                </div>
                <p className="mt-1 text-sm text-muted-foreground">
                  {user?.mfa_enabled
                    ? t("security_page.mfa_protected")
                    : t("security_page.mfa_add_layer")}
                </p>
              </div>
            </div>
            {user?.mfa_enabled ? (
              <Button variant="outline" onClick={() => setDisableOpen(true)}>
                {t("security_page.disable")}
              </Button>
            ) : (
              <Button onClick={beginSetup} disabled={startSetup.isPending}>
                {startSetup.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                {t("security_page.enable_mfa")}
              </Button>
            )}
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">{t("auth.password")}</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex items-center justify-between">
            <div>
              <p className="font-semibold text-foreground">
                {t("security_page.account_password")}
              </p>
              <p className="mt-1 text-sm text-muted-foreground">
                {t("security_page.strong_password_hint")}
              </p>
            </div>
            <Button variant="outline" onClick={() => setChangeOpen(true)}>
              <KeyRound className="mr-2 h-4 w-4" />{" "}
              {t("security_page.change_password")}
            </Button>
          </div>
        </CardContent>
      </Card>

      <ActiveSessionsCard />

      {/* Only meaningful once MFA is on — trusted devices exist to skip a prompt
          that an MFA-less account never sees. */}
      {user?.mfa_enabled && <TrustedDevicesCard />}

      <Dialog open={setupOpen} onOpenChange={setSetupOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("security_page.setup_mfa_title")}</DialogTitle>
          </DialogHeader>

          {setupData && (
            <div className="space-y-4">
              <div>
                <p className="text-sm text-muted-foreground">
                  {t("security_page.scan_qr_hint")}
                </p>
                {/* Encoded here. `qr_code_url` is the otpauth:// URI itself,
                    and it was put in an <img src>, which renders a broken
                    image — there was never a QR code to scan. */}
                {setupData.qr_code_url && (
                  <div className="mt-3 flex justify-center rounded-lg border bg-white p-4">
                    <QRCodeSVG
                      value={setupData.qr_code_url}
                      size={192}
                      role="img"
                      aria-label={t("security_page.mfa_qr_alt")}
                    />
                  </div>
                )}
                <p className="mt-3 text-xs text-muted-foreground">
                  {t("security_page.enter_secret_manually")}
                </p>
                <code className="mt-1 block rounded bg-muted px-3 py-2 text-xs font-mono break-all">
                  {setupData.secret}
                </code>
              </div>

              {/* No recovery-codes panel: it read `recovery_codes`, which
                  MfaSetupController::setup() has never returned. */}
              <div>
                <Label htmlFor="mfa_enable_code">
                  {t("security_page.enter_6_digit_code")}
                </Label>
                <Input
                  id="mfa_enable_code"
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                  placeholder="000000"
                  maxLength={6}
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  className="mt-1 text-center text-2xl font-mono tracking-widest"
                />
              </div>

              <DialogFooter>
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setSetupOpen(false)}
                >
                  {t("common.cancel")}
                </Button>
                <Button
                  onClick={confirmEnable}
                  disabled={enableMfa.isPending || code.length !== 6}
                >
                  {enableMfa.isPending && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  {t("security_page.verify_and_enable")}
                </Button>
              </DialogFooter>
            </div>
          )}
        </DialogContent>
      </Dialog>

      <Dialog
        open={changeOpen}
        onOpenChange={(o) => {
          setChangeOpen(o);
          if (!o) setChangeError("");
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("security_page.change_password")}</DialogTitle>
          </DialogHeader>
          <form onSubmit={submitChangePassword} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="current_password">
                {t("security_page.current_password")}
              </Label>
              <Input
                id="current_password"
                type="password"
                value={currentPassword}
                onChange={(e) => setCurrentPassword(e.target.value)}
                autoComplete="current-password"
                required
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="new_password">
                {t("security_page.new_password")}
              </Label>
              <div className="relative">
                <Input
                  id="new_password"
                  type={showNew ? "text" : "password"}
                  value={newPassword}
                  onChange={(e) => setNewPassword(e.target.value)}
                  autoComplete="new-password"
                  required
                  minLength={8}
                  className="pr-10"
                />
                <button
                  type="button"
                  onClick={() => setShowNew(!showNew)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                  tabIndex={-1}
                  aria-label={
                    showNew
                      ? t("auth.hide_password", "Hide password")
                      : t("auth.show_password", "Show password")
                  }
                  aria-pressed={showNew}
                >
                  {showNew ? (
                    <EyeOff className="h-4 w-4" />
                  ) : (
                    <Eye className="h-4 w-4" />
                  )}
                </button>
              </div>
              {/* S03 requires the strength indicator on password change, not only
                  on the reset page where it already lived. */}
              <PasswordStrengthMeter password={newPassword} />
              <p className="text-xs text-muted-foreground">
                {t("security_page.new_password_hint")}
              </p>
            </div>
            <div className="space-y-2">
              <Label htmlFor="confirm_password">
                {t("security_page.confirm_new_password")}
              </Label>
              <Input
                id="confirm_password"
                type={showNew ? "text" : "password"}
                value={confirmPassword}
                onChange={(e) => setConfirmPassword(e.target.value)}
                autoComplete="new-password"
                required
              />
            </div>
            {changeError && (
              <div className="rounded-lg bg-destructive/10 p-3">
                <p className="text-sm text-destructive">{changeError}</p>
              </div>
            )}
            <p className="text-xs text-muted-foreground">
              {t("security_page.sign_out_sessions_note")}
            </p>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setChangeOpen(false)}
              >
                {t("common.cancel")}
              </Button>
              <Button type="submit" disabled={changePassword.isPending}>
                {changePassword.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                {t("security_page.change_password")}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={disableOpen} onOpenChange={setDisableOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("security_page.disable_mfa_title")}</DialogTitle>
          </DialogHeader>
          <p id="mfa_disable_hint" className="text-sm text-muted-foreground">
            {t("security_page.disable_confirm_hint")}
          </p>
          <Input
            aria-labelledby="mfa_disable_hint"
            inputMode="numeric"
            autoComplete="one-time-code"
            value={disableCode}
            onChange={(e) => setDisableCode(e.target.value)}
            placeholder="000000"
            maxLength={6}
            className="text-center text-2xl font-mono tracking-widest"
          />
          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => setDisableOpen(false)}
            >
              {t("common.cancel")}
            </Button>
            <Button
              variant="destructive"
              onClick={confirmDisable}
              disabled={disableMfa.isPending || disableCode.length !== 6}
            >
              {disableMfa.isPending && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              {t("security_page.disable_mfa")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
