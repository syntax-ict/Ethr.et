"use client";

import { useState, type ChangeEvent } from "react";
import {
  Settings2,
  Loader2,
  Save,
  Clock,
  ShieldCheck,
  BellRing,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { PageActions } from "@/components/shared/page-actions";
import { RoleGate } from "@/components/shared/role-gate";
import {
  useAttendanceSettings,
  useUpdateAttendanceSettings,
  type AttendanceSettings,
} from "@/features/attendance/api";
import { useSaveStatus } from "@/components/shared/save-status";
import { useUnsavedChangesWarning } from "@/lib/hooks/useUnsavedChangesWarning";
import { useT } from "@/lib/i18n/useT";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { toast } from "sonner";
import { SettingRow } from "@/components/patterns/SettingRow";
import { SettingsSection } from "@/components/patterns/SettingsSection";
import { InputWithUnit } from "@/components/shared/input-with-unit";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { apiErrorMessage } from "@/lib/api/error-message";
import { ErrorState } from "@/components/shared/error-state";
import { BranchRulesCard } from "@/features/attendance/components/branch-rules-card";
import { WorkSitesCard } from "@/features/attendance/components/work-sites-card";
import { DeviceSilenceSetting } from "@/features/devices/components/device-silence-setting";
import { SetupStatusPanel } from "@/features/attendance/components/setup-status-panel";

type Method = AttendanceSettings["enabled_methods"][number];

const ALL_METHODS: {
  key: Method;
  labelKey: string;
  label: string;
  helpKey: string;
  help: string;
}[] = [
  {
    key: "biometric",
    labelKey: "attendance.settings_page.method_biometric_label",
    label: "Biometric device",
    helpKey: "attendance.settings_page.method_biometric_help",
    help: "Fingerprint or face terminals at the door.",
  },
  {
    key: "mobile",
    labelKey: "attendance.settings_page.method_mobile_label",
    label: "Phone check-in",
    helpKey: "attendance.settings_page.method_mobile_help",
    help: "From the employee's phone, with location and selfie.",
  },
  {
    key: "qr",
    labelKey: "attendance.settings_page.method_qr_label",
    label: "QR code",
    helpKey: "attendance.settings_page.method_qr_help",
    help: "Scan a code at the branch entrance.",
  },
  {
    key: "kiosk",
    labelKey: "attendance.settings_page.method_kiosk_label",
    label: "Kiosk",
    helpKey: "attendance.settings_page.method_kiosk_help",
    help: "A shared tablet; employees type their code.",
  },
  {
    key: "web",
    labelKey: "attendance.settings_page.method_web_label",
    label: "Computer",
    helpKey: "attendance.settings_page.method_web_help",
    help: "Check in from the dashboard on a computer.",
  },
  {
    key: "manual",
    labelKey: "attendance.settings_page.method_manual_label",
    label: "Manual entry",
    helpKey: "attendance.settings_page.method_manual_help",
    help: "HR records the punch by hand.",
  },
  {
    key: "csv",
    labelKey: "attendance.settings_page.method_csv_label",
    label: "Spreadsheet import",
    helpKey: "attendance.settings_page.method_csv_help",
    help: "Upload many punches from a spreadsheet.",
  },
];

/**
 * The whole-number fields and the range the server accepts for each. The
 * limits drive both the input's `min`/`max` and the message shown before a
 * save is allowed.
 */
const NUMERIC_LIMITS = {
  ot_daily_cap_minutes: { min: 0, max: 1440 },
  confidence_threshold: { min: 0, max: 100 },
  photo_retention_days: { min: 1, max: 90 },
  mobile_accuracy_threshold_meters: { min: 10, max: 5000 },
  qr_expiry_minutes: { min: 5, max: 480 },
  qr_single_use_limit: { min: 0, max: 1000 },
  kiosk_auto_reset_seconds: { min: 2, max: 30 },
  reminder_lead_minutes: { min: 0, max: 120 },
  auto_checkout_after_minutes: { min: 15, max: 720 },
  risk_review_threshold: { min: 10, max: 100 },
} as const satisfies Partial<Record<keyof AttendanceSettings, unknown>>;

type NumericKey = keyof typeof NUMERIC_LIMITS;

function parseNetworks(text: string): string[] {
  return text.split(/[\s,]+/).filter((part) => part !== "");
}

export default function AttendanceSettingsPage() {
  const { t } = useT();
  const { can } = usePermissions();
  const [form, setForm] = useState<AttendanceSettings | null>(null);
  // What the server last gave us; the header button is live only while the
  // form differs from it.
  const [baseline, setBaseline] = useState<AttendanceSettings | null>(null);
  // The text of a number field while it is being typed. A cleared field used
  // to snap straight back to a default, so "100" could not be retyped as
  // "250" without passing through the default. The form keeps the last whole
  // number; the draft keeps what is on screen, and an empty or out-of-range
  // draft blocks the save with a message under the field.
  const [drafts, setDrafts] = useState<Partial<Record<NumericKey, string>>>({});
  // The office networks as typed, separators and all. Parsing on every
  // keystroke dropped the comma the moment it was typed.
  const [networksText, setNetworksText] = useState<string | null>(null);

  const { data, isLoading, isError, refetch } = useAttendanceSettings();

  // Seed the editable copy once the server data arrives, without clobbering an
  // in-progress edit. Adjusting state during render avoids an extra effect
  // commit — see https://react.dev/learn/you-might-not-need-an-effect.
  if (data && !form) {
    setForm(data);
    setBaseline(data);
  }

  const save = useUpdateAttendanceSettings();
  const { announceSaved, announceError } = useSaveStatus();

  const rangeMessage = (key: NumericKey) =>
    t(
      "attendance.settings_page.range_error",
      "Enter a whole number from :min to :max.",
      { min: NUMERIC_LIMITS[key].min, max: NUMERIC_LIMITS[key].max },
    );

  function numberError(key: NumericKey): string | undefined {
    if (!form) return undefined;
    const draft = drafts[key];
    const value = draft === undefined ? form[key] : Number(draft);
    const { min, max } = NUMERIC_LIMITS[key];
    if (
      draft === "" ||
      !Number.isInteger(value) ||
      Number(value) < min ||
      Number(value) > max
    ) {
      return rangeMessage(key);
    }
    return undefined;
  }

  const hasNumberError = (Object.keys(NUMERIC_LIMITS) as NumericKey[]).some(
    (key) => numberError(key) !== undefined,
  );

  const pendingForm: AttendanceSettings | null =
    form && networksText !== null
      ? { ...form, office_networks: parseNetworks(networksText) }
      : form;

  const isDirty =
    pendingForm !== null &&
    baseline !== null &&
    (JSON.stringify(pendingForm) !== JSON.stringify(baseline) ||
      Object.keys(drafts).length > 0);
  useUnsavedChangesWarning(isDirty);

  function handleSave() {
    if (!pendingForm) return;
    if (hasNumberError) {
      toast.error(
        t(
          "attendance.settings_page.fix_fields",
          "Fix the highlighted fields before saving.",
        ),
      );
      return;
    }
    save.mutate(pendingForm, {
      onSuccess: (updated) => {
        setForm(updated);
        setBaseline(updated);
        setDrafts({});
        setNetworksText(null);
        toast.success(t("attendance.settings_page.saved", "Settings saved"));
        announceSaved(t("attendance.settings_page.saved", "Settings saved"));
      },
      onError: (err) => {
        const message = apiErrorMessage(
          err,
          t("attendance.settings_page.save_failed", "Failed to save settings"),
        );
        toast.error(message);
        announceError(err, message);
      },
    });
  }

  function toggleMethod(method: Method) {
    if (!form) return;
    const enabled = form.enabled_methods.includes(method)
      ? form.enabled_methods.filter((m) => m !== method)
      : [...form.enabled_methods, method];
    if (enabled.length === 0) {
      toast.error(
        t(
          "attendance.settings_page.at_least_one_method",
          "At least one method must be enabled",
        ),
      );
      return;
    }
    setForm({ ...form, enabled_methods: enabled });
  }

  function setField<K extends keyof AttendanceSettings>(
    key: K,
    value: AttendanceSettings[K],
  ) {
    if (!form) return;
    setForm({ ...form, [key]: value });
  }

  function setNumber(key: NumericKey, raw: string) {
    setDrafts((prev) => ({ ...prev, [key]: raw }));
    const n = Number(raw);
    if (raw.trim() !== "" && Number.isInteger(n)) {
      setField(key, n);
    }
  }

  const numberValue = (key: NumericKey) =>
    drafts[key] ?? (form ? String(form[key]) : "");

  /**
   * The wiring every whole-number input shares. `describedBy` is the id of
   * the hint under the field, when it has one; a range message replaces it.
   */
  function numberProps(key: NumericKey, id: string, describedBy?: string) {
    const error = numberError(key);
    return {
      id,
      type: "number" as const,
      inputMode: "numeric" as const,
      min: NUMERIC_LIMITS[key].min,
      max: NUMERIC_LIMITS[key].max,
      value: numberValue(key),
      onChange: (e: ChangeEvent<HTMLInputElement>) =>
        setNumber(key, e.target.value),
      "aria-invalid": error ? true : undefined,
      "aria-describedby": error ? `${id}-error` : describedBy,
    };
  }

  function numberErrorNode(key: NumericKey, id: string) {
    const error = numberError(key);
    if (!error) return null;
    return (
      <p
        id={`${id}-error`}
        role="alert"
        className="mt-1 text-xs text-destructive"
      >
        {error}
      </p>
    );
  }

  /**
   * One whole-number setting: the title and one line of help on the left, the
   * number with its unit on the right, a range message under the row.
   */
  function numberRow(opts: {
    key: NumericKey;
    id: string;
    title: string;
    unit: string;
    help?: string;
    details?: string;
    disabled?: boolean;
  }) {
    const { key, id, title, unit, help, details, disabled } = opts;
    return (
      <SettingRow
        id={id}
        title={title}
        description={help}
        details={details}
        error={numberErrorNode(key, id)}
      >
        <InputWithUnit
          unit={unit}
          {...numberProps(key, id)}
          disabled={disabled}
        />
      </SettingRow>
    );
  }

  const unitMin = t("common.unit_min", "min");
  const effectWrites = t(
    "common.effect_writes_attendance",
    "Writes attendance",
  );
  const effectConsent = t("common.effect_needs_consent", "Needs consent");

  const reconciliationHelp = {
    off: t(
      "attendance.settings_page.reconciliation_off_help",
      "Payroll runs are approved as they are today.",
    ),
    advisory: t(
      "attendance.settings_page.reconciliation_advisory_help",
      "The run page lists attendance still to settle.",
    ),
    required: t(
      "attendance.settings_page.reconciliation_required_help",
      "A run waits until attendance is settled.",
    ),
  };

  return (
    // AttendanceSettingController checks attendance.manage.
    <RoleGate anyPermission={["manageAttendance"]}>
      <div className="space-y-6">
        <PageActions
          actions={
            <Button
              onClick={handleSave}
              disabled={!form || save.isPending || !isDirty}
            >
              {save.isPending ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : (
                <Save className="mr-2 h-4 w-4" />
              )}
              {t("attendance.settings_page.save_changes", "Save changes")}
            </Button>
          }
        />

        <SetupStatusPanel />

        {isError ? (
          <ErrorState onRetry={() => refetch()} />
        ) : isLoading || !form ? (
          <div className="space-y-4">
            {Array.from({ length: 3 }).map((_, i) => (
              <Skeleton key={i} className="h-32" />
            ))}
          </div>
        ) : (
          <div className="grid gap-6 lg:grid-cols-2">
            <SettingsSection
              className="lg:col-span-2"
              icon={<Settings2 className="h-4 w-4" />}
              title={t(
                "attendance.settings_page.enabled_methods",
                "Attendance methods",
              )}
              contentClassName="grid gap-3 space-y-0 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
            >
              {ALL_METHODS.map((m) => (
                <SettingRow
                  key={m.key}
                  id={`method-${m.key}`}
                  title={t(m.labelKey, m.label)}
                  description={t(m.helpKey, m.help)}
                  className="rounded-lg border p-3 hover:bg-muted/50"
                >
                  <Switch
                    checked={form.enabled_methods.includes(m.key)}
                    onCheckedChange={() => toggleMethod(m.key)}
                  />
                </SettingRow>
              ))}
            </SettingsSection>

            <SettingsSection
              className="lg:col-span-2"
              icon={<Clock className="h-4 w-4" />}
              title={t("attendance.settings_page.rules", "Attendance rules")}
              contentClassName="grid gap-6 space-y-0 lg:grid-cols-3"
            >
              {numberRow({
                key: "ot_daily_cap_minutes",
                id: "ot-daily-cap",
                title: t(
                  "attendance.settings_page.ot_daily_cap",
                  "Daily overtime limit",
                ),
                unit: unitMin,
                help: t(
                  "attendance.settings_page.ot_daily_cap_help",
                  "Days above this are flagged on the payroll run.",
                ),
                details: t(
                  "attendance.settings_page.ot_daily_cap_details",
                  "Flagged days go to the payroll run for review. Days over the legal 4 hours and weeks over 12 are flagged too. All overtime worked is still paid.",
                ),
              })}
              <SettingRow
                id="overtime-requires-approval"
                title={t(
                  "attendance.settings_page.overtime_requires_approval",
                  "Overtime needs approval",
                )}
                description={t(
                  "attendance.settings_page.overtime_requires_approval_help",
                  "Employees ask before working overtime.",
                )}
                details={t(
                  "attendance.settings_page.overtime_requires_approval_details",
                  "Overtime worked past what was approved is flagged on the payroll run, and still paid.",
                )}
              >
                <Switch
                  checked={form.overtime_requires_approval}
                  onCheckedChange={(v) =>
                    setField("overtime_requires_approval", v)
                  }
                />
              </SettingRow>
              {numberRow({
                key: "confidence_threshold",
                id: "confidence-threshold",
                title: t(
                  "attendance.settings_page.confidence_threshold",
                  "Biometric match threshold",
                ),
                unit: t("common.unit_percent", "%"),
                help: t(
                  "attendance.settings_page.confidence_threshold_help",
                  "Matches below this score go to review.",
                ),
              })}
            </SettingsSection>

            {/* Lateness deductions: a payroll decision, so only someone
                who may change payroll configuration can switch it. */}
            <SettingsSection
              title={t(
                "attendance.settings_page.lateness_title",
                "Lateness deductions",
              )}
              intro={
                !can.managePayrollConfig
                  ? t(
                      "attendance.settings_page.lateness_payroll_only",
                      "Only someone who manages payroll settings can change lateness deductions.",
                    )
                  : undefined
              }
            >
              <SettingRow
                id="lateness-deduction-enabled"
                title={t(
                  "attendance.settings_page.lateness_enabled",
                  "Allow lateness deductions",
                )}
                description={t(
                  "attendance.settings_page.lateness_help",
                  "Lets payroll deduct approved late or early minutes from pay.",
                )}
                details={t(
                  "attendance.settings_page.lateness_details",
                  "Off by default. Each deduction is approved by someone who processes payroll, and lateness never takes more than a third of a month's basic pay.",
                )}
                badge={t("common.effect_affects_pay", "Affects pay")}
              >
                <Switch
                  checked={form.lateness_deduction_enabled}
                  disabled={!can.managePayrollConfig}
                  onCheckedChange={(v) =>
                    setField("lateness_deduction_enabled", v)
                  }
                />
              </SettingRow>
              <SettingRow
                id="lateness-deduction-basis"
                title={t(
                  "attendance.settings_page.lateness_basis",
                  "Legal basis for deductions",
                )}
                description={t(
                  "attendance.settings_page.lateness_basis_help",
                  "The work rule, agreement or written consent that allows it.",
                )}
                details={t(
                  "attendance.settings_page.lateness_basis_details",
                  "Name the work rules clause, collective agreement or written consent that allows it (Proclamation 1156/2019 Art. 59).",
                )}
                stacked
              >
                <Input
                  id="lateness-deduction-basis"
                  value={form.lateness_deduction_basis ?? ""}
                  disabled={!can.managePayrollConfig}
                  maxLength={500}
                  onChange={(e) =>
                    setField(
                      "lateness_deduction_basis",
                      e.target.value === "" ? null : e.target.value,
                    )
                  }
                />
              </SettingRow>
              <SettingRow
                id="reconciliation-mode"
                title={t(
                  "attendance.settings_page.reconciliation_mode",
                  "Reconcile attendance before payroll",
                )}
                description={reconciliationHelp[form.reconciliation_mode]}
                details={t(
                  "attendance.settings_page.reconciliation_details",
                  "Required means a run cannot be approved until every absence, lateness and missed punch in its period is settled.",
                )}
                stacked
              >
                <RadioGroup
                  variant="segmented"
                  value={form.reconciliation_mode}
                  disabled={!can.managePayrollConfig}
                  onValueChange={(v) =>
                    setField(
                      "reconciliation_mode",
                      v as AttendanceSettings["reconciliation_mode"],
                    )
                  }
                >
                  <RadioGroupItem value="off">
                    {t("attendance.settings_page.reconciliation_off", "Off")}
                  </RadioGroupItem>
                  <RadioGroupItem value="advisory">
                    {t(
                      "attendance.settings_page.reconciliation_advisory",
                      "Advisory",
                    )}
                  </RadioGroupItem>
                  <RadioGroupItem value="required">
                    {t(
                      "attendance.settings_page.reconciliation_required",
                      "Required",
                    )}
                  </RadioGroupItem>
                </RadioGroup>
              </SettingRow>
            </SettingsSection>

            <SettingsSection
              title={t(
                "attendance.settings_page.mobile_checkin",
                "Phone check-in",
              )}
            >
              <SettingRow
                id="require-geofence"
                title={t(
                  "attendance.settings_page.require_geofence",
                  "Require geofence",
                )}
                description={t(
                  "attendance.settings_page.require_geofence_help",
                  "Refuses phone check-ins from outside the branch area.",
                )}
              >
                <Switch
                  checked={form.geofence_required}
                  onCheckedChange={(v) => setField("geofence_required", v)}
                />
              </SettingRow>
              <SettingRow
                id="require-photo"
                title={t(
                  "attendance.settings_page.require_photo",
                  "Selfie with phone check-in",
                )}
                description={t(
                  "attendance.settings_page.require_photo_help",
                  "Takes a selfie with each phone check-in.",
                )}
                badge={effectConsent}
              >
                <Switch
                  checked={form.mobile_photo_required}
                  onCheckedChange={(v) => setField("mobile_photo_required", v)}
                />
              </SettingRow>
              {numberRow({
                key: "photo_retention_days",
                id: "photo-retention-days",
                title: t(
                  "attendance.settings_page.photo_retention_days",
                  "Keep selfies for",
                ),
                unit: t("common.unit_days", "days"),
                help: t(
                  "attendance.settings_page.photo_retention_days_help",
                  "Then deleted; the attendance record stays.",
                ),
                details: t(
                  "attendance.settings_page.photo_retention_days_details",
                  "After this, HR and the department manager are told and have 48 hours to hold a photo for a complaint. Then the photo is deleted and the attendance record is kept.",
                ),
              })}
              {numberRow({
                key: "mobile_accuracy_threshold_meters",
                id: "gps-accuracy-threshold",
                title: t(
                  "attendance.settings_page.gps_accuracy_threshold",
                  "GPS accuracy limit",
                ),
                unit: t("common.unit_m", "m"),
                help: t(
                  "attendance.settings_page.gps_accuracy_help",
                  "Less accurate phone punches go to review.",
                ),
              })}
              <SettingRow
                id="offline-sync"
                title={t(
                  "attendance.settings_page.offline_sync",
                  "Offline check-in",
                )}
                description={t(
                  "attendance.settings_page.offline_sync_help",
                  "Phone punches made without internet are saved and sent later.",
                )}
                details={t(
                  "attendance.settings_page.offline_sync_details",
                  "The phone saves a punch only while this is on. The registered-phone rule still applies.",
                )}
              >
                <Switch
                  checked={form.offline_sync_enabled}
                  onCheckedChange={(v) => setField("offline_sync_enabled", v)}
                />
              </SettingRow>
            </SettingsSection>

            <SettingsSection
              title={t("attendance.settings_page.qr_code", "QR code")}
            >
              {numberRow({
                key: "qr_expiry_minutes",
                id: "default-expiry",
                title: t(
                  "attendance.settings_page.default_expiry",
                  "QR code lifetime",
                ),
                unit: unitMin,
              })}
              <SettingRow
                id="auto-refresh"
                title={t(
                  "attendance.settings_page.auto_refresh_title",
                  "Refresh QR automatically",
                )}
                description={t(
                  "attendance.settings_page.auto_refresh_help",
                  "Makes a new code when the old one expires.",
                )}
              >
                <Switch
                  checked={form.qr_auto_refresh}
                  onCheckedChange={(v) => setField("qr_auto_refresh", v)}
                />
              </SettingRow>
              <SettingRow
                id="single-use-limit"
                title={t(
                  "attendance.settings_page.single_use_limit",
                  "Scans per code",
                )}
                description={t(
                  "attendance.settings_page.single_use_limit_help",
                  "How many times one code can be scanned. 0 means no limit.",
                )}
                error={numberErrorNode(
                  "qr_single_use_limit",
                  "single-use-limit",
                )}
              >
                <Input
                  {...numberProps("qr_single_use_limit", "single-use-limit")}
                  className="w-32"
                />
              </SettingRow>
            </SettingsSection>

            <SettingsSection
              title={t("attendance.settings_page.kiosk", "Kiosk")}
            >
              <SettingRow
                id="require-pin"
                title={t(
                  "attendance.settings_page.require_pin",
                  "Require kiosk PIN",
                )}
                description={t(
                  "attendance.settings_page.require_pin_help",
                  "Employees type a PIN after their code.",
                )}
                details={t(
                  "attendance.settings_page.require_pin_where",
                  "Set each employee's PIN on their profile, under Attendance.",
                )}
              >
                <Switch
                  checked={form.kiosk_pin_required}
                  onCheckedChange={(v) => setField("kiosk_pin_required", v)}
                />
              </SettingRow>
              {numberRow({
                key: "kiosk_auto_reset_seconds",
                id: "auto-reset-delay",
                title: t(
                  "attendance.settings_page.auto_reset_delay",
                  "Show result for",
                ),
                unit: t("common.unit_s", "s"),
                help: t(
                  "attendance.settings_page.auto_reset_help",
                  "Then the kiosk clears for the next person.",
                ),
              })}
              <SettingRow
                id="kiosk-sound"
                title={t("attendance.settings_page.kiosk_sound", "Kiosk sound")}
                description={t(
                  "attendance.settings_page.kiosk_sound_help",
                  "Tells a person who turned away whether the punch worked.",
                )}
                details={t(
                  "attendance.settings_page.kiosk_sound_details",
                  "Spoken words follow the kiosk's language; a tablet with no voice for that language plays tones instead.",
                )}
                stacked
              >
                <Select
                  value={form.kiosk_sound}
                  onValueChange={(v) =>
                    setField(
                      "kiosk_sound",
                      v as AttendanceSettings["kiosk_sound"],
                    )
                  }
                >
                  {/* SettingRow clones the ids onto the Select root, which
                      renders no element; the trigger is the control. */}
                  <SelectTrigger
                    className="w-44"
                    aria-labelledby="kiosk-sound-title"
                    aria-describedby="kiosk-sound-desc"
                  >
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="off">
                      {t("attendance.settings_page.kiosk_sound_off", "Off")}
                    </SelectItem>
                    <SelectItem value="tones">
                      {t("attendance.settings_page.kiosk_sound_tones", "Tones")}
                    </SelectItem>
                    <SelectItem value="voice">
                      {t(
                        "attendance.settings_page.kiosk_sound_voice",
                        "Spoken words",
                      )}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </SettingRow>
            </SettingsSection>

            <SettingsSection
              className="lg:col-span-2"
              title={t("attendance.settings_page.assistant", "Punch assistant")}
              intro={t(
                "attendance.settings_page.assistant_intro",
                "Helps staff punch on time. Only reminders are on by default.",
              )}
              contentClassName="grid gap-6 space-y-0 lg:grid-cols-3"
            >
              <div className="space-y-3">
                <SettingRow
                  id="assistant-reminders"
                  title={t(
                    "attendance.settings_page.reminders",
                    "Shift reminders",
                  )}
                  description={t(
                    "attendance.settings_page.reminders_help",
                    "Notifies staff before the shift, when late, and at the end.",
                  )}
                  details={t(
                    "attendance.settings_page.reminders_details",
                    "On employees' phones: before the shift, when the grace period ends without a check-in, and at the shift's end. Each employee can turn them off.",
                  )}
                >
                  <Switch
                    checked={form.reminders_enabled}
                    onCheckedChange={(v) => setField("reminders_enabled", v)}
                  />
                </SettingRow>
                {numberRow({
                  key: "reminder_lead_minutes",
                  id: "reminder-lead",
                  title: t(
                    "attendance.settings_page.reminder_lead",
                    "Remind before the shift",
                  ),
                  unit: unitMin,
                  disabled: !form.reminders_enabled,
                })}
              </div>

              <div className="space-y-3">
                <SettingRow
                  id="assistant-auto-checkout"
                  title={t(
                    "attendance.settings_page.auto_checkout",
                    "Auto check-out",
                  )}
                  description={t(
                    "attendance.settings_page.auto_checkout_help",
                    "Closes open check-ins at shift end, flagged for review.",
                  )}
                  details={t(
                    "attendance.settings_page.auto_checkout_details",
                    "The check-out is set at the shift's scheduled end. The employee is told and can file a correction.",
                  )}
                  badge={effectWrites}
                >
                  <Switch
                    checked={form.auto_checkout_enabled}
                    onCheckedChange={(v) =>
                      setField("auto_checkout_enabled", v)
                    }
                  />
                </SettingRow>
                {numberRow({
                  key: "auto_checkout_after_minutes",
                  id: "auto-checkout-after",
                  title: t(
                    "attendance.settings_page.auto_checkout_after",
                    "Wait after shift end",
                  ),
                  unit: unitMin,
                  disabled: !form.auto_checkout_enabled,
                })}
              </div>

              <SettingRow
                id="assistant-auto-checkin"
                title={t(
                  "attendance.settings_page.auto_checkin",
                  "Check-in on arrival",
                )}
                description={t(
                  "attendance.settings_page.auto_checkin_help",
                  "Checks in opted-in staff who open ETHR at their branch.",
                )}
                details={t(
                  "attendance.settings_page.auto_checkin_details",
                  "Up to an hour before the shift. Needs branch coordinates; never used while a selfie is required.",
                )}
                badge={effectWrites}
              >
                <Switch
                  checked={form.geofence_auto_checkin_enabled}
                  onCheckedChange={(v) =>
                    setField("geofence_auto_checkin_enabled", v)
                  }
                />
              </SettingRow>
            </SettingsSection>

            <SettingsSection
              className="lg:col-span-2"
              icon={<ShieldCheck className="h-4 w-4" />}
              title={t(
                "attendance.settings_page.protection",
                "Cheating protection",
              )}
              intro={t(
                "attendance.settings_page.protection_intro",
                "Each punch gets a risk score. High scores go to Integrity.",
              )}
              details={t(
                "attendance.settings_page.protection_details",
                "Signals include a shared phone, a simulated location or a reused selfie, each with its reason. A score never refuses a punch: those at or above the threshold go to Integrity for a person to decide. Only the registered phone rule can refuse one.",
              )}
            >
              <SettingRow
                id="device-binding"
                title={t(
                  "attendance.settings_page.device_binding",
                  "Registered phone",
                )}
                description={t(
                  "attendance.settings_page.device_binding_help",
                  "Only each person's own phone can punch.",
                )}
                details={t(
                  "attendance.settings_page.device_binding_details",
                  "Each person registers the phone they punch with, at work, and confirms each punch with its fingerprint, face or PIN. A friend's phone, a second phone or another browser cannot punch for them. Start with Check and review while staff register, then choose Only the registered phone.",
                )}
                stacked
              >
                <Select
                  value={form.device_binding ?? "off"}
                  onValueChange={(v) =>
                    setField(
                      "device_binding",
                      v as AttendanceSettings["device_binding"],
                    )
                  }
                >
                  <SelectTrigger
                    className="w-full sm:w-80"
                    aria-labelledby="device-binding-title"
                    aria-describedby="device-binding-desc"
                  >
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="off">
                      {t("device_binding.off", "Off")}
                    </SelectItem>
                    <SelectItem value="flag">
                      {t("device_binding.flag", "Check and review")}
                    </SelectItem>
                    <SelectItem value="enforce">
                      {t("device_binding.enforce", "Only the registered phone")}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </SettingRow>
              <SettingRow
                id="office-networks"
                title={t(
                  "attendance.settings_page.office_networks",
                  "Office networks",
                )}
                description={t(
                  "attendance.settings_page.office_networks_help",
                  "Fixed office internet addresses for computer punches. Leave empty if none.",
                )}
                details={t(
                  "attendance.settings_page.office_networks_details",
                  "A web punch from these addresses needs no phone and is marked for review.",
                )}
                stacked
              >
                <Input
                  id="office-networks"
                  value={
                    networksText ?? (form.office_networks ?? []).join(", ")
                  }
                  placeholder="196.188.10.0/24"
                  onChange={(e) => setNetworksText(e.target.value)}
                  onBlur={() => {
                    if (networksText === null) return;
                    setField("office_networks", parseNetworks(networksText));
                    setNetworksText(null);
                  }}
                />
              </SettingRow>
              <SettingRow
                id="risk-threshold"
                title={t(
                  "attendance.settings_page.risk_threshold",
                  "Review from risk score",
                )}
                description={t(
                  "attendance.settings_page.risk_threshold_help",
                  "Lower sends more punches to review. 40 is recommended.",
                )}
                details={t(
                  "attendance.settings_page.risk_threshold_details",
                  "A branch can set its own.",
                )}
                error={numberErrorNode(
                  "risk_review_threshold",
                  "risk-threshold",
                )}
              >
                <Input
                  {...numberProps("risk_review_threshold", "risk-threshold")}
                  className="w-32"
                />
              </SettingRow>
              <SettingRow
                id="liveness-check"
                title={t(
                  "attendance.settings_page.liveness_check",
                  "Live face check",
                )}
                description={t(
                  "attendance.settings_page.liveness_check_help",
                  "Asks for a blink or head turn before the selfie.",
                )}
                details={t(
                  "attendance.settings_page.liveness_check_details",
                  "This catches a photo of a photo. It runs on the phone; no face data leaves it. A failed check is sent for review, never refused.",
                )}
              >
                <Switch
                  checked={form.liveness_check}
                  onCheckedChange={(v) => setField("liveness_check", v)}
                />
              </SettingRow>
              <SettingRow
                id="adaptive-selfie"
                title={t(
                  "attendance.settings_page.adaptive_selfie",
                  "Adaptive selfies",
                )}
                description={t(
                  "attendance.settings_page.adaptive_selfie_help",
                  "Asks for selfies less often from staff with a clean record.",
                )}
                details={t(
                  "attendance.settings_page.adaptive_selfie_details",
                  "Applies only when selfies are not required for everyone. After 90 clean days, about one day in five; everyone else, every time. Needs the photo consent of each employee.",
                )}
                badge={effectConsent}
              >
                <Switch
                  checked={form.adaptive_selfie}
                  onCheckedChange={(v) => setField("adaptive_selfie", v)}
                />
              </SettingRow>
            </SettingsSection>

            <SettingsSection
              className="lg:col-span-2"
              icon={<BellRing className="h-4 w-4" />}
              title={t("attendance.settings_page.presence", "Presence checks")}
            >
              <SettingRow
                id="presence-checks"
                title={t(
                  "attendance.settings_page.presence_per_shift",
                  "Checks during a shift",
                )}
                description={t(
                  "attendance.settings_page.presence_help",
                  "Asks checked-in staff, at random, to confirm they are at work.",
                )}
                details={t(
                  "attendance.settings_page.presence_details",
                  "During a shift, people who are checked in at a workplace get a notification at an unpredictable time. They have 15 minutes to answer. It is never sent around the middle of the shift, and only to people who allowed notifications. One missed check is not a finding; a pattern of them, or an answer from outside the workplace, goes to Integrity for a person to decide.",
                )}
                warning={t(
                  "attendance.settings_page.presence_inform",
                  "Tell your staff in writing before you switch this on.",
                )}
                stacked
              >
                <Select
                  value={String(form.presence_checks_per_shift ?? 0)}
                  onValueChange={(v) =>
                    setField("presence_checks_per_shift", Number(v))
                  }
                >
                  <SelectTrigger
                    className="w-full sm:w-80"
                    aria-labelledby="presence-checks-title"
                    aria-describedby="presence-checks-desc"
                  >
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="0">
                      {t("attendance.presence_count.0", "Off")}
                    </SelectItem>
                    <SelectItem value="1">
                      {t("attendance.presence_count.1", "Up to 1 a shift")}
                    </SelectItem>
                    <SelectItem value="2">
                      {t("attendance.presence_count.2", "Up to 2 a shift")}
                    </SelectItem>
                    <SelectItem value="3">
                      {t("attendance.presence_count.3", "Up to 3 a shift")}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </SettingRow>
            </SettingsSection>

            <WorkSitesCard />
            <BranchRulesCard />
            <DeviceSilenceSetting />
          </div>
        )}
      </div>
    </RoleGate>
  );
}
