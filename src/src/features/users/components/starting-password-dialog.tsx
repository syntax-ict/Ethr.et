"use client";

import { useState } from "react";
import { KeyRound, Loader2, Printer } from "lucide-react";
import { Button } from "@/components/ui/button";
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
  STARTING_PASSWORD_BATCH,
  STARTING_PASSWORD_HOURS,
  useIssueStartingPassword,
} from "@/features/users/starting-password";
import { apiErrorMessage } from "@/lib/api/error-message";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";

/**
 * Give people with no email one starting password (owner decision
 * 2026-11-10).
 *
 * ETHR makes the password; HR never types it. HR confirms with their own
 * password, sees the starting password once on a sheet they can print, and
 * hands it out with each person's employee code. Everyone must choose their
 * own password at first sign-in, and an unused one stops working after 72
 * hours. The password is held in the mutation result only and dropped when
 * the dialog closes, so it cannot be read back.
 *
 * The in-person code stays the safer way and is said so here: until a person
 * first signs in, anyone who knows the starting password and their employee
 * number could sign in as them.
 */
export function StartingPasswordDialog({
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
  const [password, setPassword] = useState("");
  const [mfaCode, setMfaCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const issue = useIssueStartingPassword();

  // Kept on screen while open: issuing takes these people off the caller's
  // list, and the sheet with the password must outlive that.
  if (
    !hasPermission("users.issueStartingPassword") ||
    (employeeIds.length === 0 && !open)
  ) {
    return null;
  }

  const batch = employeeIds.slice(0, STARTING_PASSWORD_BATCH);
  const issued = issue.data;

  function close(next: boolean) {
    setOpen(next);
    if (!next) {
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
              t(
                "starting_password.failed",
                "Could not give a starting password",
              ),
            ),
          ),
      },
    );
  }

  const leftOut = issued
    ? issued.skipped.active +
      issued.skipped.role +
      issued.skipped.closed +
      issued.skipped.reachable
    : 0;

  return (
    <>
      <Button variant="outline" onClick={() => setOpen(true)}>
        <KeyRound className="mr-2 h-4 w-4" />
        {t("starting_password.button", "Give a starting password")}
      </Button>

      <Dialog open={open} onOpenChange={close}>
        <DialogContent
          className={
            issued
              ? "print-surface max-h-[85vh] max-w-lg overflow-y-auto"
              : "max-w-md"
          }
        >
          <DialogHeader>
            <DialogTitle>
              {issued
                ? t("starting_password.sheet_title", "Starting password")
                : t(
                    "starting_password.title",
                    "Give :count people a starting password",
                    { count: batch.length },
                  )}
            </DialogTitle>
            <DialogDescription>
              {issued
                ? t(
                    "starting_password.sheet_desc",
                    "This is shown once and cannot be read back. Each person signs in with their employee code and this password, then must choose their own.",
                  )
                : t(
                    "starting_password.description",
                    "For people with no email. ETHR makes one password for all of them. Each person signs in with their employee code and must choose their own password first.",
                  )}
            </DialogDescription>
          </DialogHeader>

          {issued ? (
            <div className="space-y-4">
              <div className="rounded-md border border-border-default p-4 text-center">
                <p className="text-xs text-muted-foreground">
                  {t("starting_password.password_label", "Password")}
                </p>
                <p
                  className="mt-1 break-all font-mono text-2xl font-semibold tracking-wider text-foreground"
                  data-testid="starting-password"
                >
                  {issued.password}
                </p>
              </div>

              <p
                role="note"
                className="rounded-md bg-warning-soft p-3 text-sm text-foreground"
              >
                {t(
                  "starting_password.expiry_warning",
                  "It stops working after :hours hours, at :time. Anyone who has not signed in by then goes back to not being able to sign in, and needs a new invitation or an in-person code.",
                  {
                    hours: STARTING_PASSWORD_HOURS,
                    time: formatDateTime(issued.expires_at),
                  },
                )}
              </p>

              <div>
                <p className="text-sm font-medium text-foreground">
                  {t(
                    "starting_password.people_title",
                    "Given to :count people",
                    { count: issued.issued },
                  )}
                </p>
                <table className="mt-2 w-full text-left text-sm">
                  <thead>
                    <tr className="border-b border-border-default text-xs text-muted-foreground">
                      <th scope="col" className="py-1 pr-3 font-medium">
                        {t("starting_password.person", "Person")}
                      </th>
                      <th scope="col" className="py-1 font-medium">
                        {t("starting_password.employee_code", "Employee code")}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {issued.people.map((person) => (
                      <tr
                        key={person.employee_public_id}
                        className="border-b border-border-default last:border-0"
                      >
                        <td className="py-1 pr-3">{person.name}</td>
                        <td className="py-1 font-mono">
                          {person.employee_code}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {leftOut > 0 && (
                <p className="text-xs text-muted-foreground">
                  {t(
                    "starting_password.left_out",
                    "Left as they were: :count. They can already sign in, can be invited by email or text, or have a role above employee.",
                    { count: leftOut },
                  )}
                </p>
              )}

              <p className="no-print text-xs text-muted-foreground">
                {t(
                  "starting_password.handling",
                  "Hand it out in person. Do not post it on a notice board or send it in a group message.",
                )}
              </p>

              <DialogFooter className="no-print gap-2 sm:justify-between">
                <Button variant="outline" onClick={() => window.print()}>
                  <Printer className="mr-2 h-4 w-4" />
                  {t("common.print", "Print")}
                </Button>
                <Button onClick={() => close(false)}>
                  {t("starting_password.done", "Done")}
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
              <p
                role="note"
                className="rounded-md bg-warning-soft p-3 text-sm text-foreground"
              >
                {t(
                  "starting_password.risk",
                  "Until a person first signs in, anyone who knows this password and their employee code could sign in as them. An in-person code from the person's profile does not have that risk.",
                )}
              </p>

              {employeeIds.length > batch.length && (
                <p className="text-xs text-muted-foreground">
                  {t(
                    "starting_password.batch_limit",
                    "One password covers :max people at a time. The other :rest stay on the list for the next one.",
                    {
                      max: STARTING_PASSWORD_BATCH,
                      rest: employeeIds.length - batch.length,
                    },
                  )}
                </p>
              )}

              <div className="space-y-1.5">
                <Label htmlFor="starting-password-own">
                  {t("activation_code.password", "Your password")}
                </Label>
                <Input
                  id="starting-password-own"
                  type="password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  autoComplete="current-password"
                  required
                />
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="starting-password-mfa">
                  {t(
                    "activation_code.mfa",
                    "Your authenticator code, if you use one",
                  )}
                </Label>
                <Input
                  id="starting-password-mfa"
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
                <Button type="submit" disabled={issue.isPending || !password}>
                  {issue.isPending ? (
                    <>
                      <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                      {t("starting_password.issuing", "Working…")}
                    </>
                  ) : (
                    t("starting_password.issue", "Make the password")
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
