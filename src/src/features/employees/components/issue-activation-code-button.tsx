"use client";

import { useState } from "react";
import { Loader2, QrCode } from "lucide-react";
import { QRCodeSVG } from "qrcode.react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  ACTIVATION_DOCUMENTS,
  useActivationStatus,
  useIssueActivationCode,
  useRevokeActivationCode,
  type ActivationDocument,
} from "@/features/employees/activation-code";
import { apiErrorMessage } from "@/lib/api/error-message";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";

/**
 * Activate, or recover, an employee who has no email and no phone, face to face.
 *
 * Nothing can be sent to them, so HR checks an identity document in person and
 * issues a one-time code, shown here once as a QR and as typed characters. The
 * employee redeems it on their own device and chooses their own password; HR
 * never sees it. The code is held in the mutation result only, and is dropped
 * when the dialog closes, so it cannot be read back.
 */
export function IssueActivationCodeButton({
  employeeId,
  employeeName,
  email,
  phone,
}: {
  employeeId: string;
  employeeName: string;
  email: string | null | undefined;
  phone: string | null | undefined;
}) {
  const { t } = useT();
  const { hasPermission } = usePermissions();
  const [open, setOpen] = useState(false);
  const [attested, setAttested] = useState(false);
  const [docType, setDocType] = useState<ActivationDocument | "">("");
  const [note, setNote] = useState("");
  const [password, setPassword] = useState("");
  const [mfaCode, setMfaCode] = useState("");
  const [error, setError] = useState<string | null>(null);

  const allowed = !email && hasPermission("users.issueActivationCode");
  // With a phone, the server decides: it counts only once texts can reach it.
  const status = useActivationStatus(
    employeeId,
    allowed && (open || Boolean(phone)),
  );
  const issue = useIssueActivationCode(employeeId);
  const revoke = useRevokeActivationCode(employeeId);

  if (!allowed || (phone && status.data?.reachable !== false)) {
    return null;
  }

  const recovering = status.data?.login_status === "active";
  const issued = issue.data;

  function reset() {
    setAttested(false);
    setDocType("");
    setNote("");
    setPassword("");
    setMfaCode("");
    setError(null);
    issue.reset();
  }

  function close(next: boolean) {
    setOpen(next);
    if (!next) reset();
  }

  function submit() {
    if (!docType) return;
    setError(null);
    issue.mutate(
      {
        document_type: docType,
        note: note.trim() || undefined,
        current_password: password,
        mfa_code: mfaCode.trim() || undefined,
      },
      {
        // The password is not needed once it has been checked.
        onSuccess: () => setPassword(""),
        onError: (err) =>
          setError(
            apiErrorMessage(
              err,
              t("activation_code.failed", "Could not issue the code"),
            ),
          ),
      },
    );
  }

  function withdraw() {
    revoke.mutate(undefined, {
      onSuccess: () => {
        toast.success(t("activation_code.revoked", "Code withdrawn"));
        close(false);
      },
      onError: (err) =>
        toast.error(
          apiErrorMessage(
            err,
            t("activation_code.revoke_failed", "Could not withdraw the code"),
          ),
        ),
    });
  }

  const documentLabel: Record<ActivationDocument, string> = {
    national_id: t("activation_code.doc_national_id", "National ID"),
    kebele_id: t("activation_code.doc_kebele_id", "Kebele ID"),
    passport: t("activation_code.doc_passport", "Passport"),
    driving_licence: t(
      "activation_code.doc_driving_licence",
      "Driving licence",
    ),
    employer_id: t("activation_code.doc_employer_id", "Employer ID"),
  };

  return (
    <>
      <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
        <QrCode className="mr-2 h-3 w-3" />
        {t("activation_code.button", "Activate in person")}
      </Button>

      <Dialog open={open} onOpenChange={close}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>
              {recovering
                ? t(
                    "activation_code.title_recover",
                    "Reset sign-in for :name in person",
                    { name: employeeName },
                  )
                : t("activation_code.title", "Activate :name in person", {
                    name: employeeName,
                  })}
            </DialogTitle>
            <DialogDescription>
              {t(
                "activation_code.description",
                "Nothing can be sent to this person: they have no email, and invitations are not sent by text. Check their identity document in person first. The code is shown once and cannot be read back.",
              )}
            </DialogDescription>
          </DialogHeader>

          {issued ? (
            <div className="space-y-4">
              <div className="flex justify-center rounded-md border border-border-default bg-white p-4">
                <QRCodeSVG
                  value={issued.activation_url}
                  size={208}
                  level="M"
                  title={t("activation_code.qr_title", "Activation QR code")}
                />
              </div>
              <div className="text-center">
                <p className="text-xs text-muted-foreground">
                  {t("activation_code.code_label", "Or type this code")}
                </p>
                <p
                  className="mt-1 font-mono text-2xl font-semibold tracking-widest text-foreground"
                  data-testid="activation-code"
                >
                  {issued.code}
                </p>
              </div>
              <p className="text-xs text-muted-foreground">
                {t(
                  "activation_code.code_hint",
                  "They scan the QR code on their own phone, or open the sign-in page and type the code with their employee code :code. Let them type their own password. Do not photograph or save this screen.",
                  { code: issued.employee_code ?? "" },
                )}
              </p>
              <p className="text-xs text-muted-foreground">
                {t("activation_code.expires", "Expires :time", {
                  time: new Date(issued.expires_at).toLocaleString(),
                })}
              </p>
              <DialogFooter className="gap-2 sm:justify-between">
                <Button
                  variant="outline"
                  onClick={withdraw}
                  disabled={revoke.isPending}
                >
                  {t("activation_code.revoke", "Withdraw code")}
                </Button>
                <Button onClick={() => close(false)}>
                  {t("activation_code.done", "Done")}
                </Button>
              </DialogFooter>
            </div>
          ) : (
            <form
              className="space-y-4"
              onSubmit={(e) => {
                e.preventDefault();
                submit();
              }}
            >
              {status.data?.state === "live" && (
                <p className="rounded-md bg-warning-soft p-3 text-xs text-foreground">
                  {t(
                    "activation_code.live_exists",
                    "A code is already live. Issuing a new one withdraws it.",
                  )}
                </p>
              )}

              <div className="space-y-1.5">
                <Label htmlFor="activation-document">
                  {t("activation_code.document", "Document checked")}
                </Label>
                <select
                  id="activation-document"
                  value={docType}
                  onChange={(e) =>
                    setDocType(e.target.value as ActivationDocument | "")
                  }
                  className="h-9 w-full rounded-md border border-border-default bg-background px-3 text-sm"
                  required
                >
                  <option value="">
                    {t("activation_code.document_choose", "Choose one")}
                  </option>
                  {ACTIVATION_DOCUMENTS.map((d) => (
                    <option key={d} value={d}>
                      {documentLabel[d]}
                    </option>
                  ))}
                </select>
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="activation-note">
                  {t("activation_code.note", "Note (optional)")}
                </Label>
                <Input
                  id="activation-note"
                  value={note}
                  maxLength={255}
                  onChange={(e) => setNote(e.target.value)}
                  autoComplete="off"
                />
              </div>

              <div className="flex items-start gap-3">
                <Checkbox
                  id="activation-attest"
                  checked={attested}
                  onCheckedChange={(v) => setAttested(v === true)}
                />
                <Label
                  htmlFor="activation-attest"
                  className="text-sm font-normal"
                >
                  {t(
                    "activation_code.attest",
                    "I checked this person's identity document in person",
                  )}
                </Label>
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="activation-password">
                  {t("activation_code.password", "Your password")}
                </Label>
                <Input
                  id="activation-password"
                  type="password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  autoComplete="current-password"
                  required
                />
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="activation-mfa">
                  {t(
                    "activation_code.mfa",
                    "Your authenticator code, if you use one",
                  )}
                </Label>
                <Input
                  id="activation-mfa"
                  value={mfaCode}
                  inputMode="numeric"
                  maxLength={12}
                  onChange={(e) => setMfaCode(e.target.value)}
                  autoComplete="one-time-code"
                />
              </div>

              {error && (
                <p role="alert" className="text-sm text-destructive">
                  {error}
                </p>
              )}

              <DialogFooter>
                <Button
                  type="submit"
                  disabled={
                    issue.isPending || !attested || !docType || !password
                  }
                >
                  {issue.isPending ? (
                    <>
                      <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                      {t("activation_code.issuing", "Issuing…")}
                    </>
                  ) : (
                    t("activation_code.issue", "Issue code")
                  )}
                </Button>
              </DialogFooter>
            </form>
          )}
        </DialogContent>
      </Dialog>
    </>
  );
}
