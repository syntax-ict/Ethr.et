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
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { rules, fieldMessage } from "@/lib/forms/rules";
import { toast } from "sonner";
import { z } from "zod";

interface ApiKey {
  public_id: string;
  name: string;
  key_prefix: string;
  abilities: string[];
  is_active: boolean;
  last_used_at: string | null;
  expires_at: string | null;
  created_at: string;
}

const ABILITIES = [
  "read",
  "write",
  "employees",
  "attendance",
  "leave",
  "payroll",
  "reports",
] as const;

const apiKeySchema = z.object({
  name: rules.requiredText(255),
  // An API key granting nothing authenticates and then 403s on every call — it
  // looks issued and is useless. The server enforces this; saying so here means
  // the user is not left guessing at a dead Create button.
  abilities: z.array(z.string()).min(1, "api_keys_page.abilities_required"),
});
type ApiKeyValues = z.infer<typeof apiKeySchema>;

export default function ApiKeysPage() {
  const { t } = useT();
  const queryClient = useQueryClient();
  const [createOpen, setCreateOpen] = useState(false);
  const [newKey, setNewKey] = useState<string | null>(null);

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

  const { data, isLoading } = useQuery({
    queryKey: ["api-keys"],
    queryFn: async () => {
      const { data } = await apiClient.get("/api-keys");
      return data;
    },
  });

  // No `onError` toast — a rejected create is reported inside the dialog now,
  // on the field that caused it.
  const createKey = useMutation({
    mutationFn: async (values: ApiKeyValues) => {
      const { data } = await apiClient.post("/api-keys", values);
      return data;
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ["api-keys"] });
      setNewKey(data.key);
      setCreateOpen(false);
      reset();
      toast.success(t("api_keys_page.key_created"));
    },
  });

  const revokeKey = useMutation({
    mutationFn: async (id: string) => {
      await apiClient.delete(`/api-keys/${id}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["api-keys"] });
      toast.success(t("api_keys_page.key_revoked"));
    },
  });

  const keys: ApiKey[] = data?.keys ?? [];

  function toggleAbility(ability: string) {
    const next = selectedAbilities.includes(ability)
      ? selectedAbilities.filter((a) => a !== ability)
      : [...selectedAbilities, ability];
    setValue("abilities", next, { shouldValidate: true, shouldDirty: true });
  }

  function copyKey() {
    if (newKey) {
      navigator.clipboard.writeText(newKey);
      toast.success(t("api_keys_page.key_copied"));
    }
  }

  return (
    <RoleGate minRole="tenant_admin">
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
              <Button onClick={() => setCreateOpen(true)}>
                <Plus className="mr-2 h-4 w-4" />{" "}
                {t("api_keys_page.create_key")}
              </Button>
            </div>
          }
        />

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
                <Button size="sm" variant="outline" onClick={copyKey}>
                  <Copy className="h-4 w-4" />
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

        {isLoading ? (
          <div className="space-y-3">
            {Array.from({ length: 3 }).map((_, i) => (
              <Skeleton key={i} className="h-20 w-full" />
            ))}
          </div>
        ) : keys.length === 0 ? (
          <EmptyState
            icon={KeyRound}
            title={t("api_keys_page.no_keys")}
            description={t("api_keys_page.no_keys_desc")}
          />
        ) : (
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
                    <span key="n" className="font-medium">
                      {k.name}
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
                      {new Date(k.created_at).toLocaleDateString()}
                    </span>,
                  ],
                  actions: (
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => revokeKey.mutate(k.public_id)}
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

        <Dialog open={createOpen} onOpenChange={setCreateOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>{t("api_keys_page.create_key")}</DialogTitle>
            </DialogHeader>
            <form
              onSubmit={submit(
                (values) => createKey.mutateAsync(values),
                t("api_keys_page.create_failed"),
              )}
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
