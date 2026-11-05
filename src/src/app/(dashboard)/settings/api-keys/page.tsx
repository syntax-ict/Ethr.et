"use client";

import { useState } from "react";
import Link from "next/link";
import {
  BookOpen,
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
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { PageActions } from "@/components/shared/page-actions";
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
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { rules, fieldMessage } from "@/lib/forms/rules";
import { toast } from "sonner";
import { z } from "zod";
import { PlanFeatureNotice } from "@/components/shared/plan-feature-notice";
import { usePlanFeatures } from "@/features/auth/api";
import { apiErrorMessage } from "@/lib/api/error-message";

const ABILITIES = [
  "read",
  "write",
  "employees",
  "attendance",
  "leave",
  "payroll",
  "reports",
] as const satisfies readonly ApiKeyAbility[];

// How long a new key lives. A key that never expires is still offered, but it
// is no longer the only choice: the form had no expiry at all, so every key
// made in the console lived until someone remembered to revoke it
// (API/webhook end-to-end test, 2026-10-14, D5).
const EXPIRY_DAYS = ["30", "90", "365", "never"] as const;
type Expiry = (typeof EXPIRY_DAYS)[number];

function expiresAt(expiry: Expiry): string | null {
  if (expiry === "never") return null;
  return new Date(Date.now() + Number(expiry) * 86_400_000).toISOString();
}

const apiKeySchema = z.object({
  name: rules.requiredText(255),
  // An API key granting nothing authenticates and then 403s on every call — it
  // looks issued and is useless. The server enforces this; saying so here means
  // the user is not left guessing at a dead Create button.
  abilities: z
    .array(z.enum(ABILITIES))
    .min(1, "api_keys_page.abilities_required"),
  expiry: z.enum(EXPIRY_DAYS),
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
    defaultValues: { name: "", abilities: ["read"], expiry: "90" },
  });

  const selectedAbilities = watch("abilities");
  const expiry = watch("expiry");
  // The ability codes were shown raw ("read", "employees"); each now has a
  // label, and the reference's one-line meaning as its help.
  const abilityLabel: Record<ApiKeyAbility, string> = {
    read: t("api_keys_page.ability_read", "Read"),
    write: t("api_keys_page.ability_write", "Write"),
    employees: t("api_keys_page.ability_employees", "Employees"),
    attendance: t("api_keys_page.ability_attendance", "Attendance"),
    leave: t("api_keys_page.ability_leave", "Leave"),
    payroll: t("api_keys_page.ability_payroll", "Payroll"),
    reports: t("api_keys_page.ability_reports", "Reports"),
  };
  const abilityHelp: Record<ApiKeyAbility, string> = {
    read: t("api_reference.ability_read"),
    write: t("api_reference.ability_write"),
    employees: t("api_reference.ability_employees"),
    attendance: t("api_reference.ability_attendance"),
    leave: t("api_reference.ability_leave"),
    payroll: t("api_reference.ability_payroll"),
    reports: t("api_reference.ability_reports"),
  };
  const expiryLabel: Record<Expiry, string> = {
    "30": t("api_keys_page.expiry_30"),
    "90": t("api_keys_page.expiry_90"),
    "365": t("api_keys_page.expiry_365"),
    never: t("api_keys_page.expiry_never"),
  };

  const keysQuery = useApiKeys();
  const createKey = useCreateApiKey();
  // Creating a key needs the plan's `api_access` feature (N66).
  const hasApiAccess = usePlanFeatures().has("api_access");
  const { isSuperAdmin } = usePermissions();
  const revokeKey = useRevokeApiKey();

  // No `onError` toast — `submit` reports a rejected create inside the dialog,
  // on the field that caused it.
  async function create(values: ApiKeyValues) {
    const created = await createKey.mutateAsync({
      name: values.name,
      abilities: values.abilities,
      expires_at: expiresAt(values.expiry),
    });
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
      onError: (err) =>
        toast.error(apiErrorMessage(err, t("common.action_failed"))),
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
        <PageActions
          actions={
            <div className="flex flex-wrap gap-2">
              <Button variant="outline" asChild>
                <Link href="/settings/api-keys/reference">
                  <BookOpen className="mr-2 h-4 w-4" aria-hidden="true" />{" "}
                  {t("api_keys_page.reference")}
                </Link>
              </Button>
              {/* Only for whom the backend serves it: `/api/docs` is behind
                  the `viewApiDocs` gate, super admins only (admin-guide.md).
                  Shown to every tenant admin, it opened a bare 403 (production
                  check, 2026-10-14). */}
              {isSuperAdmin && (
                <Button variant="outline" asChild>
                  {/* A plain <a>, not next/link. `/api/docs` is the backend's
                      Swagger page reached through the `/api/:path*` rewrite,
                      not an app route — so next/link prefetched it as an RSC
                      navigation on mount, and that request never resolved. It
                      left the page permanently short of network-idle and made
                      every visit fire a pointless call to the docs endpoint. */}
                  <a href="/api/docs" target="_blank" rel="noopener noreferrer">
                    <ExternalLink className="mr-2 h-4 w-4" aria-hidden="true" />{" "}
                    {t("api_keys_page.api_docs", "API docs")}
                  </a>
                </Button>
              )}
              {hasApiAccess && (
                <Button onClick={() => setCreateOpen(true)}>
                  <Plus className="mr-2 h-4 w-4" aria-hidden="true" />{" "}
                  {t("api_keys_page.create_key", "Create key")}
                </Button>
              )}
            </div>
          }
        />

        {!hasApiAccess && <PlanFeatureNotice feature="api_access" />}

        {newKey && (
          <Card className="border-warning-edge bg-warning-soft">
            <CardContent className="p-4">
              <p className="mb-2 text-sm font-semibold text-warning-on-soft">
                {t("api_keys_page.save_now_hint")}
              </p>
              <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                <code className="block min-w-0 flex-1 rounded bg-background px-3 py-2 text-xs font-mono break-all">
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
              description={t(
                "api_keys_page.no_keys_desc",
                "Create a key to let another system read or update your ETHR data.",
              )}
              action={
                hasApiAccess ? (
                  <Button onClick={() => setCreateOpen(true)}>
                    <Plus className="mr-2 h-4 w-4" aria-hidden="true" />{" "}
                    {t("api_keys_page.create_key", "Create key")}
                  </Button>
                ) : undefined
              }
            />
          }
        >
          {(keys) => (
            <Card>
              <CardContent className="p-0">
                <SimpleTable
                  mobileCards
                  caption={t("api_keys_page.title", "API keys")}
                  headers={[
                    t("common.name"),
                    t("api_keys_page.prefix"),
                    t("api_keys_page.abilities"),
                    t("api_keys_page.created", "Created"),
                    t("api_keys_page.expires"),
                  ]}
                  colClassName={[
                    "",
                    "",
                    "hidden sm:table-cell",
                    "hidden md:table-cell",
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
                          <Badge variant="outline" className="text-caption">
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
                            className="text-caption"
                            title={
                              (abilityHelp as Partial<Record<string, string>>)[
                                a
                              ]
                            }
                          >
                            {(abilityLabel as Partial<Record<string, string>>)[
                              a
                            ] ?? a}
                          </Badge>
                        ))}
                      </div>,
                      <span key="c" className="text-muted-foreground">
                        {k.created_at ? formatDate(k.created_at) : "—"}
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
              </CardContent>
            </Card>
          )}
        </QueryBoundary>

        <ConfirmDialog
          open={!!revoking}
          onOpenChange={(open) => !open && setRevoking(null)}
          title={t("api_keys_page.revoke_confirm_title", "Revoke :name?", {
            name: revoking?.name ?? "",
          })}
          description={t(
            "api_keys_page.revoke_confirm_desc",
            "Every integration using this key stops working immediately. This cannot be undone.",
          )}
          confirmLabel={t("api_keys_page.revoke")}
          variant="destructive"
          loading={revokeKey.isPending}
          onConfirm={confirmRevoke}
        />

        <Dialog open={createOpen} onOpenChange={setCreateOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>
                {t("api_keys_page.create_key", "Create key")}
              </DialogTitle>
              <DialogDescription>
                {t(
                  "api_keys_page.create_desc",
                  "A key acts as you, limited to the abilities you tick. It is shown once, after you create it.",
                )}
              </DialogDescription>
            </DialogHeader>
            <form
              onSubmit={submit(
                create,
                t(
                  "api_keys_page.create_failed",
                  "The key could not be created.",
                ),
              )}
              className="space-y-4"
              noValidate
            >
              <FormErrorSummary message={rootError} />

              <FormField
                id="api-key-name"
                label={t("api_keys_page.key_name", "Key name")}
                required
                error={fieldMessage(t, errors.name?.message)}
              >
                <Input
                  {...register("name")}
                  placeholder={t(
                    "api_keys_page.key_name_placeholder",
                    "Mobile app integration",
                  )}
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
                      <div
                        key={a}
                        className="flex items-start gap-2 rounded-lg border p-2 hover:bg-muted/50"
                      >
                        <Checkbox
                          id={`api-key-ability-${a}`}
                          checked={selectedAbilities.includes(a)}
                          onCheckedChange={() => toggleAbility(a)}
                          aria-describedby={`api-key-ability-${a}-help`}
                          className="mt-0.5"
                        />
                        <div className="min-w-0 space-y-0.5">
                          <Label
                            htmlFor={`api-key-ability-${a}`}
                            className="cursor-pointer text-sm"
                          >
                            {abilityLabel[a]}
                          </Label>
                          <p
                            id={`api-key-ability-${a}-help`}
                            className="text-caption text-muted-foreground"
                          >
                            {abilityHelp[a]}
                          </p>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </FormField>

              <FormField id="api-key-expiry" label={t("api_keys_page.expiry")}>
                {(control) => (
                  <Select
                    value={expiry}
                    onValueChange={(value) =>
                      setValue("expiry", value as Expiry, { shouldDirty: true })
                    }
                  >
                    <SelectTrigger {...control} className="mt-1">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {EXPIRY_DAYS.map((d) => (
                        <SelectItem key={d} value={d}>
                          {expiryLabel[d]}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
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
