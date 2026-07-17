"use client";

import { useEffect, useState } from "react";
import { Settings2, Loader2, Save } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { RoleGate } from "@/components/shared/role-gate";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

const ALL_METHODS = [
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

interface AttendanceSettings {
  enabled_methods: string[];
  geofence_required: boolean;
  mobile_photo_required: boolean;
  kiosk_pin_required: boolean;
  qr_expiry_minutes: number;
  qr_auto_refresh: boolean;
  qr_single_use_limit: number;
  mobile_accuracy_threshold_meters: number;
  offline_sync_enabled: boolean;
  kiosk_auto_reset_seconds: number;
}

export default function AttendanceSettingsPage() {
  const { t } = useT();
  const queryClient = useQueryClient();
  const [form, setForm] = useState<AttendanceSettings | null>(null);

  const { data, isLoading } = useQuery<AttendanceSettings>({
    queryKey: ["attendance", "settings"],
    queryFn: async () => (await apiClient.get("/attendance/settings")).data,
  });

  useEffect(() => {
    if (data && !form) setForm(data);
  }, [data, form]);

  const save = useMutation({
    mutationFn: async (payload: Partial<AttendanceSettings>) => {
      const { data } = await apiClient.put("/attendance/settings", payload);
      return data;
    },
    onSuccess: (updated) => {
      setForm(updated);
      queryClient.invalidateQueries({ queryKey: ["attendance", "settings"] });
      toast.success(t("attendance.settings_page.saved"));
    },
    onError: () => toast.error(t("attendance.settings_page.save_failed")),
  });

  function toggleMethod(method: string) {
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
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("attendance.settings_page.title")}
          description={t("attendance.settings_page.description")}
          actions={
            <Button
              onClick={() => form && save.mutate(form)}
              disabled={!form || save.isPending}
            >
              {save.isPending ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : (
                <Save className="mr-2 h-4 w-4" />
              )}
              {t("attendance.settings_page.save_changes")}
            </Button>
          }
        />

        {isLoading || !form ? (
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
                        onClick={() => toggleMethod(m.key)}
                      >
                        <Switch
                          checked={enabled}
                          onCheckedChange={() => toggleMethod(m.key)}
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

            {/* Mobile Settings */}
            <Card>
              <CardHeader>
                <CardTitle className="text-base">
                  {t("attendance.settings_page.mobile_checkin")}
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium">
                      {t("attendance.settings_page.require_geofence")}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {t("attendance.settings_page.require_geofence_desc")}
                    </p>
                  </div>
                  <Switch
                    checked={form.geofence_required}
                    onCheckedChange={(v) => setField("geofence_required", v)}
                  />
                </div>
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium">
                      {t("attendance.settings_page.require_photo")}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {t("attendance.settings_page.require_photo_desc")}
                    </p>
                  </div>
                  <Switch
                    checked={form.mobile_photo_required}
                    onCheckedChange={(v) =>
                      setField("mobile_photo_required", v)
                    }
                  />
                </div>
                <div>
                  <Label className="text-sm">
                    {t("attendance.settings_page.gps_accuracy_threshold")}
                  </Label>
                  <Input
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
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium">
                      {t("attendance.settings_page.offline_sync")}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {t("attendance.settings_page.offline_sync_desc")}
                    </p>
                  </div>
                  <Switch
                    checked={form.offline_sync_enabled}
                    onCheckedChange={(v) => setField("offline_sync_enabled", v)}
                  />
                </div>
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
                  <Label className="text-sm">
                    {t("attendance.settings_page.default_expiry")}
                  </Label>
                  <Input
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
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium">
                      {t("attendance.settings_page.auto_refresh")}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {t("attendance.settings_page.auto_refresh_desc")}
                    </p>
                  </div>
                  <Switch
                    checked={form.qr_auto_refresh}
                    onCheckedChange={(v) => setField("qr_auto_refresh", v)}
                  />
                </div>
                <div>
                  <Label className="text-sm">
                    {t("attendance.settings_page.single_use_limit")}
                  </Label>
                  <Input
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
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium">
                      {t("attendance.settings_page.require_pin")}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {t("attendance.settings_page.require_pin_desc")}
                    </p>
                  </div>
                  <Switch
                    checked={form.kiosk_pin_required}
                    onCheckedChange={(v) => setField("kiosk_pin_required", v)}
                  />
                </div>
                <div>
                  <Label className="text-sm">
                    {t("attendance.settings_page.auto_reset_delay")}
                  </Label>
                  <Input
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
          </div>
        )}
      </div>
    </RoleGate>
  );
}
