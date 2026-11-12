"use client";

import { useId, useState } from "react";
import Link from "next/link";
import { Loader2, Printer, QrCode } from "lucide-react";
import { QRCodeSVG } from "qrcode.react";
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
  CODE_SHEET_HOURS,
  CODE_SHEET_MAX,
  useIssueActivationCodes,
} from "@/features/users/activation-code-sheet";
import { apiErrorMessage } from "@/lib/api/error-message";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";

/**
 * A sheet of activation codes for people no invitation can reach (owner
 * decision 2026-11-10).
 *
 * HR confirms with their own password and undertakes to check each person's
 * identity when their slip is handed over. ETHR makes one code per person
 * and shows them once, as slips to print and cut: the person's name and
 * employee code, a QR, and the code to type. Each person redeems their own
 * on their own phone and chooses their own password, so unlike one shared
 * starting password, a slip is no use to anybody else once it is handed to
 * the right person.
 *
 * The codes are held in the mutation result only and dropped when the dialog
 * closes. They cannot be read back; another sheet withdraws them.
 */
export function ActivationCodeSheetDialog({
  employeeIds,
  onIssued,
}: {
  /** The people an invitation cannot reach. */
  employeeIds: string[];
  onIssued?: () => void;
}) {
  const { t } = useT();
  const { formatDateTime } = useDateFormatters();
  const { hasPermission } = usePermissions();
  const [open, setOpen] = useState(false);
  const [attested, setAttested] = useState(false);
  const [hours, setHours] = useState<number>(CODE_SHEET_HOURS[0]);
  const [password, setPassword] = useState("");
  const [mfaCode, setMfaCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const issue = useIssueActivationCodes();
  const id = useId();

  // Kept on screen while open: issuing takes these people off the caller's
  // list, and the sheet with the codes must outlive that.
  if (
    !hasPermission("users.issueActivationCode") ||
    (employeeIds.length === 0 && !open)
  ) {
    return null;
  }

  const batch = employeeIds.slice(0, CODE_SHEET_MAX);
  const sheet = issue.data;

  function close(next: boolean) {
    setOpen(next);
    if (!next) {
      setAttested(false);
      setPassword("");
      setMfaCode("");
      setError(null);
      issue.reset();
    }
  }

  function submit() {
    setError(null);
    issue.mutate(
      {
        employee_ids: batch,
        attested: true,
        hours,
        current_password: password,
        mfa_code: mfaCode.trim() || undefined,
      },
      {
        onSuccess: () => {
          // The HR password is not needed once it has been checked.
          setPassword("");
          setMfaCode("");
          onIssued?.();
        },
        onError: (err) =>
          setError(
            apiErrorMessage(
              err,
              t("code_sheet.failed", "Could not make the code sheet"),
            ),
          ),
      },
    );
  }

  return (
    <>
      <Button variant="outline" onClick={() => setOpen(true)}>
        <QrCode className="mr-2 h-4 w-4" />
        {t("code_sheet.button", "Print a code sheet")}
      </Button>

      <Dialog open={open} onOpenChange={close}>
        <DialogContent
          className={
            sheet
              ? "print-surface max-h-[85vh] max-w-3xl overflow-y-auto"
              : "max-w-md"
          }
        >
          <DialogHeader>
            <DialogTitle>
              {sheet
                ? t("code_sheet.sheet_title", "Activation codes")
                : t("code_sheet.title", "Activation codes for :count people", {
                    count: batch.length,
                  })}
            </DialogTitle>
            <DialogDescription>
              {sheet && sheet.issued === 0
                ? t(
                    "code_sheet.none_made",
                    "No codes were made. Each person below has the reason beside their name.",
                  )
                : sheet
                  ? t(
                      "code_sheet.sheet_desc",
                      "These are shown once and cannot be read back. Print the sheet, cut it into slips, and hand each person their own after checking who they are.",
                    )
                  : t(
                      "code_sheet.description",
                      "For people with no email. ETHR makes one code for each person. They scan or type it on their own phone and choose their own password.",
                    )}
            </DialogDescription>
          </DialogHeader>

          {sheet ? (
            <div className="space-y-4">
              {sheet.issued > 0 && (
                <p
                  role="note"
                  className="rounded-md bg-warning-soft p-3 text-sm text-foreground"
                >
                  {t(
                    "code_sheet.expiry",
                    "Each code works once, until :time. Anyone who has not used theirs by then needs a new one.",
                    { time: formatDateTime(sheet.expires_at) },
                  )}
                </p>
              )}

              <ul
                className="grid grid-cols-1 gap-3 sm:grid-cols-2"
                aria-label={t("code_sheet.sheet_title", "Activation codes")}
              >
                {sheet.codes.map((slip) => (
                  <li
                    key={slip.employee_public_id}
                    className="flex break-inside-avoid items-center gap-3 rounded-md border border-dashed border-border-strong p-3"
                  >
                    <div className="shrink-0 rounded bg-white p-1">
                      <QRCodeSVG
                        value={slip.activation_url}
                        size={96}
                        level="M"
                        title={t(
                          "activation_code.qr_title",
                          "Activation QR code",
                        )}
                      />
                    </div>
                    <div className="min-w-0 text-sm">
                      <p className="truncate font-medium text-foreground">
                        {slip.name}
                      </p>
                      <p className="text-xs text-muted-foreground">
                        {t("code_sheet.employee_code", "Employee code")}{" "}
                        <span className="font-mono text-foreground">
                          {slip.employee_code}
                        </span>
                      </p>
                      <p
                        className="mt-1 font-mono text-base font-semibold tracking-wider text-foreground"
                        data-testid="sheet-code"
                      >
                        {slip.code}
                      </p>
                      <p className="mt-1 text-xs text-muted-foreground">
                        {t(
                          "code_sheet.slip_hint",
                          "Scan the code, or open the sign-in page and type it with your employee code. Then choose your own password.",
                        )}
                      </p>
                    </div>
                  </li>
                ))}
              </ul>

              {sheet.refused.length > 0 && (
                <div className="no-print space-y-1">
                  <p className="text-sm font-medium text-foreground">
                    {t("code_sheet.refused_title", "No code for :count", {
                      count: sheet.refused.length,
                    })}
                  </p>
                  <ul className="space-y-1 text-xs text-muted-foreground">
                    {sheet.refused.map((person) => (
                      <li key={person.employee_public_id}>
                        <span className="font-medium text-foreground">
                          {person.name}
                        </span>
                        {": "}
                        {person.detail}
                      </li>
                    ))}
                  </ul>
                  {sheet.refused.some((p) => p.reason === "identifier") && (
                    <p className="text-xs">
                      <Link
                        href="/setup/guided"
                        className="text-primary underline-offset-2 hover:underline"
                      >
                        {t(
                          "code_sheet.open_access",
                          "Open the guided setup and turn on employee code sign-in under Access",
                        )}
                      </Link>
                    </p>
                  )}
                </div>
              )}

              {sheet.issued > 0 && (
                <p className="no-print text-xs text-muted-foreground">
                  {t(
                    "code_sheet.handling",
                    "Hand each slip to the person named on it, after checking their identity document. Do not leave the sheet where others can read it, and shred what is left over.",
                  )}
                </p>
              )}

              <DialogFooter className="no-print gap-2 sm:justify-between">
                <Button
                  variant="outline"
                  onClick={() => window.print()}
                  disabled={sheet.issued === 0}
                >
                  <Printer className="mr-2 h-4 w-4" />
                  {t("common.print", "Print")}
                </Button>
                <Button onClick={() => close(false)}>
                  {t("code_sheet.done", "Done")}
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
              {employeeIds.length > batch.length && (
                <p className="text-xs text-muted-foreground">
                  {t(
                    "code_sheet.first_batch",
                    "One sheet covers :max people. This one is for the first :max; make another for the rest.",
                    { max: CODE_SHEET_MAX },
                  )}
                </p>
              )}

              <div className="space-y-1.5">
                <Label htmlFor={`${id}-hours`}>
                  {t("code_sheet.hours", "The codes work for")}
                </Label>
                <select
                  id={`${id}-hours`}
                  value={hours}
                  onChange={(e) => setHours(Number(e.target.value))}
                  className="h-9 w-full rounded-md border border-border-default bg-background px-3 text-sm"
                >
                  <option value={CODE_SHEET_HOURS[0]}>
                    {t("code_sheet.hours_3_days", "3 days")}
                  </option>
                  <option value={CODE_SHEET_HOURS[1]}>
                    {t("code_sheet.hours_7_days", "7 days")}
                  </option>
                </select>
              </div>

              <div className="flex items-start gap-2">
                <Checkbox
                  id={`${id}-attested`}
                  checked={attested}
                  onCheckedChange={(value) => setAttested(value === true)}
                />
                <Label
                  htmlFor={`${id}-attested`}
                  className="text-sm font-normal leading-snug"
                >
                  {t(
                    "code_sheet.attest",
                    "I will check each person's identity document before I hand them their slip.",
                  )}
                </Label>
              </div>

              <div className="space-y-1.5">
                <Label htmlFor={`${id}-password`}>
                  {t("code_sheet.your_password", "Your password")}
                </Label>
                <Input
                  id={`${id}-password`}
                  type="password"
                  autoComplete="current-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  required
                />
              </div>

              <div className="space-y-1.5">
                <Label htmlFor={`${id}-mfa`}>
                  {t(
                    "code_sheet.mfa",
                    "Authenticator code, if you use two-step sign-in",
                  )}
                </Label>
                <Input
                  id={`${id}-mfa`}
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  value={mfaCode}
                  onChange={(e) => setMfaCode(e.target.value)}
                />
              </div>

              {error && (
                <p role="alert" className="text-sm text-destructive">
                  {error}
                </p>
              )}

              <DialogFooter className="gap-2">
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => close(false)}
                >
                  {t("common.cancel", "Cancel")}
                </Button>
                <Button
                  type="submit"
                  disabled={
                    issue.isPending || !attested || password.trim() === ""
                  }
                >
                  {issue.isPending && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  {t("code_sheet.submit", "Make the codes")}
                </Button>
              </DialogFooter>
            </form>
          )}
        </DialogContent>
      </Dialog>
    </>
  );
}
