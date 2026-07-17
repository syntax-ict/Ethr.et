"use client";

import { useState } from "react";
import {
  ShieldCheck,
  ShieldOff,
  Loader2,
  Copy,
  AlertCircle,
  Eye,
  EyeOff,
  KeyRound,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import { PageHeader } from "@/components/shared/page-header";
import { useCurrentUser } from "@/features/auth/api";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

export default function SecurityPage() {
  const { t } = useT();
  const { data: user } = useCurrentUser();
  const queryClient = useQueryClient();
  const [setupOpen, setSetupOpen] = useState(false);
  const [setupData, setSetupData] = useState<{
    secret: string;
    qr_code_url: string;
    recovery_codes: string[];
  } | null>(null);
  const [code, setCode] = useState("");
  const [disableOpen, setDisableOpen] = useState(false);
  const [disableCode, setDisableCode] = useState("");
  const [changeOpen, setChangeOpen] = useState(false);
  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [showNew, setShowNew] = useState(false);
  const [changeError, setChangeError] = useState("");

  const startSetup = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post("/auth/mfa/setup");
      return data;
    },
    onSuccess: (data) => {
      setSetupData(data);
      setSetupOpen(true);
    },
    onError: () => toast.error(t("security_page.mfa_setup_failed")),
  });

  const enableMfa = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post("/auth/mfa/enable", { code });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["auth", "me"] });
      toast.success(t("security_page.mfa_enabled"));
      setSetupOpen(false);
      setSetupData(null);
      setCode("");
    },
    onError: () => toast.error(t("security_page.invalid_code")),
  });

  const disableMfa = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post("/auth/mfa/disable", {
        code: disableCode,
      });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["auth", "me"] });
      toast.success(t("security_page.mfa_disabled"));
      setDisableOpen(false);
      setDisableCode("");
    },
    onError: () => toast.error(t("security_page.invalid_code")),
  });

  const changePassword = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post("/auth/password/change", {
        current_password: currentPassword,
        password: newPassword,
        password_confirmation: confirmPassword,
      });
      return data;
    },
    onSuccess: (data: { message: string }) => {
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
  });

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
    changePassword.mutate();
  }

  function copyCodes() {
    if (setupData?.recovery_codes) {
      navigator.clipboard.writeText(setupData.recovery_codes.join("\n"));
      toast.success(t("security_page.recovery_codes_copied"));
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("security_page.title")}
        description={t("security_page.description")}
      />

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
                <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-green-100 dark:bg-green-950">
                  <ShieldCheck className="h-5 w-5 text-green-600 dark:text-green-400" />
                </div>
              ) : (
                <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-100 dark:bg-amber-950">
                  <ShieldOff className="h-5 w-5 text-amber-600 dark:text-amber-400" />
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
                      className="bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300 border-0"
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
              <Button
                onClick={() => startSetup.mutate()}
                disabled={startSetup.isPending}
              >
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
                {setupData.qr_code_url && (
                  <div className="mt-3 flex justify-center rounded-lg border bg-white p-4">
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img
                      src={setupData.qr_code_url}
                      alt="MFA QR Code"
                      className="h-48 w-48"
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

              {setupData.recovery_codes &&
                setupData.recovery_codes.length > 0 && (
                  <div className="rounded-lg border-2 border-amber-300 bg-amber-50 p-3 dark:bg-amber-950/30">
                    <div className="flex items-start gap-2">
                      <AlertCircle className="mt-0.5 h-4 w-4 text-amber-600" />
                      <div className="flex-1">
                        <p className="text-sm font-semibold text-amber-900 dark:text-amber-300">
                          {t("security_page.recovery_codes")}
                        </p>
                        <p className="mt-1 text-xs text-amber-900 dark:text-amber-300/80">
                          {t("security_page.recovery_codes_hint")}
                        </p>
                        <div className="mt-2 grid grid-cols-2 gap-1 font-mono text-xs">
                          {setupData.recovery_codes.map((c) => (
                            <code
                              key={c}
                              className="rounded bg-background px-2 py-1"
                            >
                              {c}
                            </code>
                          ))}
                        </div>
                        <Button
                          size="sm"
                          variant="outline"
                          className="mt-2"
                          onClick={copyCodes}
                        >
                          <Copy className="mr-2 h-3 w-3" />{" "}
                          {t("security_page.copy_all")}
                        </Button>
                      </div>
                    </div>
                  </div>
                )}

              <div>
                <Label>{t("security_page.enter_6_digit_code")}</Label>
                <Input
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                  placeholder="000000"
                  maxLength={6}
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
                  onClick={() => enableMfa.mutate()}
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
                >
                  {showNew ? (
                    <EyeOff className="h-4 w-4" />
                  ) : (
                    <Eye className="h-4 w-4" />
                  )}
                </button>
              </div>
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
          <p className="text-sm text-muted-foreground">
            {t("security_page.disable_confirm_hint")}
          </p>
          <Input
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
              onClick={() => disableMfa.mutate()}
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
