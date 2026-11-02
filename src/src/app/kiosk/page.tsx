"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import {
  LogIn,
  LogOut,
  ArrowLeftRight,
  XCircle,
  Clock,
  KeyRound,
  Lock,
  Loader2,
  WifiOff,
  CloudUpload,
  Hand,
  UserRound,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  DEFAULT_TIMEZONE,
  formatCalendarDayMonth,
  formatCalendarWeekday,
  formatClockTime,
} from "@/lib/utils/date";
import { resolveClock } from "@/lib/calendar/ethiopian-time";
import {
  authenticateKiosk,
  kioskPunch,
  reportKioskPunch,
  verifyKioskExit,
  type KioskPunchResult,
} from "@/features/kiosk/api";
import {
  EXIT_HOLD_MS,
  useHold,
  useIdle,
  useKioskLock,
} from "@/features/kiosk/kiosk-lock";
import { KioskKeypad, PinDots } from "@/features/kiosk/components/kiosk-keypad";
import { KioskPunchResultCard } from "@/features/kiosk/components/kiosk-punch-result";
import { KioskSelfService } from "@/features/kiosk/components/kiosk-self-service";
import {
  useKioskSound,
  type KioskSoundMode,
} from "@/features/kiosk/kiosk-sound";
import { type KioskCodeFormat } from "@/features/kiosk/kiosk-code";
import { KioskCodeHint } from "@/features/kiosk/components/kiosk-code-hint";
import {
  cacheKioskConfig,
  cachedKioskConfig,
  forgetKioskConfig,
  isUnreachable,
  queuedPunches,
  savePunchOffline,
  syncQueuedPunches,
  type KioskOfflineConfig,
} from "@/features/kiosk/offline";
import { cn } from "@/lib/utils";
import { useT } from "@/lib/i18n/useT";
import { apiErrorDetail, apiErrorMessage } from "@/lib/api/error-message";

type Screen = "setup" | "kiosk" | "lock" | "self_service";
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
  /** What the kiosk says on each result; absent from older caches. */
  sound?: KioskSoundMode;
  /** The organisation's employee number format; absent from older caches. */
  codeFormat?: KioskCodeFormat | null;
  /** For punching through a power cut or outage; absent from older caches. */
  offline?: KioskOfflineConfig | null;
}

/** `employee_code` is `max:30` in StoreEmployeeRequest; the kiosk accepts the same. */
const EMPLOYEE_CODE_MAX = 30;

/** A half-typed code or PIN is wiped after this long untouched. */
const INPUT_IDLE_MS = 20_000;
/** The punch screen goes to its resting clock after this long untouched. */
const SLEEP_IDLE_MS = 60_000;
/** Time to notice the wrong face and press "Not you?". */
const REPORT_WINDOW_SECONDS = 8;

