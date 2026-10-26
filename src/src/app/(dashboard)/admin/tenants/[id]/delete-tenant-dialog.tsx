"use client";

import { useState } from "react";
import { AlertTriangle, Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { useDeleteTenant } from "@/features/admin/api";
import { apiErrorDetail } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";

type Props = {
  tenant: { public_id: string; name: string };
  /** Where the organisation is reached, as the page prints it. */
  address: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
};

/**
 * Deleting an organisation, the second of two deliberate steps (it must be
 * cancelled first). Two things are asked for, and the server checks each
 * again: the reason, which goes into the audit log, and a current
 * authenticator code, so an unattended console session is not enough. The
 * dialog names the organisation and its address, so the right one is in
 * front of the person deleting it. Typing the address out was dropped on
 * 2026-10-26 by owner decision.
 *
 * The dialog says plainly what deleting does and does not do: it closes the
 * organisation and keeps its records, and it can be undone.
 */
export function DeleteTenantDialog({
  tenant,
  address,
  open,
  onOpenChange,
}: Props) {
  const { t } = useT();
  const remove = useDeleteTenant();
  const [reason, setReason] = useState("");
  const [code, setCode] = useState("");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const ready = reason.trim().length >= 5 && /^\d{6}$/.test(code);

  function close(next: boolean) {
    if (remove.isPending) return;
    onOpenChange(next);
    if (!next) {
      setReason("");
      setCode("");
      setFieldErrors({});
    }
  }

  function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!ready) return;
    setFieldErrors({});
    remove.mutate(
      {
        publicId: tenant.public_id,
        code,
        reason: reason.trim(),
      },
      {
        onSuccess: () => {
          toast.success(
            t(
              "admin_tenant_detail_page.delete_done",
              ":name was deleted. Its records are kept and it can be restored.",
              { name: tenant.name },
            ),
          );
          close(false);
        },
        onError: (err: unknown) => {
          const data = (
            err as {
              response?: {
                data?: { errors?: Record<string, string[]>; type?: string };
              };
            }
          ).response?.data;
          const errors: Record<string, string> = {};
          for (const [field, messages] of Object.entries(data?.errors ?? {})) {
            if (messages[0]) errors[field] = messages[0];
          }
          if (data?.type === "https://ethr.et/errors/invalid-mfa-code") {
            errors.code =
              apiErrorDetail(err) ??
              t("admin_tenant_detail_page.delete_code_invalid", "Invalid code");
            setCode("");
          }
          if (Object.keys(errors).length > 0) {
            setFieldErrors(errors);
            return;
          }
          toast.error(
            apiErrorDetail(err) ??
              t(
                "admin_tenant_detail_page.delete_failed",
                "Couldn't delete this organisation",
              ),
          );
        },
      },
    );
  }

  return (
    <Dialog open={open} onOpenChange={close}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
        <form onSubmit={submit} className="space-y-4">
          <DialogHeader>
            <DialogTitle>
              {t("admin_tenant_detail_page.delete_title", "Delete :name?", {
                name: tenant.name,
              })}
            </DialogTitle>
            <DialogDescription>
              {t(
                "admin_tenant_detail_page.delete_description",
                "This closes the organisation. It does not erase its records.",
              )}
            </DialogDescription>
          </DialogHeader>

          <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-3">
            <div className="flex items-start gap-2">
              <AlertTriangle
                className="mt-0.5 h-4 w-4 shrink-0 text-destructive"
                aria-hidden
              />
              <ul className="space-y-1.5 text-sm text-foreground">
                <li>
                  {t(
                    "admin_tenant_detail_page.delete_effect_signout",
                    "Everyone at :name is signed out now and cannot sign in.",
                    { name: tenant.name },
                  )}
                </li>
                <li>
                  {t(
                    "admin_tenant_detail_page.delete_effect_address",
                    ":address stops working, and stays reserved so no one else can take it.",
                    { address },
                  )}
                </li>
                <li>
                  {t(
                    "admin_tenant_detail_page.delete_effect_kept",
                    "Employee, payroll, attendance and audit records are kept. You can restore the organisation from the Deleted list.",
                  )}
                </li>
              </ul>
            </div>
            <p className="mt-3 text-xs text-muted-foreground">
              {t(
                "admin_tenant_detail_page.delete_backup_hint",
                "If the organisation may ask for a copy of its data, use Backup Data first.",
              )}
            </p>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="delete-reason">
              {t("admin_tenant_detail_page.delete_reason_label", "Reason")}
            </Label>
            <Textarea
              id="delete-reason"
              rows={2}
              maxLength={500}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder={t(
                "admin_tenant_detail_page.delete_reason_placeholder",
                "For example: duplicate registration, or the organisation asked to close its account",
              )}
              aria-invalid={fieldErrors.reason ? true : undefined}
            />
            <p className="text-xs text-muted-foreground">
              {t(
                "admin_tenant_detail_page.delete_reason_hint",
                "Recorded in the audit log with your name.",
              )}
            </p>
            {fieldErrors.reason && (
              <p className="text-xs text-destructive">{fieldErrors.reason}</p>
            )}
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="delete-code">
              {t("admin_tenant_detail_page.mfa_code_label")}
            </Label>
            <Input
              id="delete-code"
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={6}
              value={code}
              onChange={(e) => setCode(e.target.value.replace(/\D/g, ""))}
              placeholder="000000"
              aria-invalid={fieldErrors.code ? true : undefined}
            />
            {fieldErrors.code && (
              <p className="text-xs text-destructive">{fieldErrors.code}</p>
            )}
          </div>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => close(false)}
              disabled={remove.isPending}
            >
              {t("admin_tenant_detail_page.delete_keep", "Keep organisation")}
            </Button>
            <Button
              type="submit"
              variant="destructive"
              disabled={!ready || remove.isPending}
            >
              {remove.isPending && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              {t(
                "admin_tenant_detail_page.delete_organisation",
                "Delete organisation",
              )}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
