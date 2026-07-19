"use client";

import { useState } from "react";
import {
  Save,
  Loader2,
  Shield,
  Clock,
  CalendarDays,
  Building2,
  Wallet,
} from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { PageHeader } from "@/components/shared/page-header";
import { RoleGate } from "@/components/shared/role-gate";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { toast } from "sonner";

export default function SettingsPage() {
  const { t } = useT();
  const queryClient = useQueryClient();

  const { data, isLoading } = useQuery({
    queryKey: ["settings"],
    queryFn: async () => {
      const { data } = await apiClient.get("/settings");
      return data;
    },
  });

  const [dirty, setDirty] = useState<Record<string, unknown>>({});

  const updateSettings = useMutation({
    mutationFn: async (settings: Record<string, unknown>) => {
      const { data } = await apiClient.put("/settings", { settings });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["settings"] });
      setDirty({});
      toast.success(t("settings.saved", "Settings saved"));
    },
    onError: () =>
      toast.error(t("settings.save_failed", "Failed to save settings")),
  });

  function updateField(key: string, value: unknown) {
    setDirty((p) => ({ ...p, [key]: value }));
  }

  function getValue(key: string, fallback: unknown = ""): string {
    if (key in dirty) return String(dirty[key]);
    const parts = key.split(".");
    let val: unknown = data;
    for (const p of parts) {
      val = (val as Record<string, unknown>)?.[p];
    }
    return String(val ?? fallback);
  }

  if (isLoading) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-10 w-80" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  const hasDirty = Object.keys(dirty).length > 0;

  return (
    <RoleGate minRole="tenant_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("settings.title", "Settings")}
          description={t("settings.description", "Configure your organization")}
          actions={
            hasDirty && (
              <Button
                onClick={() => updateSettings.mutate(dirty)}
                disabled={updateSettings.isPending}
              >
                {updateSettings.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <Save className="mr-2 h-4 w-4" />
                )}
                {t("common.save", "Save Changes")}
              </Button>
            )
          }
        />

        <Tabs defaultValue="general" className="w-full">
          <TabsList className="grid w-full grid-cols-2 sm:grid-cols-5">
            <TabsTrigger value="general">
              {t("settings.tab_general", "General")}
            </TabsTrigger>
            <TabsTrigger value="attendance">
              {t("settings.tab_attendance", "Attendance")}
            </TabsTrigger>
            <TabsTrigger value="leave">
              {t("settings.tab_leave", "Leave")}
            </TabsTrigger>
            <TabsTrigger value="payroll">
              {t("settings.tab_payroll", "Payroll")}
            </TabsTrigger>
            <TabsTrigger value="security">
              {t("settings.tab_security", "Security")}
            </TabsTrigger>
          </TabsList>

          <TabsContent value="general" className="mt-6">
            <Card>
              <CardHeader>
                <div className="flex items-center gap-2">
                  <Building2 className="h-4 w-4 text-muted-foreground" />
                  <CardTitle className="text-base">
                    {t("settings.organization", "Organization")}
                  </CardTitle>
                </div>
              </CardHeader>
              <CardContent className="grid gap-4 sm:grid-cols-2">
                <div>
                  <Label htmlFor="org-name">
                    {t("settings.organization_name", "Organization Name")}
                  </Label>
                  <Input
                    id="org-name"
                    value={data?.organization?.name ?? ""}
                    disabled
                    className="mt-1"
                  />
                  <p className="mt-1 text-xs text-muted-foreground">
                    {t("settings.contact_support", "Contact support to change")}
                  </p>
                </div>
                <div>
                  <Label htmlFor="org-subdomain">{t("settings.subdomain", "Subdomain")}</Label>
                  <div className="mt-1 flex items-center gap-2">
                    <Input
                      id="org-subdomain"
                      value={data?.organization?.subdomain ?? ""}
                      disabled
                    />
                    <span className="shrink-0 text-sm text-muted-foreground">
                      .ethr.et
                    </span>
                  </div>
                </div>
                <div>
                  <Label htmlFor="org-timezone">{t("settings.timezone", "Timezone")}</Label>
                  <Input
                    id="org-timezone"
                    value={data?.organization?.timezone ?? "Africa/Addis_Ababa"}
                    disabled
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label htmlFor="org-language">{t("settings.language", "Language")}</Label>
                  <Select
                    value={getValue("locale", "en")}
                    onValueChange={(v) => updateField("locale", v)}
                  >
                    <SelectTrigger id="org-language" className="mt-1">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="en">
                        {t("settings.english", "English")}
                      </SelectItem>
                      <SelectItem value="am">
                        {t("settings.amharic", "Amharic")}
                      </SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="attendance" className="mt-6">
            <Card>
              <CardHeader>
                <div className="flex items-center gap-2">
                  <Clock className="h-4 w-4 text-muted-foreground" />
                  <CardTitle className="text-base">
                    {t("settings.attendance_rules", "Attendance Rules")}
                  </CardTitle>
                </div>
              </CardHeader>
              <CardContent className="grid gap-4 sm:grid-cols-2">
                <div>
                  <Label>
                    {t("settings.grace_period", "Grace Period (minutes)")}
                  </Label>
                  <Input
                    type="number"
                    value={getValue("grace_period_minutes", "15")}
                    onChange={(e) =>
                      updateField(
                        "grace_period_minutes",
                        parseInt(e.target.value),
                      )
                    }
                    className="mt-1"
                  />
                  <p className="mt-1 text-xs text-muted-foreground">
                    {t(
                      "settings.grace_period_help",
                      "Minutes after shift start before marking late",
                    )}
                  </p>
                </div>
                <div>
                  <Label>
                    {t("settings.ot_daily_cap", "OT Daily Cap (minutes)")}
                  </Label>
                  <Input
                    type="number"
                    value={getValue("ot_daily_cap_minutes", "120")}
                    onChange={(e) =>
                      updateField(
                        "ot_daily_cap_minutes",
                        parseInt(e.target.value),
                      )
                    }
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label>
                    {t(
                      "settings.confidence_threshold",
                      "Confidence Threshold (%)",
                    )}
                  </Label>
                  <Input
                    type="number"
                    min="0"
                    max="100"
                    value={getValue("confidence_threshold", "70")}
                    onChange={(e) =>
                      updateField(
                        "confidence_threshold",
                        parseInt(e.target.value),
                      )
                    }
                    className="mt-1"
                  />
                  <p className="mt-1 text-xs text-muted-foreground">
                    {t(
                      "settings.confidence_threshold_help",
                      "Minimum confidence score for attendance records",
                    )}
                  </p>
                </div>
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="leave" className="mt-6">
            <Card>
              <CardHeader>
                <div className="flex items-center gap-2">
                  <CalendarDays className="h-4 w-4 text-muted-foreground" />
                  <CardTitle className="text-base">
                    {t("settings.leave_policies", "Leave Policies")}
                  </CardTitle>
                </div>
              </CardHeader>
              <CardContent>
                <div>
                  <Label>{t("settings.working_days", "Working Days")}</Label>
                  <p className="mt-1 text-sm text-muted-foreground">
                    {t(
                      "settings.working_days_help",
                      "Monday through Friday (default). Configure leave types under Leave Types management.",
                    )}
                  </p>
                </div>
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="payroll" className="mt-6">
            <Card>
              <CardHeader>
                <div className="flex items-center gap-2">
                  <Wallet className="h-4 w-4 text-muted-foreground" />
                  <CardTitle className="text-base">
                    {t(
                      "settings.payroll_configuration",
                      "Payroll Configuration",
                    )}
                  </CardTitle>
                </div>
              </CardHeader>
              <CardContent className="grid gap-4 sm:grid-cols-2">
                <div>
                  <Label>{t("settings.pay_period", "Pay Period")}</Label>
                  <Input
                    value={data?.payroll?.pay_period ?? "monthly"}
                    disabled
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label>
                    {t("settings.payroll_run_day", "Payroll Run Day")}
                  </Label>
                  <Input
                    type="number"
                    min="1"
                    max="28"
                    value={getValue("run_day", "25")}
                    onChange={(e) =>
                      updateField("run_day", parseInt(e.target.value))
                    }
                    className="mt-1"
                  />
                  <p className="mt-1 text-xs text-muted-foreground">
                    {t(
                      "settings.payroll_run_day_help",
                      "Day of month to run payroll",
                    )}
                  </p>
                </div>
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="security" className="mt-6">
            <Card>
              <CardHeader>
                <div className="flex items-center gap-2">
                  <Shield className="h-4 w-4 text-muted-foreground" />
                  <CardTitle className="text-base">
                    {t("settings.security_settings", "Security Settings")}
                  </CardTitle>
                </div>
              </CardHeader>
              <CardContent className="grid gap-4 sm:grid-cols-2">
                <div>
                  <Label>{t("settings.mfa_policy", "MFA Policy")}</Label>
                  <Select
                    value={getValue("mfa_policy", "optional")}
                    onValueChange={(v) => updateField("mfa_policy", v)}
                  >
                    <SelectTrigger className="mt-1">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="disabled">
                        {t("settings.mfa_disabled", "Disabled")}
                      </SelectItem>
                      <SelectItem value="optional">
                        {t("settings.mfa_optional", "Optional")}
                      </SelectItem>
                      <SelectItem value="required">
                        {t("settings.mfa_required", "Required")}
                      </SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div>
                  <Label>
                    {t("settings.session_timeout", "Session Timeout (minutes)")}
                  </Label>
                  <Input
                    type="number"
                    value={getValue("session_timeout_minutes", "480")}
                    onChange={(e) =>
                      updateField(
                        "session_timeout_minutes",
                        parseInt(e.target.value),
                      )
                    }
                    className="mt-1"
                  />
                </div>
              </CardContent>
            </Card>
          </TabsContent>
        </Tabs>
      </div>
    </RoleGate>
  );
}
