"use client";

import { useId, useState } from "react";
import { Copy, DatabaseBackup, Eye, PlugZap, Save } from "lucide-react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";
import { RoleGate } from "@/components/shared/role-gate";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  useBackupConsole,
  useRevealBackupKey,
  useSaveBackupStorage,
  useTestBackupStorage,
  useUpdateBackupSettings,
  type BackupConsoleState,
} from "@/features/admin/backups";
import { reasonLabel } from "@/features/admin/components/operations-labels";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";

/**
 * Off-host backup storage for the whole platform: the bucket, a test that
 * proves it, the key that opens every copy, and only then the switch.
 *
 * Nothing here names a provider. Any S3-compatible storage works; the
 * operator copies the endpoint, bucket and access key from the provider's
 * documentation, and the test button is what shows it works.
 */
export default function BackupsPage() {
  const query = useBackupConsole();

  return (
    <RoleGate minRole="super_admin">
      <QueryBoundary
        query={query}
        loading={<Skeleton className="h-96 w-full" />}
        isEmpty={() => false}
      >
        {(state) => (
          <div className="space-y-6">
            <StatusCard state={state} />
            <StorageCard state={state} />
            {state.storage !== null && <KeyCard state={state} />}
          </div>
        )}
      </QueryBoundary>
    </RoleGate>
  );
}

function errorDetail(err: unknown): string | undefined {
  const data = (
    err as {
      response?: {
        data?: { detail?: string; errors?: Record<string, string[]> };
      };
    }
  ).response?.data;

  return data?.errors
    ? Object.values(data.errors)[0]?.[0]
    : (data?.detail ?? undefined);
}

function StatusCard({ state }: { state: BackupConsoleState }) {
  const { t } = useT();
  const { timeAgo } = useDateFormatters();
  const update = useUpdateBackupSettings();
  const ready =
    state.storage?.verified === true && state.key?.saved_at !== null;
  const when = (iso: string | null) =>
    iso === null ? t("admin_backups.never", "Never") : timeAgo(iso);

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <DatabaseBackup className="h-5 w-5" />
          {t("admin_backups.status_title", "Off-host copies")}
          {state.available ? (
            <Badge variant="success">{t("admin_backups.state_on", "On")}</Badge>
          ) : (
            <Badge variant="neutral">
              {t("admin_backups.state_off", "Off")}
            </Badge>
          )}
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-5">
        {state.source === "env" && (
          <p className="rounded-lg bg-info-soft p-3 text-sm text-info-on-soft">
            {t(
              "admin_backups.source_env",
              "Off-host copies are currently configured in the server's .env file. Saving storage here takes over from it.",
            )}
          </p>
        )}

        {state.blocker !== null && (
          <p className="text-sm text-muted-foreground">
            {reasonLabel(t, state.blocker)}
          </p>
        )}

        <dl className="grid gap-3 text-sm sm:grid-cols-2">
          <div>
            <dt className="text-muted-foreground">
              {t("admin_backups.last_backup", "Last backup on this server")}
            </dt>
            <dd className="font-medium text-foreground">
              {when(state.health.last_backup_at)}
            </dd>
          </div>
          <div>
            <dt className="text-muted-foreground">
              {t("admin_backups.last_copy", "Last off-host copy")}
            </dt>
            <dd className="font-medium text-foreground">
              {when(state.health.off_host.last_copied_at)}
            </dd>
          </div>
        </dl>

        <div className="flex items-center justify-between gap-4">
          <div>
            <p className="text-sm font-medium text-foreground">
              {t("admin_backups.switch_label", "Copy each nightly backup")}
            </p>
            <p className="text-xs text-muted-foreground">
              {ready
                ? t(
                    "admin_backups.switch_help",
                    "Each night's backup is encrypted on this server, then copied to the storage below. The newest :count copies are kept.",
                    { count: state.keep },
                  )
                : t(
                    "admin_backups.switch_blocked",
                    "Save the storage, pass the connection test and confirm the key is saved before switching this on.",
                  )}
            </p>
          </div>
          <Switch
            checked={state.enabled}
            disabled={(!ready && !state.enabled) || update.isPending}
            aria-label={t(
              "admin_backups.switch_label",
              "Copy each nightly backup",
            )}
            onCheckedChange={(enabled) =>
              update.mutate(
                { enabled },
                {
                  onSuccess: () =>
                    toast.success(t("admin_backups.saved", "Saved")),
                  onError: (err) =>
                    toast.error(
                      errorDetail(err) ??
                        t("admin_backups.save_failed", "Could not save"),
                    ),
                },
              )
            }
          />
        </div>
      </CardContent>
    </Card>
  );
}

