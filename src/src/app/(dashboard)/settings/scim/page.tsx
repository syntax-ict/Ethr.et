"use client";

import { useEffect, useRef, useState } from "react";
import {
  Link2,
  Copy,
  Check,
  AlertTriangle,
  KeyRound,
  Loader2,
  Trash2,
} from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { useHydrated } from "@/lib/hooks/useHydrated";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { RoleGate } from "@/components/shared/role-gate";
import { EmptyState } from "@/components/shared/empty-state";
import { SimpleTable } from "@/components/shared/simple-table";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { PlanFeatureNotice } from "@/components/shared/plan-feature-notice";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { useGenerateScimToken } from "@/features/settings/api";
import {
  useRevokeApiKey,
  useScimTokens,
  type ApiKey,
} from "@/features/api-keys/api";
import { useFeatureOffReason } from "@/features/auth/api";
import { toast } from "sonner";
import { apiErrorMessage } from "@/lib/api/error-message";

const SCIM_PATH = "/api/v1/scim/v2";

type Copied = "url" | "token" | null;

export default function ScimSettingsPage() {
  const { t } = useT();
  const [scimTokenName, setScimTokenName] = useState("");
  const [generatedToken, setGeneratedToken] = useState<string | null>(null);
  // Which copy button just succeeded. One flag used to serve both buttons, so
  // copying the URL flipped the token button's label instead of its own.
  const [copied, setCopied] = useState<Copied>(null);
  const copiedTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  // Read once hydrated: reading `window` during render produced a
  // server/client mismatch, since the server has no origin to print.
  const hydrated = useHydrated();
  const origin = hydrated ? window.location.origin : "";

  useEffect(
    () => () => {
      if (copiedTimer.current) clearTimeout(copiedTimer.current);
    },
    [],
  );

  const generateScimToken = useGenerateScimToken();
  const baseUrl = `${origin}${SCIM_PATH}`;

  // SCIM tokens are API keys, listed and revoked through the API keys
  // endpoints, which `apikey.manage` guards, not `settings.manage`.
  const { formatDate } = useDateFormatters();
  const { can } = usePermissions();
  const canManageKeys = can.manageApiKeys;
  const tokensQuery = useScimTokens(canManageKeys);
  const revokeKey = useRevokeApiKey();
  const [revoking, setRevoking] = useState<ApiKey | null>(null);
  const scimOffReason = useFeatureOffReason("scim");

  function confirmRevoke() {
    if (!revoking) return;
    revokeKey.mutate(revoking.public_id, {
      onSuccess: () => {
        toast.success(t("scim_page.token_revoked", "SCIM token revoked"));
        setRevoking(null);
      },
      onError: (err) =>
        toast.error(apiErrorMessage(err, t("common.action_failed"))),
    });
  }

  function generate() {
    generateScimToken.mutate(scimTokenName.trim(), {
      onSuccess: (result) => {
        setGeneratedToken(result.token);
        setScimTokenName("");
        toast.success(
          t("settings.scim_token_generated", "SCIM token generated"),
        );
      },
      onError: (err) =>
        toast.error(
          apiErrorMessage(
            err,
            t(
              "settings.scim_token_failed",
              "The token could not be generated.",
            ),
          ),
        ),
    });
  }

  function copyToClipboard(text: string, which: Exclude<Copied, null>) {
    navigator.clipboard.writeText(text).then(
      () => {
        setCopied(which);
        if (copiedTimer.current) clearTimeout(copiedTimer.current);
        copiedTimer.current = setTimeout(() => setCopied(null), 2000);
      },
      () => toast.error(t("common.action_failed")),
    );
  }

  return (
    <RoleGate anyPermission={["manageSettings"]}>
      <div className="space-y-6">
        <Card>
          <CardHeader>
            <div className="flex items-center gap-2">
              <Link2
                className="h-4 w-4 text-muted-foreground"
                aria-hidden="true"
              />
              <CardTitle className="text-base">
                {t("settings.scim_provisioning", "SCIM provisioning")}
              </CardTitle>
            </div>
            <CardDescription>
              {t(
                "settings.scim_intro",
                "Connect your identity provider so it creates, updates and deactivates ETHR users for you.",
              )}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-6">
            {scimOffReason !== null && (
              <div className="space-y-2">
                <p className="text-sm font-medium">
                  {t(
                    "scim_page.off_title",
                    "SCIM provisioning is off for your organisation. Your identity provider's requests are refused until it is back on.",
                  )}
                </p>
                <PlanFeatureNotice feature="scim" />
              </div>
            )}
            <ol className="space-y-6">
              <li className="space-y-3">
                <h3 className="text-heading">
                  1.{" "}
                  {t(
                    "settings.scim_step_token",
                    "Generate a bearer token. It is shown once.",
                  )}
                </h3>
                <div className="space-y-2">
                  <Label htmlFor="scim-token-name">
                    {t("settings.token_name", "Token name")}
                  </Label>
                  <div className="flex flex-col gap-2 sm:flex-row">
                    <Input
                      id="scim-token-name"
                      placeholder="Okta SCIM"
                      value={scimTokenName}
                      onChange={(e) => setScimTokenName(e.target.value)}
                      aria-describedby="scim-token-name-help"
                      className="sm:flex-1"
                    />
                    <Button
                      onClick={generate}
                      disabled={
                        !scimTokenName.trim() || generateScimToken.isPending
                      }
                    >
                      {generateScimToken.isPending ? (
                        <Loader2
                          className="mr-2 h-4 w-4 animate-spin"
                          aria-hidden="true"
                        />
                      ) : (
                        <KeyRound className="mr-2 h-4 w-4" aria-hidden="true" />
                      )}
                      {t(
                        "settings.generate_scim_token",
                        "Generate a bearer token",
                      )}
                    </Button>
                  </div>
                  <p
                    id="scim-token-name-help"
                    className="text-caption text-muted-foreground"
                  >
                    {t(
                      "settings.token_name_help",
                      "Name it after the identity provider that will use it, such as Okta or Microsoft Entra.",
                    )}
                  </p>
                </div>

                {generatedToken && (
                  <div className="space-y-2 rounded-lg border border-warning-edge bg-warning-soft p-4">
                    <div className="flex items-center gap-2">
                      <AlertTriangle
                        className="h-4 w-4 shrink-0 text-warning-on-soft"
                        aria-hidden="true"
                      />
                      <span className="text-sm font-medium text-warning-on-soft">
                        {t(
                          "settings.token_warning",
                          "Copy this token now. It will not be shown again.",
                        )}
                      </span>
                    </div>
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                      <code className="block min-w-0 flex-1 break-all rounded bg-background px-3 py-2 font-mono text-xs">
                        {generatedToken}
                      </code>
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => copyToClipboard(generatedToken, "token")}
                      >
                        {copied === "token" ? (
                          <Check
                            className="mr-1.5 h-3.5 w-3.5 text-success"
                            aria-hidden="true"
                          />
                        ) : (
                          <Copy
                            className="mr-1.5 h-3.5 w-3.5"
                            aria-hidden="true"
                          />
                        )}
                        {copied === "token"
                          ? t("common.copied", "Copied")
                          : t("common.copy", "Copy")}
                      </Button>
                    </div>
                  </div>
                )}
                {canManageKeys ? (
                  <div className="space-y-2">
                    <h4 className="text-sm font-medium">
                      {t("scim_page.tokens_title", "Tokens in use")}
                    </h4>
                    <QueryBoundary
                      query={tokensQuery}
                      loading={
                        <div className="space-y-2">
                          {Array.from({ length: 2 }).map((_, i) => (
                            <Skeleton key={i} className="h-12 w-full" />
                          ))}
                        </div>
                      }
                      empty={
                        <EmptyState
                          icon={KeyRound}
                          title={t("scim_page.no_tokens", "No SCIM tokens yet")}
                          description={t(
                            "scim_page.no_tokens_desc",
                            "Generate one above, then enter it in your identity provider.",
                          )}
                        />
                      }
                    >
                      {(tokens) => (
                        <div className="rounded-lg border">
                          <SimpleTable
                            mobileCards
                            caption={t(
                              "scim_page.tokens_title",
                              "Tokens in use",
                            )}
                            headers={[
                              t("common.name"),
                              t("api_keys_page.prefix"),
                              t("api_keys_page.created", "Created"),
                              t("scim_page.last_used", "Last used"),
                              t("api_keys_page.expires"),
                            ]}
                            colClassName={[
                              "",
                              "",
                              "hidden md:table-cell",
                              "hidden sm:table-cell",
                              "hidden md:table-cell",
                            ]}
                            rows={tokens.map((k) => ({
                              key: k.public_id,
                              cells: [
                                <span
                                  key="n"
                                  className="flex items-center gap-2"
                                >
                                  <span className="font-medium">{k.name}</span>
                                  {/* Expired tokens are still listed, but
                                      the server no longer accepts them. */}
                                  {!k.is_active && (
                                    <Badge
                                      variant="outline"
                                      className="text-caption"
                                    >
                                      {t("common.inactive")}
                                    </Badge>
                                  )}
                                </span>,
                                <span
                                  key="p"
                                  className="font-mono text-muted-foreground"
                                >
                                  {k.key_prefix}...
                                </span>,
                                <span key="c" className="text-muted-foreground">
                                  {k.created_at
                                    ? formatDate(k.created_at)
                                    : "—"}
                                </span>,
                                <span key="l" className="text-muted-foreground">
                                  {k.last_used_at
                                    ? formatDate(k.last_used_at)
                                    : t(
                                        "security_page.never_used",
                                        "Not used yet",
                                      )}
                                </span>,
                                <span key="e" className="text-muted-foreground">
                                  {k.expires_at
                                    ? formatDate(k.expires_at)
                                    : t("api_keys_page.expiry_never")}
                                </span>,
                              ],
                              actions: (
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  onClick={() => setRevoking(k)}
                                  aria-label={t(
                                    "api_keys_page.revoke_named",
                                    "Revoke :name",
                                    { name: k.name },
                                  )}
                                >
                                  <Trash2
                                    className="h-4 w-4 text-destructive"
                                    aria-hidden="true"
                                  />
                                </Button>
                              ),
                            }))}
                          />
                        </div>
                      )}
                    </QueryBoundary>
                  </div>
                ) : (
                  <p className="text-caption text-muted-foreground">
                    {t(
                      "settings.scim_revoke_hint",
                      "To revoke a token, ask an administrator who has API key access.",
                    )}
                  </p>
                )}
              </li>

              <li className="space-y-3">
                <h3 className="text-heading">
                  2.{" "}
                  {t(
                    "settings.scim_step_connect",
                    "In your identity provider, enter the base URL below and the token.",
                  )}
                </h3>
                <div className="rounded-lg border bg-muted/50 p-4">
                  <p className="text-caption font-medium uppercase tracking-wide text-muted-foreground">
                    {t("settings.scim_endpoint", "Base URL")}
                  </p>
                  <div className="mt-1 flex flex-col gap-2 sm:flex-row sm:items-center">
                    <code className="block min-w-0 flex-1 break-all rounded bg-background px-2 py-1 text-xs">
                      {baseUrl}
                    </code>
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => copyToClipboard(baseUrl, "url")}
                      aria-label={t("scim_page.copy_base_url", "Copy base URL")}
                    >
                      {copied === "url" ? (
                        <Check
                          className="mr-1.5 h-3.5 w-3.5 text-success"
                          aria-hidden="true"
                        />
                      ) : (
                        <Copy
                          className="mr-1.5 h-3.5 w-3.5"
                          aria-hidden="true"
                        />
                      )}
                      {copied === "url"
                        ? t("common.copied", "Copied")
                        : t("common.copy", "Copy")}
                    </Button>
                  </div>
                </div>
              </li>

              <li className="space-y-3">
                <h3 className="text-heading">
                  3.{" "}
                  {t(
                    "settings.scim_step_enable",
                    "Turn on provisioning there. Users then appear under People.",
                  )}
                </h3>
              </li>
            </ol>

            <div className="space-y-2 rounded-lg border p-4">
              <h3 className="text-heading">
                {t("settings.scim_capabilities", "What the connector supports")}
              </h3>
              <ul className="grid gap-1.5 text-xs text-muted-foreground sm:grid-cols-2">
                <li className="flex items-center gap-1.5">
                  <Check
                    className="h-3 w-3 shrink-0 text-success"
                    aria-hidden="true"
                  />
                  {t(
                    "settings.scim_users_crud",
                    "Users: create, read, update and deactivate",
                  )}
                </li>
                <li className="flex items-center gap-1.5">
                  <Check
                    className="h-3 w-3 shrink-0 text-success"
                    aria-hidden="true"
                  />
                  {t(
                    "settings.scim_groups_crud",
                    "Groups: create, read, update and deactivate",
                  )}
                </li>
                <li className="flex items-center gap-1.5">
                  <Check
                    className="h-3 w-3 shrink-0 text-success"
                    aria-hidden="true"
                  />
                  {t("settings.scim_filter", "Filter by userName and email")}
                </li>
                <li className="flex items-center gap-1.5">
                  <Check
                    className="h-3 w-3 shrink-0 text-success"
                    aria-hidden="true"
                  />
                  {t("settings.scim_pagination", "Pagination support")}
                </li>
              </ul>
            </div>
          </CardContent>
        </Card>

        <ConfirmDialog
          open={!!revoking}
          onOpenChange={(open) => !open && setRevoking(null)}
          title={t("api_keys_page.revoke_confirm_title", "Revoke :name?", {
            name: revoking?.name ?? "",
          })}
          description={t(
            "scim_page.revoke_confirm_desc",
            "Your identity provider stops provisioning users until you give it a new token. This cannot be undone.",
          )}
          confirmLabel={t("api_keys_page.revoke")}
          variant="destructive"
          loading={revokeKey.isPending}
          onConfirm={confirmRevoke}
        />
      </div>
    </RoleGate>
  );
}
