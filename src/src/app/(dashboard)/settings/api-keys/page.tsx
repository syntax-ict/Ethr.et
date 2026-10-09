"use client";

import { useState } from "react";
import {
  KeyRound,
  Plus,
  Trash2,
  Copy,
  Loader2,
  ExternalLink,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { SimpleTable } from "@/components/shared/simple-table";
import { RoleGate } from "@/components/shared/role-gate";
import { FormField } from "@/components/patterns/FormField";
import { FormErrorSummary } from "@/components/patterns/FormErrorSummary";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import {
  useApiKeys,
  useCreateApiKey,
  useRevokeApiKey,
  type ApiKey,
  type ApiKeyAbility,
} from "@/features/api-keys/api";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { rules, fieldMessage } from "@/lib/forms/rules";
import { toast } from "sonner";
import { z } from "zod";
import { PlanFeatureNotice } from "@/components/shared/plan-feature-notice";
import { usePlanFeatures } from "@/features/auth/api";

const ABILITIES = [
  "read",
  "write",
  "employees",
  "attendance",
  "leave",
  "payroll",
  "reports",
] as const satisfies readonly ApiKeyAbility[];

const apiKeySchema = z.object({
  name: rules.requiredText(255),
  // An API key granting nothing authenticates and then 403s on every call — it
  // looks issued and is useless. The server enforces this; saying so here means
  // the user is not left guessing at a dead Create button.
  abilities: z
    .array(z.enum(ABILITIES))
    .min(1, "api_keys_page.abilities_required"),
});
type ApiKeyValues = z.infer<typeof apiKeySchema>;

export default function ApiKeysPage() {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  const [createOpen, setCreateOpen] = useState(false);
  const [newKey, setNewKey] = useState<string | null>(null);
  const [revoking, setRevoking] = useState<ApiKey | null>(null);

  const {
    register,
    submit,
    reset,
    watch,
    setValue,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<ApiKeyValues>({
    schema: apiKeySchema,
    defaultValues: { name: "", abilities: ["read"] },
  });

  const selectedAbilities = watch("abilities");

  const keysQuery = useApiKeys();
  const createKey = useCreateApiKey();
  // Creating a key needs the plan's `api_access` feature (N66).
  const hasApiAccess = usePlanFeatures().has("api_access");
  const revokeKey = useRevokeApiKey();

  // No `onError` toast — `submit` reports a rejected create inside the dialog,
  // on the field that caused it.
  async function create(values: ApiKeyValues) {
    const created = await createKey.mutateAsync(values);
    setNewKey(created.key);
    setCreateOpen(false);
    reset();
    toast.success(t("api_keys_page.key_created"));
  }

  // Revoking is immediate and permanent — every integration using the key
  // stops authenticating — so it is confirmed first. It used to fire on a
  // single click of the row's icon, with no message at all if it failed.
  function confirmRevoke() {
    if (!revoking) return;
    revokeKey.mutate(revoking.public_id, {
      onSuccess: () => {
        toast.success(t("api_keys_page.key_revoked"));
        setRevoking(null);
      },
      onError: () => toast.error(t("common.action_failed")),
    });
  }

  function toggleAbility(ability: ApiKeyAbility) {
    const next = selectedAbilities.includes(ability)
      ? selectedAbilities.filter((a) => a !== ability)
      : [...selectedAbilities, ability];
    setValue("abilities", next, { shouldValidate: true, shouldDirty: true });
  }

  function copyKey() {
    if (!newKey) return;
    navigator.clipboard.writeText(newKey).then(
      () => toast.success(t("api_keys_page.key_copied")),
      () => toast.error(t("common.action_failed")),
    );
  }

  return (
    <RoleGate anyPermission={["manageApiKeys"]}>
      <div className="space-y-6">
        <PageHeader
          title={t("api_keys_page.title")}
          description={t("api_keys_page.description")}
          actions={
            <div className="flex gap-2">
              <Button variant="outline" asChild>
                {/* A plain <a>, not next/link. `/api/docs` is the backend's
                    Swagger page reached through the `/api/:path*` rewrite, not
                    an app route — so next/link prefetched it as an RSC
                    navigation on mount, and that request never resolved. It
                    left the page permanently short of network-idle and made
                    every visit fire a pointless call to the docs endpoint. */}
                <a href="/api/docs" target="_blank" rel="noopener noreferrer">
                  <ExternalLink className="mr-2 h-4 w-4" aria-hidden="true" />{" "}
                  {t("api_keys_page.api_docs")}
                </a>
              </Button>
              {hasApiAccess && (
                <Button onClick={() => setCreateOpen(true)}>
                  <Plus className="mr-2 h-4 w-4" />{" "}
                  {t("api_keys_page.create_key")}
                </Button>
              )}
            </div>
          }
        />

        {!hasApiAccess && <PlanFeatureNotice />}

        {newKey && (
          <Card className="border-2 border-status-warning/40 bg-status-warning/5">
            <CardContent className="p-4">
              <p className="mb-2 text-sm font-semibold text-foreground">
                {t("api_keys_page.save_now_hint")}
              </p>
              <div className="flex items-center gap-2">
                <code className="flex-1 rounded bg-background px-3 py-2 text-xs font-mono break-all">
                  {newKey}
                </code>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={copyKey}
                  aria-label={t("common.copy")}
                >
                  <Copy className="h-4 w-4" aria-hidden="true" />
                </Button>
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() => setNewKey(null)}
                >
                  {t("api_keys_page.dismiss")}
                </Button>
              </div>
            </CardContent>
          </Card>
        )}

        <QueryBoundary
          query={keysQuery}
          loading={
            <div className="space-y-3">
              {Array.from({ length: 3 }).map((_, i) => (
                <Skeleton key={i} className="h-20 w-full" />
              ))}
            </div>
          }
          empty={
            <EmptyState
              icon={KeyRound}
              title={t("api_keys_page.no_keys")}
              description={t("api_keys_page.no_keys_desc")}
            />
          }
        >
          {(keys) => (
            <Card>
              <CardContent className="p-0">
                <SimpleTable
                  caption={t("api_keys_page.title", "API Keys")}
                  headers={[
                    t("common.name"),
                    t("api_keys_page.prefix"),
                    t("api_keys_page.abilities"),
                    t("attendance.kiosks_page.created"),
                  ]}
                  colClassName={[
                    "",
                    "",
                    "hidden sm:table-cell",
                    "hidden md:table-cell",
                  ]}
                  rows={keys.map((k) => ({
                    key: k.public_id,
                    cells: [
                      <span key="n" className="flex items-center gap-2">
                        <span className="font-medium">{k.name}</span>
                        {/* An expired key is still listed (only revoked ones
                          are not) but no longer authenticates. */}
                        {!k.is_active && (
                          <Badge variant="outline" className="text-[10px]">
                            {t("common.inactive")}
                          </Badge>
                        )}
                      </span>,
                      <span key="p" className="font-mono text-muted-foreground">
                        {k.key_prefix}...
                      </span>,
                      <div key="a" className="flex flex-wrap gap-1">
                        {k.abilities.map((a) => (
                          <Badge
                            key={a}
                            variant="outline"
                            className="text-[10px]"
                          >
                            {a}
                          </Badge>
                        ))}
                      </div>,
                      <span key="c" className="text-muted-foreground">
                        {k.created_at ? formatDate(k.created_at) : "—"}
                      </span>,
                    ],
                    actions: (
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setRevoking(k)}
                      >
                        <Trash2 className="h-4 w-4 text-destructive" />
                        <span className="sr-only">
                          {t("api_keys_page.revoke", "Revoke")}
                        </span>
                      </Button>
                    ),
                  }))}
                />
              </CardContent>
            </Card>
          )}
        </QueryBoundary>

        <ConfirmDialog
          open={!!revoking}
          onOpenChange={(open) => !open && setRevoking(null)}
          title={`${t("api_keys_page.revoke")}: ${revoking?.name ?? ""}`}
          description={t("devices_page.delete_confirm_suffix")}
          confirmLabel={t("api_keys_page.revoke")}
          variant="destructive"
          loading={revokeKey.isPending}
          onConfirm={confirmRevoke}
        />

        <Dialog open={createOpen} onOpenChange={setCreateOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>{t("api_keys_page.create_key")}</DialogTitle>
            </DialogHeader>
            <form
              onSubmit={submit(create, t("api_keys_page.create_failed"))}
              className="space-y-4"
              noValidate
            >
              <FormErrorSummary message={rootError} />

              <FormField
                id="api-key-name"
                label={t("api_keys_page.key_name")}
                required
                error={fieldMessage(t, errors.name?.message)}
              >
                <Input
                  {...register("name")}
                  placeholder={t("api_keys_page.key_name_placeholder")}
                  className="mt-1"
                />
              </FormField>

              <FormField
                id="api-key-abilities"
                label={t("api_keys_page.abilities")}
                required
                error={fieldMessage(t, errors.abilities?.message)}
              >
                {(control) => (
                  <div
                    {...control}
                    role="group"
                    aria-label={t("api_keys_page.abilities")}
                    className="mt-2 grid grid-cols-2 gap-2"
                  >
                    {ABILITIES.map((a) => (
                      <label
                        key={a}
                        className="flex cursor-pointer items-center gap-2 rounded-lg border p-2 hover:bg-muted/50"
                      >
                        <input
                          type="checkbox"
                          checked={selectedAbilities.includes(a)}
                          onChange={() => toggleAbility(a)}
                          className="h-4 w-4 rounded"
                        />
                        <span className="text-sm capitalize">{a}</span>
                      </label>
                    ))}
                  </div>
                )}
              </FormField>

              <DialogFooter>
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setCreateOpen(false)}
                >
                  {t("common.cancel")}
                </Button>
                <Button type="submit" disabled={isSubmitting}>
                  {isSubmitting && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  {t("api_keys_page.create")}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>
    </RoleGate>
  );
}
