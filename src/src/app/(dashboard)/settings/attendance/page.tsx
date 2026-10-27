"use client";

import { useState } from "react";
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
  descriptionKey: string;
}[] = [
  {
    key: "biometric",
    labelKey: "attendance.settings_page.method_biometric_label",
    descriptionKey: "attendance.settings_page.method_biometric_desc",
  },
  {
    key: "mobile",
    labelKey: "attendance.settings_page.method_mobile_label",
    descriptionKey: "attendance.settings_page.method_mobile_desc",
  },
  {
    key: "qr",
    labelKey: "attendance.settings_page.method_qr_label",
    descriptionKey: "attendance.settings_page.method_qr_desc",
  },
  {
    key: "kiosk",
    labelKey: "attendance.settings_page.method_kiosk_label",
    descriptionKey: "attendance.settings_page.method_kiosk_desc",
  },
  {
    key: "web",
    labelKey: "attendance.settings_page.method_web_label",
    descriptionKey: "attendance.settings_page.method_web_desc",
  },
  {
    key: "manual",
    labelKey: "attendance.settings_page.method_manual_label",
    descriptionKey: "attendance.settings_page.method_manual_desc",
  },
  {
    key: "csv",
    labelKey: "attendance.settings_page.method_csv_label",
    descriptionKey: "attendance.settings_page.method_csv_desc",
  },
];

