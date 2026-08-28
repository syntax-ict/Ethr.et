"use client";

import { useState } from "react";
import {
  Link2,
  Copy,
  Check,
  AlertTriangle,
  KeyRound,
  Loader2,
} from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { PageHeader } from "@/components/shared/page-header";
import { RoleGate } from "@/components/shared/role-gate";
import { useMutation } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { toast } from "sonner";

export default function ScimSettingsPage() {
  const { t } = useT();
  const [scimTokenName, setScimTokenName] = useState("");
  const [generatedToken, setGeneratedToken] = useState<string | null>(null);
  const [tokenCopied, setTokenCopied] = useState(false);

  const generateScimToken = useMutation({
    mutationFn: async (name: string) => {
      const { data } = await apiClient.post("/settings/scim-token", { name });
      return data;
    },
    onSuccess: (result) => {
      setGeneratedToken(result.token);
      setScimTokenName("");
      toast.success(t("settings.scim_token_generated", "SCIM token generated"));
    },
    onError: () =>
      toast.error(t("settings.scim_token_failed", "Failed to generate token")),
  });

  function copyToClipboard(text: string) {
    navigator.clipboard.writeText(text);
    setTokenCopied(true);
    setTimeout(() => setTokenCopied(false), 2000);
  }

  return (
    <RoleGate minRole="tenant_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("settings.scim_provisioning", "SCIM Provisioning")}
          description={t(
            "settings.scim_description",
            "Connect your identity provider (Okta, Azure AD, OneLogin) to automatically provision and deprovision user accounts via SCIM 2.0.",
          )}
        />

        <Card>
          <CardHeader>
            <div className="flex items-center gap-2">
              <Link2 className="h-4 w-4 text-muted-foreground" />
              <CardTitle className="text-base">
                {t("settings.scim_provisioning", "SCIM Provisioning")}
              </CardTitle>
            </div>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="rounded-lg border bg-muted/50 p-4">
              <Label className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                {t("settings.scim_endpoint", "SCIM Endpoint")}
              </Label>
              <div className="mt-1 flex items-center gap-2">
                <code className="flex-1 truncate rounded bg-background px-2 py-1 text-xs">
                  {typeof window !== "undefined"
                    ? `${window.location.origin}/api/v1/scim/v2`
                    : "/api/v1/scim/v2"}
                </code>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() =>
                    copyToClipboard(`${window.location.origin}/api/v1/scim/v2`)
                  }
                  aria-label={t("scim_page.copy_base_url", "Copy base URL")}
                >
                  <Copy className="h-3.5 w-3.5" aria-hidden="true" />
                </Button>
              </div>
            </div>

            <div className="space-y-3">
              <Label>
                {t("settings.generate_scim_token", "Generate Bearer Token")}
              </Label>
              <div className="flex gap-2">
                <Input
                  placeholder={t(
                    "settings.token_name_placeholder",
                    "Token name (e.g. Okta SCIM)",
                  )}
                  value={scimTokenName}
                  onChange={(e) => setScimTokenName(e.target.value)}
                />
                <Button
                  onClick={() => generateScimToken.mutate(scimTokenName)}
                  disabled={
                    !scimTokenName.trim() || generateScimToken.isPending
                  }
                >
                  {generateScimToken.isPending ? (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  ) : (
                    <KeyRound className="mr-2 h-4 w-4" />
                  )}
                  {t("settings.generate", "Generate")}
                </Button>
              </div>
            </div>

            {generatedToken && (
              <div className="rounded-lg border border-status-warning/30 bg-status-warning/5 p-4 space-y-2">
                <div className="flex items-center gap-2">
                  <AlertTriangle className="h-4 w-4 text-status-warning" />
                  <span className="text-sm font-medium">
                    {t(
                      "settings.token_warning",
                      "Copy this token now — it will not be shown again",
                    )}
                  </span>
                </div>
                <div className="flex items-center gap-2">
                  <code className="flex-1 truncate rounded bg-background px-3 py-2 font-mono text-xs">
                    {generatedToken}
                  </code>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => copyToClipboard(generatedToken)}
                  >
                    {tokenCopied ? (
                      <Check className="mr-1.5 h-3.5 w-3.5 text-status-success" />
                    ) : (
                      <Copy className="mr-1.5 h-3.5 w-3.5" />
                    )}
                    {tokenCopied
                      ? t("common.copied", "Copied")
                      : t("common.copy", "Copy")}
                  </Button>
                </div>
              </div>
            )}

            <div className="rounded-lg border p-4 space-y-2">
              <h4 className="text-sm font-medium">
                {t("settings.scim_capabilities", "Supported Operations")}
              </h4>
              <ul className="grid gap-1.5 text-xs text-muted-foreground sm:grid-cols-2">
                <li className="flex items-center gap-1.5">
                  <Check className="h-3 w-3 text-status-success" />
                  {t(
                    "settings.scim_users_crud",
                    "Users: Create, Read, Update, Deactivate",
                  )}
                </li>
                <li className="flex items-center gap-1.5">
                  <Check className="h-3 w-3 text-status-success" />
                  {t(
                    "settings.scim_groups_crud",
                    "Groups: Create, Read, Update, Deactivate",
                  )}
                </li>
                <li className="flex items-center gap-1.5">
                  <Check className="h-3 w-3 text-status-success" />
                  {t("settings.scim_filter", "Filter by userName and email")}
                </li>
                <li className="flex items-center gap-1.5">
                  <Check className="h-3 w-3 text-status-success" />
                  {t("settings.scim_pagination", "Pagination support")}
                </li>
              </ul>
            </div>
          </CardContent>
        </Card>
      </div>
    </RoleGate>
  );
}