interface StorageForm {
  endpoint: string;
  region: string;
  bucket: string;
  access_key_id: string;
  secret_access_key: string;
  path_style: boolean;
}

function StorageCard({ state }: { state: BackupConsoleState }) {
  const { t } = useT();
  const { timeAgo } = useDateFormatters();
  const storage = state.storage;
  const save = useSaveBackupStorage();
  const test = useTestBackupStorage();
  const [form, setForm] = useState<StorageForm>(() => ({
    endpoint: storage?.endpoint ?? "",
    region: storage?.region ?? "us-east-1",
    bucket: storage?.bucket ?? "",
    access_key_id: storage?.access_key_id ?? "",
    secret_access_key: "",
    path_style: storage?.path_style ?? true,
  }));
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const pathStyleId = useId();

  const set = (key: keyof StorageForm) => (value: string) =>
    setForm((prev) => ({ ...prev, [key]: value }));

  function submit() {
    save.mutate(
      {
        ...form,
        secret_access_key:
          form.secret_access_key === "" ? null : form.secret_access_key,
      },
      {
        onSuccess: () => {
          setErrors({});
          setForm((prev) => ({ ...prev, secret_access_key: "" }));
          toast.success(t("admin_backups.saved", "Saved"));
        },
        onError: (err: unknown) => {
          const data = (
            err as {
              response?: { data?: { errors?: Record<string, string[]> } };
            }
          ).response?.data;
          setErrors(data?.errors ?? {});
          toast.error(t("admin_backups.save_failed", "Could not save"));
        },
      },
    );
  }

  function runTest() {
    test.mutate(undefined, {
      onSuccess: (next) =>
        next.storage?.last_test_ok
          ? toast.success(
              t("admin_backups.test_passed", "The storage passed the test"),
            )
          : toast.error(
              t("admin_backups.test_failed", "The storage failed the test"),
            ),
      onError: (err) =>
        toast.error(
          errorDetail(err) ??
            t("admin_backups.test_failed", "The storage failed the test"),
        ),
    });
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex flex-wrap items-center gap-2">
          {t("admin_backups.storage_title", "Storage")}
          {storage &&
            (storage.verified ? (
              <Badge variant="success">
                {t("admin_backups.verified", "Tested")}
              </Badge>
            ) : (
              <Badge variant="warning">
                {t("admin_backups.not_verified", "Not tested")}
              </Badge>
            ))}
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-6">
        <p className="text-sm text-muted-foreground">
          {t(
            "admin_backups.storage_help",
            "Any S3-compatible object storage. Copy these values from your provider. Changing any of them switches copies off until the storage is tested again.",
          )}
        </p>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label={t("admin_backups.endpoint", "Endpoint URL")}
            value={form.endpoint}
            onChange={set("endpoint")}
            placeholder="https://"
            error={errors.endpoint}
          />
          <Field
            label={t("admin_backups.region", "Region")}
            value={form.region}
            onChange={set("region")}
            error={errors.region}
          />
          <Field
            label={t("admin_backups.bucket", "Bucket")}
            value={form.bucket}
            onChange={set("bucket")}
            error={errors.bucket}
          />
          <Field
            label={t("admin_backups.access_key_id", "Access key ID")}
            value={form.access_key_id}
            onChange={set("access_key_id")}
            autoComplete="off"
            error={errors.access_key_id}
          />
          <Field
            label={t("admin_backups.secret", "Secret access key")}
            type="password"
            value={form.secret_access_key}
            onChange={set("secret_access_key")}
            autoComplete="new-password"
            placeholder={
              storage?.secret_set
                ? t(
                    "admin_backups.secret_kept",
                    "Saved. Leave empty to keep it.",
                  )
                : undefined
            }
            error={errors.secret_access_key}
          />
          <div className="flex items-center gap-3 sm:pt-7">
            <Switch
              id={pathStyleId}
              checked={form.path_style}
              onCheckedChange={(path_style) =>
                setForm((prev) => ({ ...prev, path_style }))
              }
            />
            <Label htmlFor={pathStyleId}>
              {t(
                "admin_backups.path_style",
                "Bucket name in the path (most providers other than AWS)",
              )}
            </Label>
          </div>
        </div>

        <div className="flex flex-wrap gap-2">
          <Button onClick={submit} disabled={save.isPending}>
            <Save className="mr-2 h-4 w-4" />
            {t("admin_backups.save", "Save storage")}
          </Button>
          <Button
            variant="outline"
            onClick={runTest}
            disabled={storage === null || test.isPending}
          >
            <PlugZap className="mr-2 h-4 w-4" />
            {test.isPending
              ? t("admin_backups.testing", "Testing")
              : t("admin_backups.test", "Test connection")}
          </Button>
        </div>

        {storage?.last_test_at && (
          <div className="space-y-1 text-sm">
            <p className="text-muted-foreground">
              {storage.last_test_ok
                ? t(
                    "admin_backups.last_test_ok",
                    "Last test :when. A file was written, read back and deleted.",
                    { when: timeAgo(storage.last_test_at) },
                  )
                : t(
                    "admin_backups.last_test_failed",
                    "Last test :when failed.",
                    {
                      when: timeAgo(storage.last_test_at),
                    },
                  )}
            </p>
            {storage.last_test_ok === false && storage.last_test_error && (
              <pre className="whitespace-pre-wrap break-words rounded bg-muted p-3 text-xs">
                {storage.last_test_error}
              </pre>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function KeyCard({ state }: { state: BackupConsoleState }) {
  const { t } = useT();
  const { timeAgo } = useDateFormatters();
  const reveal = useRevealBackupKey();
  const update = useUpdateBackupSettings();
  const [key, setKey] = useState<string | null>(null);
  const savedId = useId();
  const saved = state.key?.saved_at !== null;

  function show() {
    reveal.mutate(undefined, {
      onSuccess: (value) => setKey(value),
      onError: (err) =>
        toast.error(
          errorDetail(err) ??
            t("admin_backups.key_failed", "Could not show the key"),
        ),
    });
  }

  function copyKey() {
    if (!key) return;
    navigator.clipboard.writeText(key).then(
      () => toast.success(t("admin_backups.key_copied", "Key copied")),
      () => toast.error(t("common.action_failed")),
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t("admin_backups.key_title", "Encryption key")}</CardTitle>
      </CardHeader>
      <CardContent className="space-y-5">
        <p className="text-sm text-muted-foreground">
          {t(
            "admin_backups.key_help",
            "Every copy is encrypted with this key before it leaves the server, so the storage provider sees only encrypted files. Keep a copy of the key somewhere other than this server, such as a password manager. If the server is lost, the copies cannot be opened without it.",
          )}
        </p>

        {key === null ? (
          <Button variant="outline" onClick={show} disabled={reveal.isPending}>
            <Eye className="mr-2 h-4 w-4" />
            {t("admin_backups.key_show", "Show key")}
          </Button>
        ) : (
          <div className="flex items-center gap-2">
            <code className="flex-1 break-all rounded bg-muted px-3 py-2 font-mono text-xs">
              {key}
            </code>
            <Button
              size="sm"
              variant="outline"
              onClick={copyKey}
              aria-label={t("common.copy")}
            >
              <Copy className="h-4 w-4" aria-hidden="true" />
            </Button>
            <Button size="sm" variant="ghost" onClick={() => setKey(null)}>
              {t("admin_backups.key_hide", "Hide")}
            </Button>
          </div>
        )}

        {state.key?.revealed_at && (
          <p className="text-xs text-muted-foreground">
            {t("admin_backups.key_last_shown", "Last shown :when.", {
              when: timeAgo(state.key.revealed_at),
            })}
          </p>
        )}

        <div className="flex items-start gap-3">
          <Checkbox
            id={savedId}
            checked={saved}
            disabled={update.isPending}
            onCheckedChange={(checked) =>
              update.mutate(
                { key_saved: checked === true },
                {
                  onError: (err) =>
                    toast.error(
                      errorDetail(err) ??
                        t("admin_backups.save_failed", "Could not save"),
                    ),
                },
              )
            }
          />
          <Label htmlFor={savedId} className="leading-snug">
            {t(
              "admin_backups.key_saved",
              "I have saved this key somewhere other than this server.",
            )}
          </Label>
        </div>
      </CardContent>
    </Card>
  );
}

function Field({
  label,
  value,
  onChange,
  error,
  ...rest
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  error?: string[];
} & Omit<React.InputHTMLAttributes<HTMLInputElement>, "onChange" | "value">) {
  const id = useId();
  return (
    <div className="space-y-2">
      <Label htmlFor={id}>{label}</Label>
      <Input
        id={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        aria-invalid={error ? true : undefined}
        {...rest}
      />
      {error?.[0] && <p className="text-xs text-destructive">{error[0]}</p>}
    </div>
  );
}
