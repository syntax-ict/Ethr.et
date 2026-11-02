"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { ArrowLeft, KeyRound, Loader2, LogOut } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { EmployeeAvatar } from "@/components/shared/employee-avatar";
import { useT } from "@/lib/i18n/useT";
import { apiErrorDetail } from "@/lib/api/error-message";
import {
  formatCalendarDayMonth,
  formatCalendarWeekday,
  formatClockTime,
} from "@/lib/utils/date";
import type { ClockSystem } from "@/lib/calendar/ethiopian-time";
import type { CalendarSystem } from "@/lib/calendar/calendar-context";
import {
  changeKioskPin,
  endKioskSelfService,
  kioskSelfService,
  startKioskSelfService,
  type KioskSelfServiceSummary,
} from "../api";
import { useIdle } from "../kiosk-lock";
import { KioskKeypad, PinDots } from "./kiosk-keypad";

/** A self-service screen left alone this long signs the person out. */
export const SELF_SERVICE_IDLE_MS = 30_000;

const CODE_MAX = 30;
const PIN_MAX = 6;

type Step = "sign_in" | "summary" | "pin";
type PinField = "current" | "next" | "confirm";

/**
 * Self-service at the kiosk, for people with no phone or email: their own
 * week and leave balances, and a new PIN of their own choosing.
 *
 * Code and PIN every time, a pass that lasts minutes and works only at this
 * kiosk, a sign-out after 30 quiet seconds, and nothing about pay: it is a
 * screen in a lobby, and the next person in the queue is standing behind.
 */
