"use client";

import { Copy, Loader2, Signal } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { useTestDeviceConnection } from "@/features/devices/api";
import { AdmsSetupValues } from "@/features/devices/components/adms-setup-values";
import { syncErrorHintKeys } from "@/features/devices/components/device-heard";
import { PushSetup } from "@/features/devices/components/push-setup";
import type { IssuedSetup } from "@/features/devices/issued-setup";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";

/**
 * What to do on, or about, a terminal just registered, by how it connects.
 *
 * Push: the terminal's full address, shown once. The server keeps only a hash
 * of the token, so after this dialog closes nothing can show the address
 * again; the only way to see one is to regenerate it.
 * ADMS: the server, port and serial to enter on the terminal. No token.
 * Pull: ETHR calls the terminal, so nothing is typed on it.
 */
export function IssuedAddressDialog({
  setup,
  onClose,
}: {
  /** What to show; null keeps the dialog closed. */
  setup: IssuedSetup | null;
  onClose: () => void;
}) {
  const { t } = useT();
  // The address of a push terminal cannot be shown again, so only Done closes
  // that dialog. The other two can be read again on the device's page.
  const oneTime = setup?.mode === "push";

  return (
    <Dialog open={setup !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent
        className={oneTime ? "[&>button]:hidden" : undefined}
        onInteractOutside={oneTime ? (e) => e.preventDefault() : undefined}
        onEscapeKeyDown={oneTime ? (e) => e.preventDefault() : undefined}
      >
        {setup?.mode === "push" && <PushBody setup={setup} />}
        {setup?.mode === "adms" && <AdmsBody setup={setup} />}
        {setup?.mode === "pull" && <PullBody setup={setup} />}
        <DialogFooter>
          <Button onClick={onClose}>{t("device_token.done")}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function PushBody({
  setup,
}: {
  setup: Extract<IssuedSetup, { mode: "push" }>;
}) {
  const { t } = useT();

  async function copy() {
    try {
      await navigator.clipboard.writeText(setup.url);
      toast.success(t("device_token.copied"));
    } catch {
      toast.error(t("devices_page.copy_failed"));
    }
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>{t("device_token.issued_title")}</DialogTitle>
        <DialogDescription>{t("device_token.issued_intro")}</DialogDescription>
      </DialogHeader>
      <div className="flex items-start gap-2">
        <code
          data-testid="issued-address"
          className="flex-1 rounded border bg-muted/50 px-3 py-2 text-xs font-mono break-all"
        >
          {setup.url}
        </code>
        <Button
          variant="outline"
          size="icon"
          className="h-8 w-8 shrink-0"
          aria-label={t("device_token.copy")}
          onClick={() => void copy()}
        >
          <Copy className="h-3 w-3" />
        </Button>
      </div>
      <PushSetup url={setup.url} />
      {setup.graceHours ? (
        <p className="text-xs text-muted-foreground">
          {t("device_token.grace_note", undefined, { hours: setup.graceHours })}
        </p>
      ) : null}
    </>
  );
}

function AdmsBody({
  setup,
}: {
  setup: Extract<IssuedSetup, { mode: "adms" }>;
}) {
  const { t } = useT();

  return (
    <>
      <DialogHeader>
        <DialogTitle>{t("adms_setup.title")}</DialogTitle>
        <DialogDescription>{t("adms_setup.where")}</DialogDescription>
      </DialogHeader>
      <div data-testid="adms-setup" className="space-y-3">
        <AdmsSetupValues server={setup.server} serial={setup.serial} />
        <p className="text-xs text-muted-foreground">
          {t("device_setup.adms_no_token")}
        </p>
        {setup.allowlistEmpty && (
          <p className="rounded-lg border border-warning-edge bg-warning-soft p-3 text-sm text-warning-on-soft">
            {t("adms_setup.no_allowlist")}
          </p>
        )}
      </div>
    </>
  );
}

function PullBody({
  setup,
}: {
  setup: Extract<IssuedSetup, { mode: "pull" }>;
}) {
  const { t } = useT();
  const test = useTestDeviceConnection();
  const [result, setResult] = useState<string | null>(null);

  function runTest() {
    setResult(null);
    test.mutate(setup.devicePublicId, {
      onSuccess: ({ status, error_code }) => {
        if (status === "online") {
          setResult(t("devices_page.online_responding"));
          return;
        }
        const hint = syncErrorHintKeys(error_code, setup.adapterType)
          .map((k) => t(k))
          .join(" ");
        setResult(
          `${t("devices_page.device_status", "The device is :status", {
            status: t(`devices_page.${status}`, status),
          })}${hint ? ` ${hint}` : ""}`,
        );
      },
      onError: (err) =>
        setResult(apiErrorMessage(err, t("devices_page.test_failed"))),
    });
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>{t("device_setup.pull_title")}</DialogTitle>
        <DialogDescription>{t("device_setup.pull_intro")}</DialogDescription>
      </DialogHeader>
      <div className="space-y-2">
        <Button
          variant="outline"
          size="sm"
          onClick={runTest}
          disabled={test.isPending}
        >
          {test.isPending ? (
            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
          ) : (
            <Signal className="mr-2 h-4 w-4" />
          )}
          {t("devices_page.test_connection")}
        </Button>
        {result && (
          <p role="status" className="text-sm">
            {result}
          </p>
        )}
      </div>
    </>
  );
}
