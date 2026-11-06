"use client";

import { useState, type ChangeEvent } from "react";
import { Settings2, Loader2, Save, Clock, ShieldCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
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
import { useT } from "@/lib/i18n/useT";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { toast } from "sonner";
import { SettingRow } from "@/components/patterns/SettingRow";
import { apiErrorMessage } from "@/lib/api/error-message";
import { ErrorState } from "@/components/shared/error-state";
import { BranchRulesCard } from "@/features/attendance/components/branch-rules-card";
import { WorkSitesCard } from "@/features/attendance/components/work-sites-card";
import { SetupStatusPanel } from "@/features/attendance/components/setup-status-panel";

type Method = AttendanceSettings["enabled_methods"][number];

const ALL_METHODS: {
  key: Method;
  labelKey: string;
  label: string;
  descriptionKey: string;
  description: string;
}[] = [
  {
    key: "biometric",
    labelKey: "attendance.settings_page.method_biometric_label",
    label: "Biometric device",
    descriptionKey: "attendance.settings_page.method_biometric_desc",
    description: "Fingerprint or face devices at the door",
  },
  {
    key: "mobile",
    labelKey: "attendance.settings_page.method_mobile_label",
    label: "Phone check-in",
    descriptionKey: "attendance.settings_page.method_mobile_desc",
    description: "Location and a selfie from the employee's phone",
  },
  {
    key: "qr",
    labelKey: "attendance.settings_page.method_qr_label",
    label: "QR code",
    descriptionKey: "attendance.settings_page.method_qr_desc",
    description: "Scan QR at branch entrance",
  },
  {
    key: "kiosk",
    labelKey: "attendance.settings_page.method_kiosk_label",
    label: "Kiosk",
    descriptionKey: "attendance.settings_page.method_kiosk_desc",
    description: "A shared tablet; employees type their code",
  },
  {
    key: "web",
    labelKey: "attendance.settings_page.method_web_label",
    label: "Computer",
    descriptionKey: "attendance.settings_page.method_web_desc",
    description: "Check in from the dashboard on a computer",
  },
  {
    key: "manual",
    labelKey: "attendance.settings_page.method_manual_label",
    label: "Manual entry",
    descriptionKey: "attendance.settings_page.method_manual_desc",
    description: "HR records the punch by hand",
  },
  {
    key: "csv",
    labelKey: "attendance.settings_page.method_csv_label",
    label: "Spreadsheet import",
    descriptionKey: "attendance.settings_page.method_csv_desc",
    description: "Bulk import from spreadsheet",
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
      },
      onError: (err) =>
        toast.error(
          apiErrorMessage(
            err,
            t(
              "attendance.settings_page.save_failed",
              "Failed to save settings",
            ),
          ),
        ),
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
            {/* Enabled Methods */}
            <Card className="lg:col-span-2">
              <CardHeader>
                <CardTitle className="text-base flex items-center gap-2">
                  <Settings2 className="h-4 w-4" />{" "}
                  {t(
                    "attendance.settings_page.enabled_methods",
                    "Attendance methods",
                  )}
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                  {ALL_METHODS.map((m) => {
                    const enabled = form.enabled_methods.includes(m.key);
                    const switchId = `method-${m.key}`;
                    return (
                      <div
                        key={m.key}
                        className="flex items-start gap-3 rounded-lg border p-3 hover:bg-muted/50"
                      >
                        <Switch
                          id={switchId}
                          checked={enabled}
                          onCheckedChange={() => toggleMethod(m.key)}
                          aria-describedby={`${switchId}-desc`}
                        />
                        <div>
                          <Label
                            htmlFor={switchId}
                            className="cursor-pointer text-sm font-medium"
                          >
                            {t(m.labelKey, m.label)}
                          </Label>
                          <p
                            id={`${switchId}-desc`}
                            className="mt-1 text-xs text-muted-foreground"
                          >
                            {t(m.descriptionKey, m.description)}
                          </p>
                        </div>
                      </div>
                    );
                  })}
                </div>
              </CardContent>
            </Card>

            {/* Attendance Rules */}
            <Card className="lg:col-span-2">
              <CardHeader>
                <CardTitle className="text-base flex items-center gap-2">
                  <Clock className="h-4 w-4" />{" "}
                  {t("attendance.settings_page.rules", "Attendance rules")}
                </CardTitle>
              </CardHeader>
              <CardContent className="grid gap-4 lg:grid-cols-3">
                <div>
                  <Label htmlFor="ot-daily-cap" className="text-sm">
                    {t(
                      "attendance.settings_page.ot_daily_cap",
                      "Daily overtime limit (minutes)",
                    )}
                  </Label>
                  <Input
                    {...numberProps(
                      "ot_daily_cap_minutes",
                      "ot-daily-cap",
                      "ot-daily-cap-desc",
                    )}
                    className="mt-1 w-32"
                  />
                  {numberErrorNode("ot_daily_cap_minutes", "ot-daily-cap")}
                  <p
                    id="ot-daily-cap-desc"
                    className="mt-1 text-xs text-muted-foreground"
                  >
                    {t(
                      "attendance.settings_page.ot_daily_cap_desc",
                      "Days with more overtime are flagged on the payroll run for review, as are days over the legal 4 hours and weeks over 12. All overtime worked is still paid.",
                    )}
                  </p>
                </div>
                <SettingRow
                  id="overtime-requires-approval"
                  title={t(
                    "attendance.settings_page.overtime_requires_approval",
                    "Overtime needs approval in advance",
                  )}
                  description={t(
                    "attendance.settings_page.overtime_requires_approval_desc",
                    "Employees ask before working overtime. Overtime worked past what was approved is flagged on the payroll run, and still paid.",
                  )}
                >
                  <Switch
                    checked={form.overtime_requires_approval}
                    onCheckedChange={(v) =>
                      setField("overtime_requires_approval", v)
                    }
                  />
                </SettingRow>
                <div>
                  <Label htmlFor="confidence-threshold" className="text-sm">
                    {t(
                      "attendance.settings_page.confidence_threshold",
                      "Confidence threshold (%)",
                    )}
                  </Label>
                  <Input
                    {...numberProps(
                      "confidence_threshold",
                      "confidence-threshold",
                      "confidence-threshold-desc",
                    )}
                    className="mt-1 w-32"
                  />
                  {numberErrorNode(
                    "confidence_threshold",
                    "confidence-threshold",
                  )}
                  <p
                    id="confidence-threshold-desc"
                    className="mt-1 text-xs text-muted-foreground"
                  >
                    {t(
                      "attendance.settings_page.confidence_threshold_desc",
                      "Biometric matches below this score are sent for review.",
                    )}
                  </p>
                </div>
              </CardContent>
            </Card>

            {/* Lateness deductions: a payroll decision, so only someone
                who may change payroll configuration can switch it. */}
            <Card>
              <CardHeader>
                <CardTitle className="text-base">
                  {t(
                    "attendance.settings_page.lateness_title",
                    "Lateness deductions",
                  )}
                </CardTitle>
                {!can.managePayrollConfig && (
                  <p className="text-sm text-muted-foreground">
                    {t(
                      "attendance.settings_page.lateness_payroll_only",
                      "Only someone who manages payroll settings can change lateness deductions.",
                    )}
                  </p>
                )}
              </CardHeader>
              <CardContent className="space-y-4">
                <SettingRow
                  id="lateness-deduction-enabled"
                  title={t(
                    "attendance.settings_page.lateness_enabled",
                    "Allow lateness deductions",
                  )}
                  description={t(
                    "attendance.settings_page.lateness_desc",
                    "Off by default. When on, payroll can deduct approved late or early minutes from pay. Each deduction is approved by someone who processes payroll, and lateness never takes more than a third of a month's basic pay.",
                  )}
                >
                  <Switch
                    checked={form.lateness_deduction_enabled}
                    disabled={!can.managePayrollConfig}
                    onCheckedChange={(v) =>
                      setField("lateness_deduction_enabled", v)
                    }
                  />
                </SettingRow>
                <div>
                  <Label htmlFor="lateness-deduction-basis" className="text-sm">
                    {t(
                      "attendance.settings_page.lateness_basis",
                      "Basis for deducting",
                    )}
                  </Label>
                  <Input
                    id="lateness-deduction-basis"
                    value={form.lateness_deduction_basis ?? ""}
                    disabled={!can.managePayrollConfig}
                    maxLength={500}
                    aria-describedby="lateness-deduction-basis-desc"
                    onChange={(e) =>
                      setField(
                        "lateness_deduction_basis",
                        e.target.value === "" ? null : e.target.value,
                      )
                    }
                    className="mt-1"
                  />
                  <p
                    id="lateness-deduction-basis-desc"
                    className="mt-1 text-xs text-muted-foreground"
                  >
                    {t(
                      "attendance.settings_page.lateness_basis_hint",
                      "Name the work rules clause, collective agreement or written consent that allows it (Proclamation 1156/2019 Art. 59).",
                    )}
                  </p>
                </div>
                <SettingRow
                  id="reconciliation-mode"
                  title={t(
                    "attendance.settings_page.reconciliation_mode",
                    "Reconcile attendance before payroll",
                  )}
                  description={t(
                    "attendance.settings_page.reconciliation_mode_desc",
                    "Off: payroll runs are approved as they are today. Advisory: the run page lists attendance still to settle. Required: a run cannot be approved until every absence, lateness and missed punch in its period is settled.",
                  )}
                >
                  <Select
                    value={form.reconciliation_mode}
                    disabled={!can.managePayrollConfig}
                    onValueChange={(v) =>
                      setField(
                        "reconciliation_mode",
                        v as AttendanceSettings["reconciliation_mode"],
                      )
                    }
                  >
                    {/* SettingRow clones the ids onto the Select root, which
                        renders no element; the trigger is the control. */}
                    <SelectTrigger
                      className="w-44"
                      aria-labelledby="reconciliation-mode-title"
                      aria-describedby="reconciliation-mode-desc"
                    >
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="off">
                        {t(
                          "attendance.settings_page.reconciliation_off",
                          "Off",
                        )}
                      </SelectItem>
                      <SelectItem value="advisory">
                        {t(
                          "attendance.settings_page.reconciliation_advisory",
                          "Advisory",
                        )}
                      </SelectItem>
                      <SelectItem value="required">
                        {t(
                          "attendance.settings_page.reconciliation_required",
                          "Required",
                        )}
                      </SelectItem>
                    </SelectContent>
                  </Select>
                </SettingRow>
              </CardContent>
            </Card>

            {/* Mobile Settings */}
            <Card>
              <CardHeader>
                <CardTitle className="text-base">
                  {t(
                    "attendance.settings_page.mobile_checkin",
                    "Phone check-in",
                  )}
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <SettingRow
                  id="require-geofence"
                  title={t(
                    "attendance.settings_page.require_geofence",
                    "Require geofence",
                  )}
                  description={t(
                    "attendance.settings_page.require_geofence_desc",
                    "A phone check-in from outside the branch area is refused.",
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
                    "attendance.settings_page.require_photo_desc",
                    "Each phone check-in takes a selfie.",
                  )}
                >
                  <Switch
                    checked={form.mobile_photo_required}
                    onCheckedChange={(v) =>
                      setField("mobile_photo_required", v)
                    }
                  />
                </SettingRow>
                <div>
                  <Label htmlFor="photo-retention-days" className="text-sm">
                    {t(
                      "attendance.settings_page.photo_retention_days",
                      "Keep attendance selfies for (days)",
                    )}
                  </Label>
                  <Input
                    {...numberProps(
                      "photo_retention_days",
                      "photo-retention-days",
                      "photo-retention-days-desc",
                    )}
                    className="mt-1 w-32"
                  />
                  {numberErrorNode(
                    "photo_retention_days",
                    "photo-retention-days",
                  )}
                  <p
                    id="photo-retention-days-desc"
                    className="mt-1 text-xs text-muted-foreground"
                  >
                    {t(
                      "attendance.settings_page.photo_retention_days_desc",
                      "After this, HR and the department manager are told and have 48 hours to hold a photo for a complaint. Then the photo is deleted and the attendance record is kept.",
                    )}
                  </p>
                </div>
                <div>
                  <Label htmlFor="gps-accuracy-threshold" className="text-sm">
                    {t(
                      "attendance.settings_page.gps_accuracy_threshold",
                      "Weakest GPS accepted (metres)",
                    )}
                  </Label>
                  <Input
                    {...numberProps(
                      "mobile_accuracy_threshold_meters",
                      "gps-accuracy-threshold",
                      "gps-accuracy-threshold-desc",
                    )}
                    className="mt-1 w-32"
                  />
                  {numberErrorNode(
                    "mobile_accuracy_threshold_meters",
                    "gps-accuracy-threshold",
                  )}
                  <p
                    id="gps-accuracy-threshold-desc"
                    className="mt-1 text-xs text-muted-foreground"
                  >
                    {t(
                      "attendance.settings_page.gps_accuracy_desc",
                      "A phone punch with a less accurate location is sent for review.",
                    )}
                  </p>
                </div>
                <SettingRow
                  id="offline-sync"
                  title={t(
                    "attendance.settings_page.offline_sync",
                    "Offline sync",
                  )}
                  description={t(
                    "attendance.settings_page.offline_sync_desc",
                    "Allow check-in when offline",
                  )}
                >
                  <Switch
                    checked={form.offline_sync_enabled}
                    onCheckedChange={(v) => setField("offline_sync_enabled", v)}
                  />
                </SettingRow>
              </CardContent>
            </Card>

            {/* QR Settings */}
            <Card>
              <CardHeader>
                <CardTitle className="text-base">
                  {t("attendance.settings_page.qr_code", "QR code")}
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div>
                  <Label htmlFor="default-expiry" className="text-sm">
                    {t(
                      "attendance.settings_page.default_expiry",
                      "Code expires after (minutes)",
                    )}
                  </Label>
                  <Input
                    {...numberProps("qr_expiry_minutes", "default-expiry")}
                    className="mt-1 w-32"
                  />
                  {numberErrorNode("qr_expiry_minutes", "default-expiry")}
                </div>
                <SettingRow
                  id="auto-refresh"
                  title={t(
                    "attendance.settings_page.auto_refresh",
                    "Refresh automatically",
                  )}
                  description={t(
                    "attendance.settings_page.auto_refresh_desc",
                    "Make a new code when the old one expires.",
                  )}
                >
                  <Switch
                    checked={form.qr_auto_refresh}
                    onCheckedChange={(v) => setField("qr_auto_refresh", v)}
                  />
                </SettingRow>
                <div>
                  <Label htmlFor="single-use-limit" className="text-sm">
                    {t(
                      "attendance.settings_page.single_use_limit",
                      "Scans per code",
                    )}
                  </Label>
                  <Input
                    {...numberProps(
                      "qr_single_use_limit",
                      "single-use-limit",
                      "single-use-limit-desc",
                    )}
                    className="mt-1 w-32"
                  />
                  {numberErrorNode("qr_single_use_limit", "single-use-limit")}
                  <p
                    id="single-use-limit-desc"
                    className="mt-1 text-xs text-muted-foreground"
                  >
                    {t(
                      "attendance.settings_page.single_use_limit_desc",
                      "How many times one code can be scanned. 0 means no limit.",
                    )}
                  </p>
                </div>
              </CardContent>
            </Card>

            {/* Kiosk Settings */}
            <Card>
              <CardHeader>
                <CardTitle className="text-base">
                  {t("attendance.settings_page.kiosk", "Kiosk")}
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <SettingRow
                  id="require-pin"
                  title={t(
                    "attendance.settings_page.require_pin",
                    "Require employee PIN",
                  )}
                  description={t(
                    "attendance.settings_page.require_pin_desc",
                    "Employees type their PIN after their code. Set each employee's PIN on their profile, under Attendance.",
                  )}
                >
                  <Switch
                    checked={form.kiosk_pin_required}
                    onCheckedChange={(v) => setField("kiosk_pin_required", v)}
                  />
                </SettingRow>
                <div>
                  <Label htmlFor="auto-reset-delay" className="text-sm">
                    {t(
                      "attendance.settings_page.auto_reset_delay",
                      "Auto-reset delay (seconds)",
                    )}
                  </Label>
                  <Input
                    {...numberProps(
                      "kiosk_auto_reset_seconds",
                      "auto-reset-delay",
                      "auto-reset-delay-desc",
                    )}
                    className="mt-1 w-32"
                  />
                  {numberErrorNode(
                    "kiosk_auto_reset_seconds",
                    "auto-reset-delay",
                  )}
                  <p
                    id="auto-reset-delay-desc"
                    className="mt-1 text-xs text-muted-foreground"
                  >
                    {t(
                      "attendance.settings_page.auto_reset_desc",
                      "How long the result stays on screen before the kiosk clears for the next person.",
                    )}
                  </p>
                </div>
                {/* Stacked like the delay above: this card is a narrow
                    column, and a row squeezed the description to a word a
                    line. */}
                <div>
                  <Label htmlFor="kiosk-sound" className="text-sm">
                    {t("attendance.settings_page.kiosk_sound", "Kiosk sound")}
                  </Label>
                  <Select
                    value={form.kiosk_sound}
                    onValueChange={(v) =>
                      setField(
                        "kiosk_sound",
                        v as AttendanceSettings["kiosk_sound"],
                      )
                    }
                  >
                    <SelectTrigger
                      id="kiosk-sound"
                      className="mt-1 w-44"
                      aria-describedby="kiosk-sound-desc"
                    >
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="off">
                        {t("attendance.settings_page.kiosk_sound_off", "Off")}
                      </SelectItem>
                      <SelectItem value="tones">
                        {t(
                          "attendance.settings_page.kiosk_sound_tones",
                          "Tones",
                        )}
                      </SelectItem>
                      <SelectItem value="voice">
                        {t(
                          "attendance.settings_page.kiosk_sound_voice",
                          "Spoken words",
                        )}
                      </SelectItem>
                    </SelectContent>
                  </Select>
                  <p
                    id="kiosk-sound-desc"
                    className="mt-1 text-xs text-muted-foreground"
                  >
                    {t(
                      "attendance.settings_page.kiosk_sound_desc",
                      "Played after each punch, so a person who has already turned away still knows whether it worked. Spoken words follow the kiosk's language; a tablet with no voice for that language plays tones instead.",
                    )}
                  </p>
                </div>
              </CardContent>
            </Card>

            {/* Punch assistant */}
            <Card className="lg:col-span-2">
              <CardHeader>
                <CardTitle className="text-base">
                  {t("attendance.settings_page.assistant", "Punch assistant")}
                </CardTitle>
                <p className="text-sm text-muted-foreground">
                  {t(
                    "attendance.settings_page.assistant_desc",
                    "Help employees punch on time. Reminders only notify; the other two write attendance, so they are off until you turn them on.",
                  )}
                </p>
              </CardHeader>
              <CardContent className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-3">
                  <SettingRow
                    id="assistant-reminders"
                    title={t(
                      "attendance.settings_page.reminders",
                      "Shift reminders",
                    )}
                    description={t(
                      "attendance.settings_page.reminders_desc",
                      "On employees' phones: before the shift, when the grace period ends without a check-in, and at the shift's end. Each employee can turn them off.",
                    )}
                  >
                    <Switch
                      checked={form.reminders_enabled}
                      onCheckedChange={(v) => setField("reminders_enabled", v)}
                    />
                  </SettingRow>
                  <div>
                    <Label htmlFor="reminder-lead" className="text-sm">
                      {t(
                        "attendance.settings_page.reminder_lead",
                        "Minutes before the shift",
                      )}
                    </Label>
                    <Input
                      {...numberProps("reminder_lead_minutes", "reminder-lead")}
                      disabled={!form.reminders_enabled}
                      className="mt-1 w-32"
                    />
                    {numberErrorNode("reminder_lead_minutes", "reminder-lead")}
                  </div>
                </div>

                <div className="space-y-3">
                  <SettingRow
                    id="assistant-auto-checkout"
                    title={t(
                      "attendance.settings_page.auto_checkout",
                      "Auto check-out",
                    )}
                    description={t(
                      "attendance.settings_page.auto_checkout_desc",
                      "Closes a check-in left open, at the shift's scheduled end, flagged for review. The employee is told and can file a correction.",
                    )}
                  >
                    <Switch
                      checked={form.auto_checkout_enabled}
                      onCheckedChange={(v) =>
                        setField("auto_checkout_enabled", v)
                      }
                    />
                  </SettingRow>
                  <div>
                    <Label htmlFor="auto-checkout-after" className="text-sm">
                      {t(
                        "attendance.settings_page.auto_checkout_after",
                        "Wait after the shift ends (minutes)",
                      )}
                    </Label>
                    <Input
                      {...numberProps(
                        "auto_checkout_after_minutes",
                        "auto-checkout-after",
                      )}
                      disabled={!form.auto_checkout_enabled}
                      className="mt-1 w-32"
                    />
                    {numberErrorNode(
                      "auto_checkout_after_minutes",
                      "auto-checkout-after",
                    )}
                  </div>
                </div>

                <SettingRow
                  id="assistant-auto-checkin"
                  title={t(
                    "attendance.settings_page.auto_checkin",
                    "Automatic check-in on arrival",
                  )}
                  description={t(
                    "attendance.settings_page.auto_checkin_desc",
                    "Employees who opt in are checked in when they open ETHR inside their branch area, up to an hour before the shift. Needs branch coordinates; never used while a selfie is required.",
                  )}
                >
                  <Switch
                    checked={form.geofence_auto_checkin_enabled}
                    onCheckedChange={(v) =>
                      setField("geofence_auto_checkin_enabled", v)
                    }
                  />
                </SettingRow>
              </CardContent>
            </Card>

            <Card className="lg:col-span-2">
              <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                  <ShieldCheck className="h-4 w-4" />
                  {t(
                    "attendance.settings_page.protection",
                    "Cheating protection",
                  )}
                </CardTitle>
                <p className="text-sm text-muted-foreground">
                  {t(
                    "attendance.settings_page.protection_desc",
                    "Every punch gets a risk score from signals such as a shared phone, a simulated location or a reused selfie, each with its reason. A score never refuses a punch: those at or above the threshold go to Integrity for a person to decide. Only the registered phone rule below can refuse one.",
                  )}
                </p>
              </CardHeader>
              <CardContent className="space-y-4">
                <div>
                  <Label htmlFor="device-binding" className="text-sm">
                    {t(
                      "attendance.settings_page.device_binding",
                      "Registered phone",
                    )}
                  </Label>
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
                      id="device-binding"
                      className="mt-1 w-full sm:w-80"
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
                        {t(
                          "device_binding.enforce",
                          "Only the registered phone",
                        )}
                      </SelectItem>
                    </SelectContent>
                  </Select>
                  <p
                    id="device-binding-desc"
                    className="mt-1 text-xs text-muted-foreground"
                  >
                    {t(
                      "attendance.settings_page.device_binding_desc",
                      "Each person registers the phone they punch with, at work, and confirms each punch with its fingerprint, face or PIN. A friend's phone, a second phone or another browser cannot punch for them. Start with Check and review while staff register, then choose Only the registered phone.",
                    )}
                  </p>
                </div>
                <div>
                  <Label htmlFor="office-networks" className="text-sm">
                    {t(
                      "attendance.settings_page.office_networks",
                      "Office networks for computer punches",
                    )}
                  </Label>
                  <Input
                    id="office-networks"
                    value={
                      networksText ?? (form.office_networks ?? []).join(", ")
                    }
                    placeholder="196.188.10.0/24"
                    aria-describedby="office-networks-desc"
                    onChange={(e) => setNetworksText(e.target.value)}
                    onBlur={() => {
                      if (networksText === null) return;
                      setField("office_networks", parseNetworks(networksText));
                      setNetworksText(null);
                    }}
                    className="mt-1"
                  />
                  <p
                    id="office-networks-desc"
                    className="mt-1 text-xs text-muted-foreground"
                  >
                    {t(
                      "attendance.settings_page.office_networks_desc",
                      "Only if your office has its own fixed internet address. A web punch from these addresses needs no phone and is marked for review. Leave empty otherwise.",
                    )}
                  </p>
                </div>
                <div>
                  <Label htmlFor="risk-threshold" className="text-sm">
                    {t(
                      "attendance.settings_page.risk_threshold",
                      "Send to review from a risk score of",
                    )}
                  </Label>
                  <Input
                    {...numberProps(
                      "risk_review_threshold",
                      "risk-threshold",
                      "risk-threshold-desc",
                    )}
                    className="mt-1 w-32"
                  />
                  {numberErrorNode("risk_review_threshold", "risk-threshold")}
                  <p
                    id="risk-threshold-desc"
                    className="mt-1 text-xs text-muted-foreground"
                  >
                    {t(
                      "attendance.settings_page.risk_threshold_desc",
                      "Lower sends more punches to review. 40 is recommended; a branch can set its own.",
                    )}
                  </p>
                </div>
                <SettingRow
                  id="liveness-check"
                  title={t(
                    "attendance.settings_page.liveness_check",
                    "Live face check before selfies",
                  )}
                  description={t(
                    "attendance.settings_page.liveness_check_desc",
                    "The phone asks for a blink or a head turn before the photo, to catch a photo of a photo. It runs on the phone; no face data leaves it. A failed check is sent for review, never refused.",
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
                    "attendance.settings_page.adaptive_selfie_desc",
                    "When selfies are not required for everyone: ask staff with 90 clean days on about one day in five, and everyone else every time. Needs the photo consent of each employee.",
                  )}
                >
                  <Switch
                    checked={form.adaptive_selfie}
                    onCheckedChange={(v) => setField("adaptive_selfie", v)}
                  />
                </SettingRow>
              </CardContent>
            </Card>

            <WorkSitesCard />
            <BranchRulesCard />
          </div>
        )}
      </div>
    </RoleGate>
  );
}
