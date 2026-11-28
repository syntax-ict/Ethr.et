"use client";

import { useEffect, useId, useRef, useState } from "react";
import { Copy, Eye, Link2, PlugZap, Save, Unplug } from "lucide-react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import {
  backupErrorDetail,
  useCompleteBackupDrive,
  useConnectBackupDrive,
  useDisconnectBackupDrive,
  useRevealBackupDriveKey,
  useSaveBackupDrive,
  useTestBackupDrive,
  useUpdateBackupDriveSettings,
  type BackupDriveState,
} from "@/features/admin/backups";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { useConfirm } from "@/components/shared/use-confirm";

/**
 * Google Drive as the fallback for off-host backups, under the storage cards
 * on the Backups page. Used only when the main storage is off or a copy to it
 * fails, so it works with no storage subscription at all.
 *
 * Connecting sends the browser to Google and back to this same page with
 * `code` and `state` in the address. This card reads them once, removes them
 * from the address and posts them to the API, which checks `state` and keeps
 * the refresh token. The client secret, the token and the key are never held
 * here beyond what was just typed or asked for.
 */
export function BackupDriveCard({ state }: { state: BackupDriveState }) {
  const { t } = useT();
  const { requestConfirm, confirmDialog } = useConfirm();
  const { timeAgo } = useDateFormatters();
  const save = useSaveBackupDrive();
  const connect = useConnectBackupDrive();
  const complete = useCompleteBackupDrive();
  const disconnect = useDisconnectBackupDrive();
  const test = useTestBackupDrive();
  const update = useUpdateBackupDriveSettings();
  const reveal = useRevealBackupDriveKey();

  const [clientId, setClientId] = useState(state.client_id ?? "");
  const [clientSecret, setClientSecret] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [outcome, setOutcome] = useState<
    { ok: true } | { ok: false; reason: string } | null
  >(null);
  const [key, setKey] = useState<string | null>(null);
  const savedId = useId();
  const handled = useRef(false);

  // Google's return trip: read the answer once, clean the address, finish.
  useEffect(() => {
    if (handled.current) return;
    const params = new URLSearchParams(window.location.search);
    const code = params.get("code");
    const returned = params.get("state");
    const denied = params.get("error");
    if (!code && !denied) return;
    handled.current = true;
    window.history.replaceState(null, "", window.location.pathname);

    if (denied || !code || !returned) {
      const reason = t(
        "admin_backups.drive_denied",
        "Google Drive was not connected because access was not allowed.",
      );
      // After the effect, not inside it: the message is the page's answer to
      // a navigation that already happened.
      queueMicrotask(() => setOutcome({ ok: false, reason }));
      return;
    }

    complete.mutate(
      { code, state: returned },
      {
        onSuccess: () => setOutcome({ ok: true }),
        onError: (err) =>
          setOutcome({
            ok: false,
            reason:
              backupErrorDetail(err) ??
              t("admin_backups.drive_connect_failed", "Could not connect"),
          }),
      },
    );
    // Runs once on arrival; `complete` and `t` are stable enough for that.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const ready = state.verified && state.key?.saved_at !== null;
  // Production never copies to Drive (Proclamation 1321/2024 Art. 22): it
  // stores outside Ethiopia. What it holds can still be restored.
  const refusedHere = state.blocker === "off_host_outside_ethiopia";
  const secretRequired = !state.saved;

  function submit() {
    save.mutate(
      {
        client_id: clientId,
        client_secret: clientSecret === "" ? null : clientSecret,
      },
      {
        onSuccess: () => {
          setErrors({});
          setClientSecret("");
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

  function startConnect() {
    connect.mutate(undefined, {
      onSuccess: (url) => window.location.assign(url),
      onError: (err) =>
        toast.error(
          backupErrorDetail(err) ??
            t("admin_backups.drive_connect_failed", "Could not connect"),
        ),
    });
  }

  function runTest() {
    test.mutate(undefined, {
      onSuccess: (next) =>
        next.google_drive.last_test_ok
          ? toast.success(
              t(
                "admin_backups.drive_test_passed",
                "Google Drive passed the test",
              ),
            )
          : toast.error(
              t(
                "admin_backups.drive_test_failed",
                "Google Drive failed the test",
              ),
            ),
      onError: (err) =>
        toast.error(
          backupErrorDetail(err) ??
            t(
              "admin_backups.drive_test_failed",
              "Google Drive failed the test",
            ),
        ),
    });
  }

  function copy(text: string, done: string) {
    navigator.clipboard.writeText(text).then(
      () => toast.success(done),
      () => toast.error(t("common.action_failed")),
    );
  }

  function showKey() {
    reveal.mutate(undefined, {
      onSuccess: (value) => setKey(value),
      onError: (err) =>
        toast.error(
          backupErrorDetail(err) ??
            t("admin_backups.key_failed", "Could not show the key"),
        ),
    });
  }

  async function confirmDisconnect() {
    if (
      !(await requestConfirm({
        title: t(
          "admin_backups.drive_disconnect_confirm_title",
          "Disconnect Google Drive?",
        ),
        description: t(
          "admin_backups.drive_disconnect_confirm_description",
          "Backups stop being copied to Google Drive until it is connected and tested again. Files already there stay in your Drive.",
        ),
        confirmLabel: t(
          "admin_backups.drive_disconnect_confirm",
          "Disconnect Google Drive",
        ),
        variant: "destructive",
      }))
    )
      return;
    disconnect.mutate(undefined, {
      onSuccess: () =>
        toast.success(t("admin_backups.drive_disconnected", "Disconnected")),
      onError: (err) =>
        toast.error(
          backupErrorDetail(err) ??
            t("admin_backups.save_failed", "Could not save"),
        ),
    });
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex flex-wrap items-center gap-2">
          {t("admin_backups.drive_title", "Google Drive (fallback)")}
          {state.connected ? (
            <Badge variant="success">
              {t("admin_backups.drive_connected", "Connected")}
            </Badge>
          ) : (
            <Badge variant="neutral">
              {t("admin_backups.drive_not_connected", "Not connected")}
            </Badge>
          )}
          {state.connected &&
            (state.verified ? (
              <Badge variant="success">
                {t("admin_backups.verified", "Tested")}
              </Badge>
            ) : (
              <Badge variant="warning">
                {t("admin_backups.not_verified", "Not tested")}
              </Badge>
            ))}
          {state.enabled && (
            <Badge variant="success">{t("admin_backups.state_on", "On")}</Badge>
          )}
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-6">
        <p className="text-sm text-muted-foreground">
          {t(
            "admin_backups.drive_help",
            "Used only when the main storage is off or a copy to it fails. Files are encrypted before they leave this server. Google Drive stores them outside Ethiopia, so it is a fallback, not a replacement.",
          )}
        </p>

        {refusedHere && (
          <p
            role="status"
            className="rounded-lg bg-warning-soft p-3 text-sm text-warning-on-soft"
          >
            {t(
              "admin_backups.drive_refused_here",
              "Production does not copy backups to Google Drive, because it stores them outside Ethiopia (Proclamation 1321/2024, Art. 22). Archives already on Drive can still be restored.",
            )}
          </p>
        )}

        {outcome?.ok === true && (
          <p
            role="status"
            className="rounded-lg bg-success-soft p-3 text-sm text-success-on-soft"
          >
            {t(
              "admin_backups.drive_connected_message",
              "Google Drive is connected. Test the connection next.",
            )}
          </p>
        )}
        {outcome?.ok === false && (
          <p
            role="alert"
            className="rounded-lg bg-destructive/10 p-3 text-sm text-destructive"
          >
            {outcome.reason}
          </p>
        )}

        <div className="space-y-2">
          <Label htmlFor={`${savedId}-redirect`}>
            {t("admin_backups.drive_redirect", "Redirect URI for Google Cloud")}
          </Label>
          <div className="flex items-center gap-2">
            <code
              id={`${savedId}-redirect`}
              className="flex-1 break-all rounded bg-muted px-3 py-2 font-mono text-xs"
            >
              {state.redirect_uri}
            </code>
            <Button
              size="sm"
              variant="outline"
              aria-label={t(
                "admin_backups.drive_copy_redirect",
                "Copy the redirect URI",
              )}
              onClick={() =>
                copy(
                  state.redirect_uri,
                  t("admin_backups.drive_redirect_copied", "Address copied"),
                )
              }
            >
              <Copy className="h-4 w-4" aria-hidden="true" />
            </Button>
          </div>
          <p className="text-xs text-muted-foreground">
            {t(
              "admin_backups.drive_redirect_help",
              "Add this under Authorized redirect URIs on the OAuth client in Google Cloud. Check that the consent screen reads In production: a Testing app loses its access after 7 days.",
            )}
          </p>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-2">
            <Label htmlFor={`${savedId}-client-id`}>
              {t("admin_backups.drive_client_id", "Client ID")}
            </Label>
            <Input
              id={`${savedId}-client-id`}
              value={clientId}
              onChange={(e) => setClientId(e.target.value)}
              autoComplete="off"
              aria-invalid={errors.client_id ? true : undefined}
            />
            {errors.client_id?.[0] && (
              <p className="text-xs text-destructive">{errors.client_id[0]}</p>
            )}
          </div>
          <div className="space-y-2">
            <Label htmlFor={`${savedId}-client-secret`}>
              {t("admin_backups.drive_client_secret", "Client secret")}
            </Label>
            <Input
              id={`${savedId}-client-secret`}
              type="password"
              value={clientSecret}
              onChange={(e) => setClientSecret(e.target.value)}
              autoComplete="new-password"
              placeholder={
                state.secret_set
                  ? t(
                      "admin_backups.secret_kept",
                      "Saved. Leave empty to keep it.",
                    )
                  : undefined
              }
              aria-invalid={errors.client_secret ? true : undefined}
            />
            {errors.client_secret?.[0] && (
              <p className="text-xs text-destructive">
                {errors.client_secret[0]}
              </p>
            )}
          </div>
        </div>
        <p className="text-xs text-muted-foreground">
          {t(
            "admin_backups.drive_save_help",
            "Changing the client ID or secret disconnects Google Drive and switches it off until it is connected and tested again.",
          )}
        </p>

        {state.connected && (
          <p className="text-sm text-muted-foreground">
            {state.account_email
              ? t("admin_backups.drive_account", "Connected as :email :when.", {
                  email: state.account_email,
                  when: state.connected_at ? timeAgo(state.connected_at) : "",
                })
              : t("admin_backups.drive_account_unknown", "Connected :when.", {
                  when: state.connected_at ? timeAgo(state.connected_at) : "",
                })}
          </p>
        )}

        <div className="flex flex-wrap gap-2">
          <Button
            onClick={submit}
            disabled={
              save.isPending ||
              clientId === "" ||
              (secretRequired && clientSecret === "")
            }
          >
            <Save className="mr-2 h-4 w-4" />
            {t("admin_backups.drive_save", "Save client")}
          </Button>
          <Button
            variant="outline"
            onClick={startConnect}
            disabled={!state.saved || connect.isPending}
          >
            <Link2 className="mr-2 h-4 w-4" />
            {state.connected
              ? t("admin_backups.drive_reconnect", "Connect again")
              : t("admin_backups.drive_connect", "Connect Google Drive")}
          </Button>
          <Button
            variant="outline"
            onClick={runTest}
            disabled={!state.connected || test.isPending}
          >
            <PlugZap className="mr-2 h-4 w-4" />
            {test.isPending
              ? t("admin_backups.testing", "Testing")
              : t("admin_backups.drive_test", "Test Google Drive")}
          </Button>
          {state.connected && (
            <Button
              variant="ghost"
              onClick={confirmDisconnect}
              disabled={disconnect.isPending}
            >
              <Unplug className="mr-2 h-4 w-4" />
              {t("admin_backups.drive_disconnect", "Disconnect")}
            </Button>
          )}
        </div>

        {state.last_test_at && (
          <div className="space-y-1 text-sm">
            <p className="text-muted-foreground">
              {state.last_test_ok
                ? t(
                    "admin_backups.last_test_ok",
                    "Last test :when. A file was written, read back and deleted.",
                    { when: timeAgo(state.last_test_at) },
                  )
                : t(
                    "admin_backups.last_test_failed",
                    "Last test :when failed.",
                    { when: timeAgo(state.last_test_at) },
                  )}
            </p>
            {state.last_test_ok === false && state.last_test_error && (
              <pre className="whitespace-pre-wrap break-words rounded bg-muted p-3 text-xs">
                {state.last_test_error}
              </pre>
            )}
          </div>
        )}

        {state.saved && (
          <div className="space-y-4 border-t pt-5">
            <p className="text-sm text-muted-foreground">
              {t(
                "admin_backups.drive_key_help",
                "Copies on Google Drive use their own key, different from the main storage's. Keep a copy of it somewhere other than this server, such as a password manager. Without it a copy cannot be opened.",
              )}
            </p>
            {key === null ? (
              <Button
                variant="outline"
                onClick={showKey}
                disabled={reveal.isPending}
              >
                <Eye className="mr-2 h-4 w-4" />
                {t("admin_backups.drive_key_show", "Show the Google Drive key")}
              </Button>
            ) : (
              <div className="flex items-center gap-2">
                <code className="flex-1 break-all rounded bg-muted px-3 py-2 font-mono text-xs">
                  {key}
                </code>
                <Button
                  size="sm"
                  variant="outline"
                  aria-label={t("common.copy")}
                  onClick={() =>
                    copy(key, t("admin_backups.key_copied", "Key copied"))
                  }
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
                checked={state.key?.saved_at != null}
                disabled={update.isPending}
                onCheckedChange={(checked) =>
                  update.mutate(
                    { key_saved: checked === true },
                    {
                      onError: (err) =>
                        toast.error(
                          backupErrorDetail(err) ??
                            t("admin_backups.save_failed", "Could not save"),
                        ),
                    },
                  )
                }
              />
              <Label htmlFor={savedId} className="leading-snug">
                {t(
                  "admin_backups.drive_key_saved",
                  "I have saved the Google Drive key somewhere other than this server.",
                )}
              </Label>
            </div>

            <div className="flex items-center justify-between gap-4">
              <div>
                <p className="text-sm font-medium text-foreground">
                  {t(
                    "admin_backups.drive_switch_label",
                    "Use Google Drive when the main storage fails",
                  )}
                </p>
                <p className="text-xs text-muted-foreground">
                  {refusedHere
                    ? t(
                        "admin_backups.drive_switch_refused",
                        "Not available in production.",
                      )
                    : ready
                      ? t(
                          "admin_backups.drive_switch_help",
                          "If the main storage is off or a copy to it fails, the night's backup goes to Google Drive instead.",
                        )
                      : t(
                          "admin_backups.drive_switch_blocked",
                          "Connect Google Drive, pass the test and confirm the key is saved before switching this on.",
                        )}
                </p>
              </div>
              <Switch
                checked={state.enabled}
                disabled={
                  ((!ready || refusedHere) && !state.enabled) ||
                  update.isPending
                }
                aria-label={t(
                  "admin_backups.drive_switch_label",
                  "Use Google Drive when the main storage fails",
                )}
                onCheckedChange={(enabled) =>
                  update.mutate(
                    { enabled },
                    {
                      onSuccess: () =>
                        toast.success(t("admin_backups.saved", "Saved")),
                      onError: (err) =>
                        toast.error(
                          backupErrorDetail(err) ??
                            t("admin_backups.save_failed", "Could not save"),
                        ),
                    },
                  )
                }
              />
            </div>
          </div>
        )}
      </CardContent>
      {confirmDialog}
    </Card>
  );
}