export default function AttendanceSettingsPage() {
  const { t } = useT();
  const { can } = usePermissions();
  const [form, setForm] = useState<AttendanceSettings | null>(null);

  const { data, isLoading, isError, refetch } = useAttendanceSettings();

  // Seed the editable copy once the server data arrives, without clobbering an
  // in-progress edit. Adjusting state during render avoids an extra effect
  // commit — see https://react.dev/learn/you-might-not-need-an-effect.
  if (data && !form) {
    setForm(data);
  }

  const save = useUpdateAttendanceSettings();

  function handleSave() {
    if (!form) return;
    save.mutate(form, {
      onSuccess: (updated) => {
        setForm(updated);
        toast.success(t("attendance.settings_page.saved"));
      },
      onError: (err) =>
        toast.error(
          apiErrorMessage(err, t("attendance.settings_page.save_failed")),
        ),
    });
  }

  function toggleMethod(method: Method) {
    if (!form) return;
    const enabled = form.enabled_methods.includes(method)
      ? form.enabled_methods.filter((m) => m !== method)
      : [...form.enabled_methods, method];
    if (enabled.length === 0) {
      toast.error(t("attendance.settings_page.at_least_one_method"));
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

  return (
    // AttendanceSettingController checks attendance.manage.
    <RoleGate anyPermission={["manageAttendance"]}>
      <div className="space-y-6">
        <PageActions
          actions={
            <Button onClick={handleSave} disabled={!form || save.isPending}>
              {save.isPending ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : (
                <Save className="mr-2 h-4 w-4" />
              )}
              {t("attendance.settings_page.save_changes")}
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
                  {t("attendance.settings_page.enabled_methods")}
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                  {ALL_METHODS.map((m) => {
                    const enabled = form.enabled_methods.includes(m.key);
                    return (
                      <div
                        key={m.key}
                        className="flex items-start gap-3 rounded-lg border p-3 cursor-pointer hover:bg-muted/50"
                        // A click on the Switch fired its own onCheckedChange
                        // *and* bubbled to this handler, toggling twice — so
                        // clicking the switch itself did nothing at all and the
                        // row only responded when you hit the padding around
                        // it. Let the control own its own clicks.
                        onClick={(e) => {
                          if (
                            (e.target as HTMLElement).closest('[role="switch"]')
                          ) {
                            return;
                          }
                          toggleMethod(m.key);
                        }}
                      >
                        <Switch
                          checked={enabled}
                          onCheckedChange={() => toggleMethod(m.key)}
                          aria-label={t(m.labelKey)}
                        />
                        <div>
                          <p className="text-sm font-medium">{t(m.labelKey)}</p>
                          <p className="text-xs text-muted-foreground">
                            {t(m.descriptionKey)}
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
                  {t("attendance.settings_page.rules", "Attendance Rules")}
                </CardTitle>
              </CardHeader>
              <CardContent className="grid gap-4 sm:grid-cols-3">
                <div>
                  <Label htmlFor="ot-daily-cap" className="text-sm">
                    {t(
                      "attendance.settings_page.ot_daily_cap",
                      "Daily overtime limit (minutes)",
                    )}
                  </Label>
                  <Input
                    id="ot-daily-cap"
                    type="number"
                    value={form.ot_daily_cap_minutes}
                    onChange={(e) =>
                      setField(
                        "ot_daily_cap_minutes",
                        parseInt(e.target.value) || 0,
                      )
                    }
                    min={0}
                    max={1440}
                    className="mt-1 w-32"
                  />
                  <p className="mt-1 text-xs text-muted-foreground">
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
                    id="confidence-threshold"
                    type="number"
                    value={form.confidence_threshold}
                    onChange={(e) =>
                      setField(
                        "confidence_threshold",
                        parseInt(e.target.value) || 0,
                      )
                    }
                    min={0}
                    max={100}
                    className="mt-1 w-32"
                  />
                  <p className="mt-1 text-xs text-muted-foreground">
                    {t(
                      "attendance.settings_page.confidence_threshold_desc",
                      "Minimum confidence score for attendance records",
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
                  {t("attendance.settings_page.lateness_title")}
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <SettingRow
                  id="lateness-deduction-enabled"
                  title={t("attendance.settings_page.lateness_enabled")}
                  description={t("attendance.settings_page.lateness_desc")}
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
                    {t("attendance.settings_page.lateness_basis")}
                  </Label>
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
                    className="mt-1"
                  />
                  <p className="mt-1 text-xs text-muted-foreground">
                    {t("attendance.settings_page.lateness_basis_hint")}
                  </p>
                </div>
                <SettingRow
                  id="reconciliation-mode"
                  title={t("attendance.settings_page.reconciliation_mode")}
                  description={t(
                    "attendance.settings_page.reconciliation_mode_desc",
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
                    <SelectTrigger className="w-44">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="off">
                        {t("attendance.settings_page.reconciliation_off")}
                      </SelectItem>
                      <SelectItem value="advisory">
                        {t("attendance.settings_page.reconciliation_advisory")}
                      </SelectItem>
                      <SelectItem value="required">
                        {t("attendance.settings_page.reconciliation_required")}
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
                  {t("attendance.settings_page.mobile_checkin")}
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <SettingRow
                  id="require-geofence"
                  title={t("attendance.settings_page.require_geofence")}
                  description={t(
                    "attendance.settings_page.require_geofence_desc",
                  )}
                >
                  <Switch
                    checked={form.geofence_required}
                    onCheckedChange={(v) => setField("geofence_required", v)}
                  />
                </SettingRow>
                <SettingRow
                  id="require-photo"
                  title={t("attendance.settings_page.require_photo")}
                  description={t("attendance.settings_page.require_photo_desc")}
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
                    id="photo-retention-days"
                    type="number"
                    value={form.photo_retention_days}
                    onChange={(e) =>
                      setField(
                        "photo_retention_days",
                        parseInt(e.target.value) || 7,
                      )
                    }
                    min={1}
                    max={90}
                    className="mt-1 w-32"
                  />
                  <p className="mt-1 text-xs text-muted-foreground">
                    {t(
                      "attendance.settings_page.photo_retention_days_desc",
                      "After this, HR and the department manager are told and have 48 hours to hold a photo for a complaint. Then the photo is deleted and the attendance record is kept.",
                    )}
                  </p>
                </div>
                <div>
                  <Label htmlFor="gps-accuracy-threshold" className="text-sm">
                    {t("attendance.settings_page.gps_accuracy_threshold")}
                  </Label>
                  <Input
                    id="gps-accuracy-threshold"
                    type="number"
                    value={form.mobile_accuracy_threshold_meters}
                    onChange={(e) =>
                      setField(
                        "mobile_accuracy_threshold_meters",
                        parseInt(e.target.value) || 100,
                      )
                    }
                    min={10}
                    max={5000}
                    className="mt-1 w-32"
                  />
                  <p className="mt-1 text-xs text-muted-foreground">
                    {t("attendance.settings_page.gps_accuracy_desc")}
                  </p>
                </div>
                <SettingRow
                  id="offline-sync"
                  title={t("attendance.settings_page.offline_sync")}
                  description={t("attendance.settings_page.offline_sync_desc")}
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
                  {t("attendance.settings_page.qr_code")}
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div>
                  <Label htmlFor="default-expiry" className="text-sm">
                    {t("attendance.settings_page.default_expiry")}
                  </Label>
                  <Input
                    id="default-expiry"
                    type="number"
                    value={form.qr_expiry_minutes}
                    onChange={(e) =>
                      setField(
                        "qr_expiry_minutes",
                        parseInt(e.target.value) || 30,
                      )
                    }
                    min={5}
                    max={480}
                    className="mt-1 w-32"
                  />
                </div>
                <SettingRow
                  id="auto-refresh"
                  title={t("attendance.settings_page.auto_refresh")}
                  description={t("attendance.settings_page.auto_refresh_desc")}
                >
                  <Switch
                    checked={form.qr_auto_refresh}
                    onCheckedChange={(v) => setField("qr_auto_refresh", v)}
                  />
                </SettingRow>
                <div>
                  <Label htmlFor="single-use-limit" className="text-sm">
                    {t("attendance.settings_page.single_use_limit")}
                  </Label>
                  <Input
                    id="single-use-limit"
                    type="number"
                    value={form.qr_single_use_limit}
                    onChange={(e) =>
                      setField(
                        "qr_single_use_limit",
                        parseInt(e.target.value) || 0,
                      )
                    }
                    min={0}
                    max={1000}
                    className="mt-1 w-32"
                  />
                  <p className="mt-1 text-xs text-muted-foreground">
                    {t("attendance.settings_page.single_use_limit_desc")}
                  </p>
                </div>
              </CardContent>
            </Card>

            {/* Kiosk Settings */}
            <Card>
              <CardHeader>
                <CardTitle className="text-base">
                  {t("attendance.settings_page.kiosk")}
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <SettingRow
                  id="require-pin"
                  title={t("attendance.settings_page.require_pin")}
                  description={`${t("attendance.settings_page.require_pin_desc")}. ${t(
                    "attendance.settings_page.require_pin_where",
                    "Set each employee's PIN on their profile, under Attendance.",
                  )}`}
                >
                  <Switch
                    checked={form.kiosk_pin_required}
                    onCheckedChange={(v) => setField("kiosk_pin_required", v)}
                  />
                </SettingRow>
                <div>
                  <Label htmlFor="auto-reset-delay" className="text-sm">
                    {t("attendance.settings_page.auto_reset_delay")}
                  </Label>
                  <Input
                    id="auto-reset-delay"
                    type="number"
                    value={form.kiosk_auto_reset_seconds}
                    onChange={(e) =>
                      setField(
                        "kiosk_auto_reset_seconds",
                        parseInt(e.target.value) || 4,
                      )
                    }
                    min={2}
                    max={30}
                    className="mt-1 w-32"
                  />
                  <p className="mt-1 text-xs text-muted-foreground">
                    {t("attendance.settings_page.auto_reset_desc")}
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
              <CardContent className="grid gap-6 md:grid-cols-3">
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
                      id="reminder-lead"
                      type="number"
                      value={form.reminder_lead_minutes}
                      onChange={(e) =>
                        setField(
                          "reminder_lead_minutes",
                          parseInt(e.target.value) || 0,
                        )
                      }
                      min={0}
                      max={120}
                      disabled={!form.reminders_enabled}
                      className="mt-1 w-32"
                    />
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
                      id="auto-checkout-after"
                      type="number"
                      value={form.auto_checkout_after_minutes}
                      onChange={(e) =>
                        setField(
                          "auto_checkout_after_minutes",
                          parseInt(e.target.value) || 120,
                        )
                      }
                      min={15}
                      max={720}
                      disabled={!form.auto_checkout_enabled}
                      className="mt-1 w-32"
                    />
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
                  <p className="mt-1 text-xs text-muted-foreground">
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
                    value={(form.office_networks ?? []).join(", ")}
                    placeholder="196.188.10.0/24"
                    onChange={(e) =>
                      setField(
                        "office_networks",
                        e.target.value
                          .split(/[\s,]+/)
                          .filter((part) => part !== ""),
                      )
                    }
                    className="mt-1"
                  />
                  <p className="mt-1 text-xs text-muted-foreground">
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
                    id="risk-threshold"
                    type="number"
                    min={10}
                    max={100}
                    value={form.risk_review_threshold}
                    onChange={(e) =>
                      setField(
                        "risk_review_threshold",
                        parseInt(e.target.value) || 40,
                      )
                    }
                    className="mt-1 w-32"
                  />
                  <p className="mt-1 text-xs text-muted-foreground">
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
