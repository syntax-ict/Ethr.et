"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import {
  LogIn,
  LogOut,
  CheckCircle2,
  XCircle,
  Clock,
  KeyRound,
  Maximize,
  Lock,
  Loader2,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { DEFAULT_TIMEZONE } from "@/lib/utils/date";
import { authenticateKiosk, kioskPunch } from "@/features/kiosk/api";
import { cn } from "@/lib/utils";
import { useT } from "@/lib/i18n/useT";

type Screen = "setup" | "kiosk" | "lock";
type CheckMode = "idle" | "checking" | "success" | "error";

interface KioskConfig {
  token: string;
  tenantName: string;
  subdomain: string;
  logoPath: string | null;
  /** Null when the session has no branch; the fallback is translated at render. */
  branchName: string | null;
  pinRequired: boolean;
  autoResetSeconds: number;
}

export default function KioskPage() {
  const { t } = useT();
  const [screen, setScreen] = useState<Screen>("setup");
  const [config, setConfig] = useState<KioskConfig | null>(null);

  // Setup form
  const [setupToken, setSetupToken] = useState("");
  const [setupLoading, setSetupLoading] = useState(false);
  // A translation key, not text, so the message follows a language change.
  const [setupError, setSetupError] = useState("");

  // Kiosk state
  const [code, setCode] = useState("");
  const [pin, setPin] = useState("");
  const [showPin, setShowPin] = useState(false);
  const [type, setType] = useState<"check_in" | "check_out">("check_in");
  const [mode, setMode] = useState<CheckMode>("idle");
  const [message, setMessage] = useState("");
  const [now, setNow] = useState(new Date());

  // Lock screen
  const [adminPin, setAdminPin] = useState("");
  const [lockError, setLockError] = useState("");

  const resetTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Auto-load token from localStorage
  useEffect(() => {
    const saved = localStorage.getItem("kiosk_token");
    if (saved) {
      authenticateToken(saved);
    }
  }, []);

  // Live clock
  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 1000);
    return () => clearInterval(id);
  }, []);

  // Auto-reset after result
  useEffect(() => {
    if (mode === "success" || mode === "error") {
      resetTimerRef.current = setTimeout(
        () => {
          setMode("idle");
          setCode("");
          setPin("");
          setShowPin(false);
          setMessage("");
        },
        (config?.autoResetSeconds ?? 4) * 1000,
      );
      return () => {
        if (resetTimerRef.current) clearTimeout(resetTimerRef.current);
      };
    }
  }, [mode, config?.autoResetSeconds]);

  async function authenticateToken(token: string) {
    setSetupLoading(true);
    setSetupError("");
    try {
      const data = await authenticateKiosk(token);
      const cfg: KioskConfig = {
        token,
        tenantName: data.tenant.name,
        subdomain: data.tenant.subdomain,
        logoPath: data.tenant.logo_path,
        branchName: data.session.branch?.name ?? null,
        pinRequired: data.settings.pin_required,
        autoResetSeconds: data.settings.auto_reset_seconds,
      };
      setConfig(cfg);
      localStorage.setItem("kiosk_token", token);
      setScreen("kiosk");
    } catch {
      setSetupError("kiosk_page.token_invalid");
      localStorage.removeItem("kiosk_token");
    } finally {
      setSetupLoading(false);
    }
  }

  function pressDigit(d: string) {
    if (showPin) {
      if (pin.length >= 6) return;
      setPin((p) => p + d);
    } else {
      if (code.length >= 8) return;
      setCode((p) => p + d);
    }
  }

  function backspace() {
    if (showPin) {
      setPin((p) => p.slice(0, -1));
    } else {
      setCode((p) => p.slice(0, -1));
    }
  }

  function clear() {
    if (showPin) {
      setPin("");
    } else {
      setCode("");
    }
  }

  const submit = useCallback(async () => {
    if (!code || !config) return;

    if (config.pinRequired && !showPin) {
      setShowPin(true);
      return;
    }

    setMode("checking");
    try {
      const data = await kioskPunch(config.token, {
        employee_code: code,
        type,
        pin: config.pinRequired ? pin : undefined,
        idempotency_key: `kiosk-${Date.now()}-${Math.random().toString(36).slice(2)}`,
      });
      const empName =
        data.employee_name ??
        data.employee?.name ??
        t("kiosk_page.default_employee");
      setMode("success");
      setMessage(
        t(
          type === "check_in" ? "kiosk_page.welcome" : "kiosk_page.goodbye",
          undefined,
          { name: empName },
        ),
      );
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { detail?: string } } };
      setMessage(
        axiosErr.response?.data?.detail ?? t("kiosk_page.check_failed"),
      );
      setMode("error");
    }
  }, [code, config, pin, showPin, type, t]);

  function enterFullscreen() {
    document.documentElement.requestFullscreen?.().catch(() => {});
  }

  function handleLockExit() {
    setScreen("lock");
    setAdminPin("");
    setLockError("");
  }

  function exitKiosk() {
    localStorage.removeItem("kiosk_token");
    setConfig(null);
    setScreen("setup");
    setSetupToken("");
    if (document.fullscreenElement) {
      document.exitFullscreen?.().catch(() => {});
    }
  }

  // Setup screen
  if (screen === "setup") {
    return (
      <div className="min-h-screen flex items-center justify-center p-6 bg-gradient-to-br from-background to-muted">
        <Card className="max-w-md w-full">
          <CardContent className="p-8 space-y-4">
            <div className="text-center">
              <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary/10">
                <KeyRound className="h-7 w-7 text-primary" />
              </div>
              <h1 className="mt-4 text-2xl font-bold">
                {t("kiosk_page.setup_title")}
              </h1>
              <p className="text-sm text-muted-foreground mt-1">
                {t("kiosk_page.setup_desc")}
              </p>
            </div>
            <div>
              <Label>{t("attendance.kiosks_page.kiosk_token")}</Label>
              <Input
                value={setupToken}
                onChange={(e) => setSetupToken(e.target.value)}
                placeholder={t("kiosk_page.token_placeholder")}
                className="mt-1 font-mono text-xs"
                autoFocus
              />
            </div>
            {setupError && (
              <p className="text-sm text-destructive">{t(setupError)}</p>
            )}
            <Button
              onClick={() => authenticateToken(setupToken)}
              className="w-full"
              disabled={!setupToken.trim() || setupLoading}
            >
              {setupLoading && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              {t("kiosk_page.activate_kiosk")}
            </Button>
          </CardContent>
        </Card>
      </div>
    );
  }

  // Lock screen (admin PIN to exit)
  if (screen === "lock") {
    return (
      <div className="min-h-screen flex items-center justify-center p-6">
        <Card className="max-w-sm w-full">
          <CardContent className="p-8 space-y-4">
            <div className="text-center">
              <Lock className="mx-auto h-10 w-10 text-muted-foreground" />
              <h2 className="mt-3 text-xl font-bold">
                {t("kiosk_page.lock_title")}
              </h2>
              <p className="text-sm text-muted-foreground mt-1">
                {t("kiosk_page.lock_desc")}
              </p>
            </div>
            <Input
              type="password"
              value={adminPin}
              onChange={(e) => setAdminPin(e.target.value.replace(/\D/g, ""))}
              placeholder={t("kiosk_page.admin_pin_placeholder")}
              maxLength={8}
              className="text-center text-2xl tracking-[0.5em] font-mono"
              autoFocus
            />
            {lockError && (
              <p className="text-sm text-destructive text-center">
                {lockError}
              </p>
            )}
            <div className="flex gap-2">
              <Button
                variant="outline"
                onClick={() => setScreen("kiosk")}
                className="flex-1"
              >
                {t("common.back")}
              </Button>
              <Button
                variant="destructive"
                onClick={() => {
                  if (adminPin.length >= 4) {
                    exitKiosk();
                  } else {
                    setLockError(
                      t("kiosk_page.pin_too_short", undefined, { min: 4 }),
                    );
                  }
                }}
                className="flex-1"
              >
                {t("kiosk_page.exit_kiosk")}
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  // Main kiosk screen
  return (
    <div className="min-h-screen flex flex-col select-none">
      {/* Header */}
      <header className="border-b bg-card px-6 py-4 flex items-center justify-between">
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary text-primary-foreground font-bold">
            E
          </div>
          <div>
            <p className="text-lg font-bold">
              {config?.tenantName ?? t("kiosk_page.default_title")}
            </p>
            <p className="text-xs text-muted-foreground">
              {config?.branchName ?? t("kiosk_page.unknown_branch")}
            </p>
          </div>
        </div>
        {/* The kiosk has no authenticated tenant query, so this clock cannot
            read the tenant's configured zone -- `/kiosk/authenticate` returns
            the tenant's name, subdomain and logo, but not its timezone. It is
            pinned to the product default instead of the device clock, which is
            the same fallback every other screen uses when the tenant is
            unknown, and which a mis-set kiosk OS cannot skew. A tenant on a
            different zone still sees Addis here; closing that means adding
            `timezone` to the kiosk payload. See BASELINE 12g. */}
        <div className="flex items-center gap-3">
          <div className="text-right">
            <p className="text-3xl font-bold font-mono tabular-nums">
              {now.toLocaleTimeString("en-ET", {
                hour: "2-digit",
                minute: "2-digit",
                timeZone: DEFAULT_TIMEZONE,
              })}
            </p>
            <p className="text-xs text-muted-foreground">
              {now.toLocaleDateString("en-ET", {
                weekday: "long",
                month: "long",
                day: "numeric",
                timeZone: DEFAULT_TIMEZONE,
              })}
            </p>
          </div>
          <div className="flex flex-col gap-1 ml-4">
            <Button
              variant="ghost"
              size="icon"
              onClick={enterFullscreen}
              title={t("kiosk_page.fullscreen")}
              aria-label={t("kiosk_page.fullscreen")}
            >
              <Maximize className="h-4 w-4" />
            </Button>
            <Button
              variant="ghost"
              size="icon"
              onClick={handleLockExit}
              title={t("kiosk_page.exit")}
              aria-label={t("kiosk_page.exit")}
            >
              <Lock className="h-4 w-4" />
            </Button>
          </div>
        </div>
      </header>

      {/* Main */}
      <main className="flex-1 flex items-center justify-center p-6">
        {mode === "success" ? (
          <div className="text-center space-y-6 animate-in zoom-in duration-300">
            <div className="mx-auto flex h-32 w-32 items-center justify-center rounded-full bg-success-soft">
              <CheckCircle2 className="h-20 w-20 text-success" />
            </div>
            <p className="text-4xl font-bold text-foreground">{message}</p>
            <p className="text-sm text-muted-foreground">
              {t("kiosk_page.recorded_at", undefined, {
                time: now.toLocaleTimeString("en-ET", {
                  hour: "2-digit",
                  minute: "2-digit",
                  timeZone: DEFAULT_TIMEZONE,
                }),
              })}
            </p>
          </div>
        ) : mode === "error" ? (
          <div className="text-center space-y-6 animate-in zoom-in duration-300">
            <div className="mx-auto flex h-32 w-32 items-center justify-center rounded-full bg-destructive-soft">
              <XCircle className="h-20 w-20 text-destructive" />
            </div>
            <p className="text-3xl font-bold text-foreground">{message}</p>
            <p className="text-sm text-muted-foreground">
              {t("kiosk_page.try_again")}
            </p>
          </div>
        ) : (
          <div className="w-full max-w-md space-y-6">
            {/* Type toggle */}
            <div className="flex rounded-xl border-2 p-1">
              <button
                onClick={() => setType("check_in")}
                className={cn(
                  "flex-1 rounded-lg py-3 font-semibold transition-all flex items-center justify-center gap-2",
                  type === "check_in"
                    ? "bg-success text-success-foreground shadow-md"
                    : "text-muted-foreground",
                )}
              >
                <LogIn className="h-4 w-4" /> {t("common.check_in")}
              </button>
              <button
                onClick={() => setType("check_out")}
                className={cn(
                  "flex-1 rounded-lg py-3 font-semibold transition-all flex items-center justify-center gap-2",
                  type === "check_out"
                    ? "bg-warning text-warning-foreground shadow-md"
                    : "text-muted-foreground",
                )}
              >
                <LogOut className="h-4 w-4" /> {t("common.check_out")}
              </button>
            </div>

            {/* Input display */}
            <Card>
              <CardContent className="p-6 text-center">
                <Label className="text-xs uppercase tracking-wider text-muted-foreground flex items-center justify-center gap-1">
                  <KeyRound className="h-3 w-3" />{" "}
                  {showPin
                    ? t("kiosk_page.employee_pin")
                    : t("kiosk_page.employee_code")}
                </Label>
                <div className="mt-3 flex justify-center gap-2">
                  {showPin
                    ? Array.from({ length: 6 }).map((_, i) => (
                        <div
                          key={i}
                          className={cn(
                            "h-12 w-8 rounded border-2 flex items-center justify-center text-2xl font-mono font-bold",
                            pin[i]
                              ? "border-primary bg-primary/5"
                              : "border-muted",
                          )}
                        >
                          {pin[i] ? "•" : ""}
                        </div>
                      ))
                    : Array.from({ length: 8 }).map((_, i) => (
                        <div
                          key={i}
                          className={cn(
                            "h-12 w-8 rounded border-2 flex items-center justify-center text-2xl font-mono font-bold",
                            code[i]
                              ? "border-primary bg-primary/5"
                              : "border-muted",
                          )}
                        >
                          {code[i] ?? ""}
                        </div>
                      ))}
                </div>
                {showPin && (
                  <button
                    className="mt-2 text-xs text-muted-foreground underline"
                    onClick={() => {
                      setShowPin(false);
                      setPin("");
                    }}
                  >
                    {t("kiosk_page.back_to_code")}
                  </button>
                )}
              </CardContent>
            </Card>

            {/* Number pad */}
            <div className="grid grid-cols-3 gap-3">
              {["1", "2", "3", "4", "5", "6", "7", "8", "9"].map((d) => (
                <Button
                  key={d}
                  variant="outline"
                  className="h-16 text-2xl font-bold"
                  onClick={() => pressDigit(d)}
                  disabled={mode === "checking"}
                >
                  {d}
                </Button>
              ))}
              <Button
                variant="outline"
                className="h-16"
                onClick={clear}
                disabled={mode === "checking"}
              >
                {t("kiosk_page.clear")}
              </Button>
              <Button
                variant="outline"
                className="h-16 text-2xl font-bold"
                onClick={() => pressDigit("0")}
                disabled={mode === "checking"}
              >
                0
              </Button>
              <Button
                variant="outline"
                className="h-16"
                onClick={backspace}
                disabled={mode === "checking"}
                aria-label={t("kiosk_page.delete_digit")}
              >
                ⌫
              </Button>
            </div>

            <Button
              className="w-full h-14 text-lg"
              onClick={submit}
              disabled={
                mode === "checking" ||
                (showPin ? pin.length === 0 : code.length === 0)
              }
            >
              {mode === "checking" ? (
                <span className="flex items-center gap-2">
                  <Clock className="h-5 w-5 animate-spin" />{" "}
                  {t("kiosk_page.processing")}
                </span>
              ) : showPin ? (
                t("kiosk_page.verify_pin")
              ) : config?.pinRequired ? (
                t("kiosk_page.next_enter_pin")
              ) : type === "check_in" ? (
                t("common.check_in")
              ) : (
                t("common.check_out")
              )}
            </Button>
          </div>
        )}
      </main>

      <footer className="border-t bg-muted/30 px-6 py-2 text-center text-xs text-muted-foreground">
        {config?.pinRequired
          ? t("kiosk_page.footer_with_pin")
          : t("kiosk_page.footer")}
      </footer>
    </div>
  );
}
