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
import { toast } from "sonner";

const ALL_METHODS = [
  {
    key: "biometric",
    label: "Biometric Device",
    description: "Fingerprint, face recognition devices",
  },
  {
    key: "mobile",
    label: "Mobile Check-in",
    description: "GPS + selfie from employee phone",
  },
  {
    key: "qr",
    label: "QR Code Scan",
    description: "Scan QR at branch entrance",
  },
  {
    key: "kiosk",
    label: "Kiosk",
    description: "Shared device with employee code",
  },
  { key: "web", label: "Web Portal", description: "Check-in from dashboard" },
  {
    key: "manual",
    label: "Manual Entry",
    description: "HR/Admin manual recording",
  },
  {
    key: "csv",
    label: "CSV Import",
    description: "Bulk import from spreadsheet",
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
      toast.success("Settings saved");
    },
    onError: () => toast.error("Failed to save settings"),
  });

  function toggleMethod(method: string) {
    if (!form) return;
    const enabled = form.enabled_methods.includes(method)
      ? form.enabled_methods.filter((m) => m !== method)
      : [...form.enabled_methods, method];
    if (enabled.length === 0) {
      toast.error("At least one method must be enabled");
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
          title="Attendance Settings"
          description="Configure which attendance methods are enabled and their behavior"
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
              Save Changes
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
                  <Settings2 className="h-4 w-4" /> Enabled Attendance Methods
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
                          <p className="text-sm font-medium">{m.label}</p>
                          <p className="text-xs text-muted-foreground">
                            {m.description}
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
                <CardTitle className="text-base">Mobile Check-in</CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium">Require geofence</p>
                    <p className="text-xs text-muted-foreground">
                      Reject check-ins outside branch area
                    </p>
                  </div>
                  <Switch
                    checked={form.geofence_required}
                    onCheckedChange={(v) => setField("geofence_required", v)}
                  />
                </div>
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium">Require photo</p>
                    <p className="text-xs text-muted-foreground">
                      Selfie required for check-in
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
                    GPS accuracy threshold (meters)
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
                    Reject if GPS accuracy exceeds this
                  </p>
                </div>
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium">Offline sync</p>
                    <p className="text-xs text-muted-foreground">
                      Allow check-in when offline
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
                <CardTitle className="text-base">QR Code</CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div>
                  <Label className="text-sm">Default expiry (minutes)</Label>
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
                    <p className="text-sm font-medium">Auto-refresh</p>
                    <p className="text-xs text-muted-foreground">
                      Auto-regenerate when QR expires
                    </p>
                  </div>
                  <Switch
                    checked={form.qr_auto_refresh}
                    onCheckedChange={(v) => setField("qr_auto_refresh", v)}
                  />
                </div>
                <div>
                  <Label className="text-sm">Single-use limit</Label>
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
                    0 = unlimited scans per QR
                  </p>
                </div>
              </CardContent>
            </Card>

            {/* Kiosk Settings */}
            <Card>
              <CardHeader>
                <CardTitle className="text-base">Kiosk</CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium">Require employee PIN</p>
                    <p className="text-xs text-muted-foreground">
                      Employees must enter PIN after code
                    </p>
                  </div>
                  <Switch
                    checked={form.kiosk_pin_required}
                    onCheckedChange={(v) => setField("kiosk_pin_required", v)}
                  />
                </div>
                <div>
                  <Label className="text-sm">Auto-reset delay (seconds)</Label>
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
                    Reset kiosk after success/error
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
