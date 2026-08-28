"use client";

import { useState, useMemo } from "react";
import { useSearchParams } from "next/navigation";
import {
  Save,
  Loader2,
  Shield,
  CalendarDays,
  KeyRound,
  Copy,
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
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import { Badge } from "@/components/ui/badge";
import { PageHeader } from "@/components/shared/page-header";
import { RoleGate } from "@/components/shared/role-gate";
import { OrganizationCard } from "@/features/settings/components/organization-card";
import { BrandingCard } from "@/features/settings/components/branding-card";
import {
  useCalendar,
  type CalendarSystem,
} from "@/lib/calendar/calendar-context";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { toast } from "sonner";

const VALID_TABS = ["general", "branding", "security", "sso"];

export default function SettingsPage() {
  const { t } = useT();
  const queryClient = useQueryClient();
  const { calendar, setCalendar } = useCalendar();
  const searchParams = useSearchParams();
  const defaultTab = useMemo(() => {
    const tab = searchParams.get("tab");
    return tab && VALID_TABS.includes(tab) ? tab : "general";
  }, [searchParams]);

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

  const [ssoDirty, setSsoDirty] = useState<Record<string, unknown>>({});

  const updateSso = useMutation({
    mutationFn: async (ssoSettings: Record<string, unknown>) => {
      const { data } = await apiClient.put("/settings/sso", ssoSettings);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["settings"] });
      setSsoDirty({});
      toast.success(t("settings.sso_saved", "SSO settings saved"));
    },
    onError: () =>
      toast.error(t("settings.sso_save_failed", "Failed to save SSO settings")),
  });

  function updateField(key: string, value: unknown) {
    setDirty((p) => ({ ...p, [key]: value }));
  }

  function updateSsoField(key: string, value: unknown) {
    setSsoDirty((p) => ({ ...p, [key]: value }));
  }

  function getSsoValue(key: string, fallback: unknown = ""): unknown {
    if (key in ssoDirty) return ssoDirty[key];
    return data?.sso?.[key] ?? fallback;
  }

  function copyToClipboard(text: string) {
    navigator.clipboard.writeText(text);
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

        <Tabs defaultValue={defaultTab} className="w-full">
          <TabsList className="flex w-full flex-wrap">
            <TabsTrigger value="general">
              {t("settings.tab_general", "General")}
            </TabsTrigger>
            <TabsTrigger value="branding">
              {t("settings.tab_branding", "Branding")}
            </TabsTrigger>
            <TabsTrigger value="security">
              {t("settings.tab_security", "Security")}
            </TabsTrigger>
            <TabsTrigger value="sso">
              {t("settings.tab_sso", "SSO")}
            </TabsTrigger>
          </TabsList>

          <TabsContent value="general" className="mt-6 space-y-6">
            <OrganizationCard organization={data?.organization} />

            <Card>
              <CardHeader>
                <div className="flex items-center gap-2">
                  <CalendarDays className="h-4 w-4 text-muted-foreground" />
                  <CardTitle className="text-base">
                    {t("settings.display", "Display")}
                  </CardTitle>
                </div>
              </CardHeader>
              <CardContent className="grid gap-4 sm:grid-cols-2">
                <div>
                  <Label htmlFor="org-calendar">
                    {t("settings.calendar_system", "Calendar System")}
                  </Label>
                  <Select
                    value={calendar}
                    onValueChange={(v) => setCalendar(v as CalendarSystem)}
                  >
                    <SelectTrigger id="org-calendar" className="mt-1">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="ethiopian">
                        {t(
                          "settings.ethiopian_calendar",
                          "Ethiopian Calendar (EC)",
                        )}
                      </SelectItem>
                      <SelectItem value="gregorian">
                        {t(
                          "settings.gregorian_calendar",
                          "Gregorian Calendar (GC)",
                        )}
                      </SelectItem>
                    </SelectContent>
                  </Select>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {t(
                      "settings.calendar_help",
                      "Controls date display across the application",
                    )}
                  </p>
                </div>
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="branding" className="mt-6">
            <BrandingCard
              logoUrl={data?.branding?.logo_url}
              theme={data?.branding?.theme}
            />
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

          <TabsContent value="sso" className="mt-6 space-y-6">
            <Card>
              <CardHeader>
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <KeyRound className="h-4 w-4 text-muted-foreground" />
                    <CardTitle className="text-base">
                      {t("settings.sso_saml", "SAML Single Sign-On")}
                    </CardTitle>
                  </div>
                  <Badge
                    variant={
                      getSsoValue("is_enabled") ? "success" : "secondary"
                    }
                  >
                    {getSsoValue("is_enabled")
                      ? t("settings.sso_enabled", "Enabled")
                      : t("settings.sso_disabled", "Disabled")}
                  </Badge>
                </div>
              </CardHeader>
              <CardContent className="space-y-6">
                <div className="flex items-center justify-between rounded-lg border p-4">
                  <div className="space-y-0.5">
                    <Label htmlFor="sso-toggle">
                      {t("settings.enable_sso", "Enable SSO")}
                    </Label>
                    <p className="text-xs text-muted-foreground">
                      {t(
                        "settings.enable_sso_help",
                        "Allow users to sign in with your identity provider",
                      )}
                    </p>
                  </div>
                  <Switch
                    id="sso-toggle"
                    checked={!!getSsoValue("is_enabled", false)}
                    onCheckedChange={(v) => updateSsoField("is_enabled", v)}
                  />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <Label htmlFor="idp-entity-id">
                      {t("settings.idp_entity_id", "IdP Entity ID")}
                    </Label>
                    <Input
                      id="idp-entity-id"
                      placeholder="https://idp.example.com/entity"
                      value={String(getSsoValue("idp_entity_id", ""))}
                      onChange={(e) =>
                        updateSsoField("idp_entity_id", e.target.value)
                      }
                      className="mt-1"
                    />
                  </div>
                  <div>
                    <Label htmlFor="idp-sso-url">
                      {t("settings.idp_sso_url", "IdP SSO URL")}
                    </Label>
                    <Input
                      id="idp-sso-url"
                      placeholder="https://idp.example.com/sso"
                      value={String(getSsoValue("idp_sso_url", ""))}
                      onChange={(e) =>
                        updateSsoField("idp_sso_url", e.target.value)
                      }
                      className="mt-1"
                    />
                  </div>
                </div>

                <div>
                  <Label htmlFor="idp-certificate">
                    {t("settings.idp_certificate", "IdP Certificate (PEM)")}
                  </Label>
                  <Textarea
                    id="idp-certificate"
                    placeholder={
                      "-----BEGIN CERTIFICATE-----\n...\n-----END CERTIFICATE-----"
                    }
                    value={String(getSsoValue("idp_certificate", "") ?? "")}
                    onChange={(e) =>
                      updateSsoField("idp_certificate", e.target.value)
                    }
                    className="mt-1 font-mono text-xs"
                    rows={5}
                  />
                  <p className="mt-1 text-xs text-muted-foreground">
                    {t(
                      "settings.idp_certificate_help",
                      "Paste the X.509 certificate from your identity provider. Stored encrypted.",
                    )}
                  </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <Label>
                      {t(
                        "settings.default_sso_role",
                        "Default Role for SSO Users",
                      )}
                    </Label>
                    <Select
                      value={String(getSsoValue("default_role", "employee"))}
                      onValueChange={(v) => updateSsoField("default_role", v)}
                    >
                      <SelectTrigger className="mt-1">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="employee">
                          {t("roles.employee", "Employee")}
                        </SelectItem>
                        <SelectItem value="supervisor">
                          {t("roles.supervisor", "Supervisor")}
                        </SelectItem>
                        <SelectItem value="dept_admin">
                          {t("roles.dept_admin", "Department Admin")}
                        </SelectItem>
                        <SelectItem value="hr_admin">
                          {t("roles.hr_admin", "HR Admin")}
                        </SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="flex items-center justify-between rounded-lg border p-4 sm:mt-5">
                    <div className="space-y-0.5">
                      <Label htmlFor="auto-provision">
                        {t("settings.auto_provision", "Auto-provision")}
                      </Label>
                      <p className="text-xs text-muted-foreground">
                        {t(
                          "settings.auto_provision_help",
                          "Automatically create accounts on first SSO login",
                        )}
                      </p>
                    </div>
                    <Switch
                      id="auto-provision"
                      checked={!!getSsoValue("auto_provision", false)}
                      onCheckedChange={(v) =>
                        updateSsoField("auto_provision", v)
                      }
                    />
                  </div>
                </div>

                {data?.sso?.metadata_url && (
                  <div className="rounded-lg border bg-muted/50 p-4">
                    <Label className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                      {t("settings.sp_metadata_url", "SP Metadata URL")}
                    </Label>
                    <div className="mt-1 flex items-center gap-2">
                      <code className="flex-1 truncate rounded bg-background px-2 py-1 text-xs">
                        {data.sso.metadata_url}
                      </code>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => copyToClipboard(data.sso.metadata_url)}
                      >
                        <Copy className="h-3.5 w-3.5" />
                      </Button>
                    </div>
                    <p className="mt-1 text-xs text-muted-foreground">
                      {t(
                        "settings.sp_metadata_help",
                        "Provide this URL to your identity provider",
                      )}
                    </p>
                  </div>
                )}

                {Object.keys(ssoDirty).length > 0 && (
                  <div className="flex justify-end">
                    <Button
                      onClick={() => updateSso.mutate(ssoDirty)}
                      disabled={updateSso.isPending}
                    >
                      {updateSso.isPending ? (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                      ) : (
                        <Save className="mr-2 h-4 w-4" />
                      )}
                      {t("settings.save_sso", "Save SSO Settings")}
                    </Button>
                  </div>
                )}
              </CardContent>
            </Card>
          </TabsContent>
        </Tabs>
      </div>
    </RoleGate>
  );
}