export function KioskSelfService({
  token,
  timeZone,
  calendar,
  clock,
  onClose,
}: {
  token: string;
  timeZone: string;
  calendar: CalendarSystem;
  clock: ClockSystem;
  onClose: () => void;
}) {
  const { t, locale } = useT();
  const [step, setStep] = useState<Step>("sign_in");
  const [code, setCode] = useState("");
  const [pin, setPin] = useState("");
  const [field, setField] = useState<"code" | "pin">("code");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [summary, setSummary] = useState<KioskSelfServiceSummary | null>(null);
  const [notice, setNotice] = useState("");
  const pass = useRef<string | null>(null);

  const [pins, setPins] = useState<Record<PinField, string>>({
    current: "",
    next: "",
    confirm: "",
  });
  const [pinField, setPinField] = useState<PinField>("current");

  const close = useCallback(() => {
    if (pass.current) void endKioskSelfService(token, pass.current);
    pass.current = null;
    onClose();
  }, [onClose, token]);

  useIdle(SELF_SERVICE_IDLE_MS, close);

  // Leaving the screen any other way still ends the pass, and drops the
  // timer that would otherwise close whoever uses self-service next.
  const expiry = useRef<number | null>(null);
  useEffect(
    () => () => {
      if (expiry.current !== null) window.clearTimeout(expiry.current);
      if (pass.current) void endKioskSelfService(token, pass.current);
    },
    [token],
  );

  async function signIn() {
    if (!code || !pin) return;
    setBusy(true);
    setError("");
    try {
      const started = await startKioskSelfService(token, code, pin);
      pass.current = started.pass;
      setSummary(await kioskSelfService(token, started.pass));
      setStep("summary");
      // The pass dies on the server at expires_at; the screen goes with it.
      const left = new Date(started.expires_at).getTime() - Date.now();
      expiry.current = window.setTimeout(close, Math.max(left, 0));
    } catch (err) {
      setPin("");
      setError(apiErrorDetail(err) ?? t("kiosk_page.self_service_failed"));
    } finally {
      setBusy(false);
    }
  }

  async function savePin() {
    if (!pass.current) return close();
    if (pins.next !== pins.confirm) {
      setError(t("kiosk_page.pin_mismatch"));
      return;
    }
    setBusy(true);
    setError("");
    try {
      await changeKioskPin(token, pass.current, {
        current_pin: pins.current,
        pin: pins.next,
        pin_confirmation: pins.confirm,
      });
      setPins({ current: "", next: "", confirm: "" });
      setNotice(t("kiosk_page.pin_changed"));
      setStep("summary");
    } catch (err) {
      setPins({ current: "", next: "", confirm: "" });
      setPinField("current");
      setError(apiErrorDetail(err) ?? t("kiosk_page.self_service_failed"));
    } finally {
      setBusy(false);
    }
  }

  // ── Keypad routing ──
  function digit(d: string) {
    if (step === "pin") {
      setPins((p) =>
        p[pinField].length >= PIN_MAX
          ? p
          : { ...p, [pinField]: p[pinField] + d },
      );
    } else if (field === "pin") {
      setPin((p) => (p.length >= PIN_MAX ? p : p + d));
    } else {
      setCode((c) => (c.length >= CODE_MAX ? c : c + d));
    }
  }
  function backspace() {
    if (step === "pin") {
      setPins((p) => ({ ...p, [pinField]: p[pinField].slice(0, -1) }));
    } else if (field === "pin") setPin((p) => p.slice(0, -1));
    else setCode((c) => c.slice(0, -1));
  }
  function clear() {
    if (step === "pin") setPins((p) => ({ ...p, [pinField]: "" }));
    else if (field === "pin") setPin("");
    else setCode("");
  }

  if (step === "sign_in") {
    return (
      <div className="w-full max-w-md space-y-5">
        <div className="text-center">
          <h2 className="text-2xl font-bold">
            {t("kiosk_page.self_service_title")}
          </h2>
          <p className="text-sm text-muted-foreground mt-1">
            {t("kiosk_page.self_service_desc")}
          </p>
        </div>
        <Card>
          <CardContent className="p-6 space-y-4 text-center">
            {field === "code" ? (
              <>
                <Label
                  htmlFor="kiosk-self-code"
                  className="text-xs uppercase tracking-wider text-muted-foreground"
                >
                  {t("kiosk_page.employee_code")}
                </Label>
                <Input
                  id="kiosk-self-code"
                  value={code}
                  onChange={(e) => setCode(e.target.value.slice(0, CODE_MAX))}
                  autoComplete="off"
                  autoCapitalize="characters"
                  spellCheck={false}
                  className="h-14 text-center text-2xl font-mono font-bold"
                />
              </>
            ) : (
              <>
                <p className="text-xs uppercase tracking-wider text-muted-foreground flex items-center justify-center gap-1">
                  <KeyRound className="h-3 w-3" />{" "}
                  {t("kiosk_page.employee_pin")}
                </p>
                <PinDots value={pin} />
                <button
                  className="text-xs text-muted-foreground underline"
                  onClick={() => {
                    setField("code");
                    setPin("");
                  }}
                >
                  {t("kiosk_page.back_to_code")}
                </button>
              </>
            )}
            {error && (
              <p className="text-sm text-destructive" role="alert">
                {error}
              </p>
            )}
          </CardContent>
        </Card>
        <KioskKeypad
          onDigit={digit}
          onClear={clear}
          onBackspace={backspace}
          disabled={busy}
        />
        <div className="flex gap-2">
          <Button variant="outline" className="h-14 flex-1" onClick={close}>
            <ArrowLeft className="mr-2 h-4 w-4" /> {t("common.back")}
          </Button>
          <Button
            className="h-14 flex-1 text-lg"
            disabled={busy || (field === "code" ? !code : pin.length < 4)}
            onClick={() => (field === "code" ? setField("pin") : signIn())}
          >
            {busy && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            {field === "code"
              ? t("kiosk_page.next_enter_pin")
              : t("kiosk_page.self_service_open")}
          </Button>
        </div>
      </div>
    );
  }

  if (step === "pin") {
    const labels: Record<PinField, string> = {
      current: t("kiosk_page.pin_current"),
      next: t("kiosk_page.pin_new"),
      confirm: t("kiosk_page.pin_confirm"),
    };
    const order: PinField[] = ["current", "next", "confirm"];
    const last = pinField === "confirm";
    return (
      <div className="w-full max-w-md space-y-5">
        <h2 className="text-center text-2xl font-bold">
          {t("kiosk_page.change_pin")}
        </h2>
        <Card>
          <CardContent className="p-6 space-y-4">
            {order.map((f) => (
              <button
                key={f}
                type="button"
                onClick={() => setPinField(f)}
                className={
                  "w-full rounded-lg border-2 p-3 text-left " +
                  (pinField === f ? "border-primary" : "border-transparent")
                }
              >
                <span className="block text-xs uppercase tracking-wider text-muted-foreground mb-2">
                  {labels[f]}
                </span>
                <PinDots value={pins[f]} />
              </button>
            ))}
            <p className="text-xs text-muted-foreground text-center">
              {t("kiosk_page.pin_rule")}
            </p>
            {error && (
              <p className="text-sm text-destructive text-center" role="alert">
                {error}
              </p>
            )}
          </CardContent>
        </Card>
        <KioskKeypad
          onDigit={digit}
          onClear={clear}
          onBackspace={backspace}
          disabled={busy}
        />
        <div className="flex gap-2">
          <Button
            variant="outline"
            className="h-14 flex-1"
            onClick={() => {
              setPins({ current: "", next: "", confirm: "" });
              setPinField("current");
              setError("");
              setStep("summary");
            }}
          >
            {t("common.cancel")}
          </Button>
          <Button
            className="h-14 flex-1 text-lg"
            disabled={busy || pins[pinField].length < 4}
            onClick={() =>
              last ? savePin() : setPinField(order[order.indexOf(pinField) + 1])
            }
          >
            {busy && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            {last ? t("common.save") : t("common.next")}
          </Button>
        </div>
      </div>
    );
  }

  // Summary
  const person = summary?.employee;
  const name =
    person && locale === "am" && person.name_am ? person.name_am : person?.name;
  const time = (iso: string | null) =>
    iso ? formatClockTime(iso, timeZone, clock, locale) : "-";
  const hours = (minutes: number) =>
    t("kiosk_page.hours_minutes", undefined, {
      hours: Math.floor(minutes / 60),
      minutes: minutes % 60,
    });

  return (
    <div className="w-full max-w-2xl space-y-5">
      <div className="flex items-center gap-4">
        {person && name && (
          <EmployeeAvatar
            name={name}
            photoThumbUrl={person.photo_url}
            className="h-20 w-20"
            fallbackClassName="text-2xl font-bold"
          />
        )}
        <div className="flex-1">
          <p className="text-2xl font-bold">{name}</p>
          {person?.position && (
            <p className="text-muted-foreground">{person.position}</p>
          )}
        </div>
        <Button size="lg" onClick={close}>
          <LogOut className="mr-2 h-4 w-4" /> {t("kiosk_page.done")}
        </Button>
      </div>

      {notice && (
        <p
          className="rounded-lg bg-success-soft p-3 text-center text-success-on-soft"
          role="status"
        >
          {notice}
        </p>
      )}

      <Card>
        <CardContent className="p-5">
          <h3 className="mb-3 font-semibold">{t("kiosk_page.my_week")}</h3>
          {summary && summary.days.length > 0 ? (
            <ul className="divide-y">
              {summary.days.map((day, i) => (
                <li
                  key={`${day.date}-${i}`}
                  className="flex items-center justify-between gap-3 py-2 text-sm"
                >
                  <span className="w-36 font-medium">
                    {formatCalendarWeekday(day.date ?? "", timeZone, locale)},{" "}
                    {formatCalendarDayMonth(
                      day.date ?? "",
                      timeZone,
                      calendar,
                      locale,
                    )}
                  </span>
                  <span className="tabular-nums">
                    {time(day.check_in)} · {time(day.check_out)}
                  </span>
                  <span className="tabular-nums text-muted-foreground">
                    {hours(day.worked_minutes)}
                  </span>
                  <span className="w-24 text-right">
                    {day.status ? t(`status.${day.status}`, day.status) : ""}
                  </span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-muted-foreground">
              {t("kiosk_page.no_attendance")}
            </p>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardContent className="p-5">
          <h3 className="mb-3 font-semibold">{t("kiosk_page.my_leave")}</h3>
          {summary && summary.leave_balances.length > 0 ? (
            <ul className="grid gap-3 sm:grid-cols-2">
              {summary.leave_balances.map((b, i) => (
                <li
                  key={`${b.leave_type}-${i}`}
                  className="rounded-lg border p-3"
                >
                  <p className="text-sm text-muted-foreground">
                    {locale === "am" && b.leave_type_am
                      ? b.leave_type_am
                      : b.leave_type}
                  </p>
                  <p className="text-2xl font-bold tabular-nums">
                    {t("kiosk_page.days_left", undefined, {
                      days: b.remaining_days,
                    })}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {t("kiosk_page.days_used", undefined, {
                      used: b.used_days,
                      total: b.entitled_days,
                    })}
                  </p>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-muted-foreground">
              {t("kiosk_page.no_leave")}
            </p>
          )}
        </CardContent>
      </Card>

      <div className="flex justify-center">
        <Button
          variant="outline"
          onClick={() => {
            setNotice("");
            setError("");
            setStep("pin");
          }}
        >
          <KeyRound className="mr-2 h-4 w-4" /> {t("kiosk_page.change_pin")}
        </Button>
      </div>
    </div>
  );
}
