"use client";

import { useRef, useState } from "react";
import { Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { useCreateEmployeeLogins } from "@/features/onboarding/v2/api";
import { ActivationCodeSheetDialog } from "@/features/users/components/activation-code-sheet-dialog";
import { StartingPasswordDialog } from "@/features/users/components/starting-password-dialog";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";
import { needsSignIn, type SignInPerson } from "../sign-in";
import {
  addTotals,
  NO_TOTALS,
  sendInSlices,
  type LoginTotals,
} from "../send-in-slices";

interface GiveSignInDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  selectedIds: string[];
  /** The rows on screen, to tell who has no email and no phone. */
  people: SignInPerson[];
  /** Called when the dialog closes after every selected person was sent. */
  onComplete: () => void;
}

/**
 * Give the selected people a way to sign in: a login for each who has none,
 * and an invitation queued for each who can be reached. Someone who can
 * already sign in is left alone by the server.
 *
 * The ids go up in slices of 200, one after another. A slice that fails stops
 * the run, says how many went, and keeps the ids not yet sent for a retry.
 */
export function GiveSignInDialog({
  open,
  onOpenChange,
  selectedIds,
  people,
  onComplete,
}: GiveSignInDialogProps) {
  const { t } = useT();
  const create = useCreateEmployeeLogins();
  // `create.isPending` only knows the request in flight; a second press can
  // land between two slices, and a second run would post people twice.
  const running = useRef(false);
  const [busy, setBusy] = useState(false);
  const [totals, setTotals] = useState<LoginTotals>(NO_TOTALS);
  const [sent, setSent] = useState(0);
  const [unsent, setUnsent] = useState<string[]>([]);
  const [failure, setFailure] = useState<string | null>(null);
  const [finished, setFinished] = useState(false);

  // Only current staff the list shows with no way in yet: the server would
  // also make a login for a leaver or for someone not on screen, so the
  // choice is made here. Anyone else in the selection is left out.
  const eligible = people.filter(
    (p) => selectedIds.includes(p.public_id) && needsSignIn(p),
  );
  const eligibleIds = eligible.map((p) => p.public_id);
  const leftOut = selectedIds.length - eligibleIds.length;
  // Only people with no email and no phone: the code sheet and the starting
  // password refuse anyone else.
  const inPerson = eligible
    .filter((p) => !p.email?.trim() && !p.phone?.trim())
    .map((p) => p.public_id);

  async function run() {
    if (running.current) return;
    running.current = true;
    setBusy(true);
    setFailure(null);
    const ids = unsent.length > 0 ? unsent : eligibleIds;

    try {
      const outcome = await sendInSlices(ids, (slice) =>
        create.mutateAsync(slice),
      );
      setTotals((prev) => addTotals(prev, outcome.results));
      setSent((prev) => prev + outcome.sent);
      setUnsent(outcome.unsent);

      if (outcome.error !== null) {
        setFailure(
          apiErrorMessage(
            outcome.error,
            t("common.error", "Something went wrong"),
          ),
        );
      } else {
        setFinished(true);
      }
    } finally {
      running.current = false;
      setBusy(false);
    }
  }

  function close(next: boolean) {
    if (!next && busy) return;
    onOpenChange(next);
    if (next) return;

    const wasFinished = finished;
    // A partial run changed people too: the list must refetch.
    const wasPartial = sent > 0;
    setTotals(NO_TOTALS);
    setSent(0);
    setUnsent([]);
    setFailure(null);
    setFinished(false);
    if (wasFinished || wasPartial) onComplete();
  }

  return (
    <Dialog open={open} onOpenChange={close}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {t("employees.bulk.sign_in.title", "Give a way to sign in")}
          </DialogTitle>
          <DialogDescription>
            {t(
              "employees.bulk.sign_in.description",
              "People with an email get an invitation by email, and people with only a phone get a text message if texts are on. People with neither are listed for a printed code, and people who can already sign in are left alone.",
            )}
          </DialogDescription>
        </DialogHeader>

        {!finished && failure === null && (
          <p className="text-sm text-muted-foreground">
            {eligibleIds.length > 0
              ? t(
                  "employees.bulk.sign_in.count",
                  "This covers :count selected people.",
                  { count: eligibleIds.length },
                )
              : t(
                  "employees.bulk.sign_in.nobody",
                  "Nobody in the selection needs a way to sign in.",
                )}
          </p>
        )}

        {!finished && leftOut > 0 && (
          <p className="text-sm text-muted-foreground">
            {t(
              "employees.bulk.sign_in.left_out",
              ":count of the selected people can already sign in or no longer work here, and are left out.",
              { count: leftOut },
            )}
          </p>
        )}

        {failure !== null && (
          <p role="alert" className="text-sm text-destructive">
            {t(
              "employees.bulk.sign_in.partial",
              "Sent :sent of :total. :left not sent yet. :reason",
              {
                sent,
                total: sent + unsent.length,
                left: unsent.length,
                reason: failure,
              },
            )}
          </p>
        )}

        {finished && (
          <div className="space-y-3">
            <p className="text-sm">
              {t(
                "employees.bulk.sign_in.result",
                "Logins created: :created. Invitations queued: :queued. Already able to sign in: :active. Need a code in person: :in_person. Email already used by another login: :email_in_use.",
                { ...totals },
              )}
            </p>
            {totals.in_person > 0 && inPerson.length > 0 && (
              <div className="space-y-2">
                <p className="text-sm text-muted-foreground">
                  {t(
                    "employees.bulk.sign_in.in_person",
                    "Some people have no email and no phone. Print them a code, or give them a starting password.",
                  )}
                </p>
                <div className="flex flex-wrap gap-2">
                  <ActivationCodeSheetDialog employeeIds={inPerson} />
                  <StartingPasswordDialog employeeIds={inPerson} />
                </div>
              </div>
            )}
          </div>
        )}

        <DialogFooter>
          {finished ? (
            <Button onClick={() => close(false)}>
              {t("common.done", "Done")}
            </Button>
          ) : (
            <>
              <Button
                variant="outline"
                onClick={() => close(false)}
                disabled={busy}
              >
                {t("common.cancel", "Cancel")}
              </Button>
              <Button
                onClick={run}
                disabled={
                  busy || (unsent.length === 0 && eligibleIds.length === 0)
                }
              >
                {busy && <Loader2 className="mr-2 h-3.5 w-3.5 animate-spin" />}
                {failure !== null
                  ? t("employees.bulk.sign_in.retry", "Send the rest")
                  : t("common.confirm", "Confirm")}
              </Button>
            </>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