export default function KioskPage() {
  const { t, locale } = useT();
  // A signed-out terminal has no person's preference to read, so it follows
  // its display language: Amharic reads the Ethiopian calendar and clock,
  // anything else Gregorian and 24-hour.
  const calendar = locale === "am" ? "ethiopian" : "gregorian";
  const clock = resolveClock("auto", calendar, locale);
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
  // The tablet's own keyboard, for a code outside the organisation's number
  // format. Closed by default, so a tap on the code field does not throw a
  // keyboard over the pad.
  const [letters, setLetters] = useState(false);
  const codeInputRef = useRef<HTMLInputElement | null>(null);
  // `auto` unless the person picks: a shared terminal cannot know, before
  // the code is typed, whether this person is arriving or leaving, and the
  // server does (KioskCheckInRequest). Starting on Check in made a leaver
  // who had checked in on the phone open a second, conflicting record.
  const [type, setType] = useState<"auto" | "check_in" | "check_out">("auto");
  const [mode, setMode] = useState<CheckMode>("idle");
  const [message, setMessage] = useState("");
  const [now, setNow] = useState(new Date());
  // The time the server recorded for the last punch, or the terminal's own
  // when it was saved offline.
  const [recordedAt, setRecordedAt] = useState<string | null>(null);
  // Who the last punch was for, and which punch, for the confirmation and
  // for "Not you?". Null for a punch saved offline.
  const [lastPunch, setLastPunch] = useState<{
    result: KioskPunchResult;
    punch: "check_in" | "check_out";
  } | null>(null);
  // The resting screen: the first touch only wakes it, so brushing against
  // the terminal presses nothing.
  const [sleeping, setSleeping] = useState(false);

  // Lock screen
  const [adminPin, setAdminPin] = useState("");
  const [lockError, setLockError] = useState("");
  const [lockLoading, setLockLoading] = useState(false);

  const play = useKioskSound(config?.sound ?? "tones", locale, t);

  const resetTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Offline: started from the cached configuration, or a punch could not
  // reach the server. Punches made meanwhile wait, sealed, on this terminal.
  const [offline, setOffline] = useState(false);
  const [pending, setPending] = useState(0);

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
          setLetters(false);
          setPin("");
          setShowPin(false);
          setMessage("");
          setType("auto");
          setRecordedAt(null);
          setLastPunch(null);
        },
        (lastPunch
          ? Math.max(config?.autoResetSeconds ?? 4, REPORT_WINDOW_SECONDS)
          : (config?.autoResetSeconds ?? 4)) * 1000,
      );
      return () => {
        if (resetTimerRef.current) clearTimeout(resetTimerRef.current);
      };
    }
  }, [mode, config?.autoResetSeconds, lastPunch]);

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
        sound: data.settings.sound,
        codeFormat: data.settings.code_format,
        offline: data.offline,
      };
      setConfig(cfg);
      cacheKioskConfig(cfg);
      setOffline(false);
      localStorage.setItem("kiosk_token", token);
      setScreen("kiosk");
    } catch (err) {
      // A kiosk restarting in a power cut has no connection to check its
      // token with. It opens from its last configuration instead of
      // forgetting the token, which left the terminal unusable until
      // someone re-registered it.
      const cached = isUnreachable(err)
        ? cachedKioskConfig<KioskConfig>(token)
        : null;
      if (cached) {
        setConfig(cached);
        setOffline(true);
        setScreen("kiosk");
      } else {
        setSetupError("kiosk_page.token_invalid");
        localStorage.removeItem("kiosk_token");
        forgetKioskConfig();
      }
    } finally {
      setSetupLoading(false);
    }
  }

  function pressDigit(d: string) {
    if (showPin) {
      if (pin.length >= 6) return;
      setPin((p) => p + d);
    } else {
      if (code.length >= EMPLOYEE_CODE_MAX) return;
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
    const idempotencyKey = `kiosk-${Date.now()}-${Math.random().toString(36).slice(2)}`;

    // Saved on the terminal, sealed, to be sent when the connection returns.
    async function keepOffline() {
      if (!config?.offline) {
        setMessage(t("kiosk_page.offline_unavailable"));
        setMode("error");
        play("error");
        return;
      }
      try {
        await savePunchOffline(config.offline, {
          c: code,
          p: config.pinRequired ? pin : null,
          t: type,
          k: idempotencyKey,
        });
        setOffline(true);
        setPending((n) => n + 1);
        // No server time to show: the terminal's own, when it was saved.
        setRecordedAt(new Date().toISOString());
        setMode("success");
        setMessage(t("kiosk_page.saved_offline", undefined, { code }));
        play("saved");
      } catch {
        setMessage(t("kiosk_page.check_failed"));
        setMode("error");
        play("error");
      }
    }

    if (typeof navigator !== "undefined" && !navigator.onLine) {
      await keepOffline();
      return;
    }

    try {
      const data = await kioskPunch(config.token, {
        employee_code: code,
        type,
        pin: config.pinRequired ? pin : undefined,
        idempotency_key: idempotencyKey,
      });
      const empName =
        (locale === "am" ? data.employee?.name_am : null) ??
        data.employee_name ??
        data.employee?.name ??
        t("kiosk_page.default_employee");
      const punched = type === "auto" ? data.punch : type;
      setLastPunch(
        data.public_id
          ? {
              result: data,
              punch: punched === "check_out" ? "check_out" : "check_in",
            }
          : null,
      );
      setRecordedAt(
        (punched === "check_out" ? data.check_out : data.check_in) ?? null,
      );
      setMode("success");
      setMessage(
        t(
          punched === "check_out" ? "kiosk_page.goodbye" : "kiosk_page.welcome",
          undefined,
          { name: empName },
        ),
      );
      play(
        data.was_duplicate
          ? "duplicate"
          : punched === "check_out"
            ? "check_out"
            : "check_in",
      );
    } catch (err: unknown) {
      if (isUnreachable(err)) {
        await keepOffline();
        return;
      }
      setMessage(apiErrorDetail(err) ?? t("kiosk_page.check_failed"));
      setMode("error");
      // 429 is the five-wrong-PINs lock: another try will not help.
      play(
        (err as { response?: { status?: number } })?.response?.status === 429
          ? "locked"
          : "error",
      );
    }
  }, [code, config, pin, showPin, type, t, locale, play]);

  // Send what waited whenever the connection is back: on the browser's
  // "online" event, and every 30 seconds in case that event never comes.
  useEffect(() => {
    if (!config || screen !== "kiosk") return;
    const token = config.token;
    let busy = false;

    async function flush() {
      if (busy) return;
      busy = true;
      try {
        if ((await queuedPunches()).length > 0) {
          await syncQueuedPunches(token);
        }
        setOffline(false);
      } catch (err) {
        if (isUnreachable(err)) setOffline(true);
      } finally {
        try {
          setPending((await queuedPunches()).length);
        } catch {
          // IndexedDB unavailable: nothing was queued.
        }
        busy = false;
      }
    }

    void flush();
    const id = window.setInterval(() => void flush(), 30_000);
    window.addEventListener("online", flush);
    return () => {
      window.clearInterval(id);
      window.removeEventListener("online", flush);
    };
  }, [config, screen]);

  const { fullscreen, fullscreenSupported, enterFullscreen } = useKioskLock(
    screen !== "setup",
  );

  const handleLockExit = useCallback(() => {
    setScreen("lock");
    setAdminPin("");
    setLockError("");
  }, []);
  const exitHold = useHold(EXIT_HOLD_MS, handleLockExit);

  const punchScreenIdle = screen === "kiosk" && mode === "idle";
  useIdle(
    INPUT_IDLE_MS,
    () => {
      setCode("");
      setLetters(false);
      setPin("");
      setShowPin(false);
      setType("auto");
    },
    punchScreenIdle &&
      (code !== "" || pin !== "" || type !== "auto" || letters),
  );
  useIdle(SLEEP_IDLE_MS, () => setSleeping(true), punchScreenIdle && !sleeping);

  // Shown over the punch screen when it is resting, or when it has dropped
  // out of full screen. The tap that dismisses it asks for full screen
  // again, since browsers grant it only from a tap.
  const covered =
    screen === "kiosk" && (sleeping || (fullscreenSupported && !fullscreen));

  // The server compares the PIN set when the kiosk was registered. Until
  // 2026-10-09 this screen accepted any four digits (redundancy audit R1).
  async function confirmExit() {
    if (!config) return;
    setLockLoading(true);
    setLockError("");
    try {
      await verifyKioskExit(config.token, adminPin);
      exitKiosk();
    } catch (err: unknown) {
      // A 401 means the kiosk was deactivated or its token regenerated. It
      // can no longer record anyone, so there is nothing left to protect.
      if (
        (err as { response?: { status?: number } })?.response?.status === 401
      ) {
        exitKiosk();
        return;
      }
      setAdminPin("");
      setLockError(apiErrorMessage(err, t("kiosk_page.exit_failed")));
      play("error");
    } finally {
      setLockLoading(false);
    }
  }

  function exitKiosk() {
    localStorage.removeItem("kiosk_token");
    forgetKioskConfig();
    setConfig(null);
    setScreen("setup");
    setSetupToken("");
    if (document.fullscreenElement) {
      document.exitFullscreen?.().catch(() => {});
    }
  }

  const nowIso = now.toISOString();

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
                disabled={lockLoading}
                onClick={() => {
                  if (adminPin.length >= 4) {
                    void confirmExit();
                  } else {
                    setLockError(
                      t("kiosk_page.pin_too_short", undefined, { min: 4 }),
                    );
                  }
                }}
                className="flex-1"
              >
                {lockLoading && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
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
          {/* The way out of kiosk mode: hold the name for three seconds. A
              visible Exit button was one tap from the admin PIN screen for
              anyone in the queue. The Kiosks page tells admins where it is. */}
          <button
            type="button"
            className="relative flex items-center gap-3 rounded-xl text-left touch-none"
            aria-label={t("kiosk_page.exit_hold")}
            title={t("kiosk_page.exit_hold")}
            {...exitHold.handlers}
          >
            <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary text-primary-foreground font-bold">
              E
            </span>
            <span>
              <span className="block text-lg font-bold">
                {config?.tenantName ?? t("kiosk_page.default_title")}
              </span>
              <span className="block text-xs text-muted-foreground">
                {config?.branchName ?? t("kiosk_page.unknown_branch")}
              </span>
            </span>
            {exitHold.progress > 0 && (
              <span
                className="absolute -bottom-2 left-0 h-1 rounded-full bg-primary"
                style={{ width: `${Math.round(exitHold.progress * 100)}%` }}
                aria-hidden="true"
              />
            )}
          </button>
          {(offline || pending > 0) && (
            <span
              className="ml-2 inline-flex items-center gap-1.5 rounded-full bg-warning-soft px-3 py-1 text-xs font-medium text-warning-on-soft"
              role="status"
            >
              {offline ? (
                <WifiOff className="h-3.5 w-3.5" aria-hidden="true" />
              ) : (
                <CloudUpload className="h-3.5 w-3.5" aria-hidden="true" />
              )}
              {offline
                ? t("kiosk_page.offline_badge", undefined, { count: pending })
                : t("kiosk_page.sending_badge", undefined, { count: pending })}
            </span>
          )}
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
              {formatClockTime(nowIso, DEFAULT_TIMEZONE, clock, locale)}
            </p>
            <p className="text-xs text-muted-foreground">
              {formatCalendarWeekday(nowIso, DEFAULT_TIMEZONE, locale, "long")},{" "}
              {formatCalendarDayMonth(
                nowIso,
                DEFAULT_TIMEZONE,
                calendar,
                locale,
              )}
            </p>
          </div>
        </div>
      </header>

      {/* Main */}
      <main className="flex-1 flex items-center justify-center p-6">
        {screen === "self_service" && config ? (
          <KioskSelfService
            token={config.token}
            timeZone={DEFAULT_TIMEZONE}
            calendar={calendar}
            clock={clock}
            codeFormat={config.codeFormat}
            onCue={play}
            onClose={() => setScreen("kiosk")}
          />
        ) : mode === "success" ? (
          <KioskPunchResultCard
            message={message}
            time={formatClockTime(
              recordedAt ?? nowIso,
              DEFAULT_TIMEZONE,
              clock,
              locale,
            )}
            person={lastPunch?.result.employee ?? null}
            canReport={lastPunch !== null && !offline}
            onReport={async () => {
              if (!config || !lastPunch?.result.public_id) return;
              await reportKioskPunch(
                config.token,
                lastPunch.result.public_id,
                lastPunch.punch,
              );
              play("reported");
            }}
          />
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
                onClick={() => setType("auto")}
                className={cn(
                  "flex-1 rounded-lg py-3 font-semibold transition-all flex items-center justify-center gap-2",
                  type === "auto"
                    ? "bg-primary text-primary-foreground shadow-md"
                    : "text-muted-foreground",
                )}
              >
                <ArrowLeftRight className="h-4 w-4" /> {t("kiosk_page.auto")}
              </button>
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
            {type === "auto" && (
              <p className="text-center text-sm text-muted-foreground">
                {t("kiosk_page.auto_hint")}
              </p>
            )}

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
                  {showPin ? (
                    <PinDots value={pin} />
                  ) : (
                    // A text field, not eight digit boxes: employee codes
                    // are any string up to 30 characters (audit N61). The
                    // pad types the number alone, which stands for the full
                    // code in the organisation's format (shown below);
                    // letters come from the tablet's keyboard behind ABC.
                    <Input
                      ref={codeInputRef}
                      aria-label={t("kiosk_page.employee_code")}
                      inputMode={letters ? "text" : "none"}
                      value={code}
                      onChange={(e) =>
                        setCode(e.target.value.slice(0, EMPLOYEE_CODE_MAX))
                      }
                      maxLength={EMPLOYEE_CODE_MAX}
                      autoComplete="off"
                      autoCapitalize="characters"
                      spellCheck={false}
                      disabled={mode === "checking"}
                      className="h-14 max-w-xs text-center text-2xl font-mono font-bold"
                    />
                  )}
                </div>
                {!showPin && (
                  <KioskCodeHint
                    code={code}
                    format={config?.codeFormat}
                    letters={letters}
                    onLetters={() => {
                      setLetters(true);
                      // After the render that switches the field's keyboard
                      // on, or the tablet opens the number-less one.
                      window.setTimeout(() => codeInputRef.current?.focus(), 0);
                    }}
                  />
                )}
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

            <KioskKeypad
              onDigit={pressDigit}
              onClear={clear}
              onBackspace={backspace}
              disabled={mode === "checking"}
            />

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
              ) : type === "auto" ? (
                t("kiosk_page.record")
              ) : type === "check_in" ? (
                t("common.check_in")
              ) : (
                t("common.check_out")
              )}
            </Button>

            <Button
              variant="ghost"
              className="w-full h-12 text-base"
              onClick={() => {
                setCode("");
                setPin("");
                setShowPin(false);
                setScreen("self_service");
              }}
              disabled={mode === "checking" || offline}
            >
              <UserRound className="mr-2 h-5 w-5" />
              {t("kiosk_page.self_service_entry")}
            </Button>
          </div>
        )}
      </main>

      {covered && (
        <button
          type="button"
          className="fixed inset-0 z-50 flex flex-col items-center justify-center gap-6 bg-background/95 backdrop-blur-sm"
          aria-label={t("kiosk_page.tap_to_start")}
          onPointerDown={(e) => e.preventDefault()}
          onClick={() => {
            setSleeping(false);
            enterFullscreen();
          }}
        >
          <span className="text-7xl font-bold font-mono tabular-nums">
            {formatClockTime(nowIso, DEFAULT_TIMEZONE, clock, locale)}
          </span>
          <span className="text-xl text-muted-foreground">
            {formatCalendarWeekday(nowIso, DEFAULT_TIMEZONE, locale, "long")},{" "}
            {formatCalendarDayMonth(nowIso, DEFAULT_TIMEZONE, calendar, locale)}
          </span>
          <span className="mt-6 inline-flex items-center gap-2 rounded-full bg-primary px-6 py-3 text-lg font-semibold text-primary-foreground">
            <Hand className="h-5 w-5" aria-hidden="true" />
            {t("kiosk_page.tap_to_start")}
          </span>
        </button>
      )}

      <footer className="border-t bg-muted/30 px-6 py-2 text-center text-xs text-muted-foreground">
        {config?.pinRequired
          ? t("kiosk_page.footer_with_pin")
          : t("kiosk_page.footer")}
      </footer>
    </div>
  );
}
