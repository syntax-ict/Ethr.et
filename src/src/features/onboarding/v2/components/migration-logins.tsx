"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { KeyRound, Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { SimpleTable } from "@/components/shared/simple-table";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { InvitationProgress } from "@/features/users/components/invitation-progress";
import { ActivationCodeSheetDialog } from "@/features/users/components/activation-code-sheet-dialog";
import { StartingPasswordDialog } from "@/features/users/components/starting-password-dialog";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { toastError } from "@/lib/errors";
import { useT } from "@/lib/i18n/useT";
import { useCreateEmployeeLogins, useMigrationLogins } from "../api";
import type { CreateLoginsResult, PendingLogin } from "../types";

// The server accepts 1,000 ids a request, but it creates every login inside
// the request, and its own comment only claims a few hundred fit the host's 30
// seconds. A list of 2,000 people got a 422 on the automatic send (2026-11-16),
// so the browser sends in slices, one after another.
const INVITE_SLICE = 200;

/**
 * After a migration commits: the people who cannot sign in yet, how each would
 * be invited, and one button that gives them all a way in. Also the owner's
 * hire-date warning (2026-10-28): the date was set to today, and is HR's to
 * correct.
 *
 * Logins are on by default where a list is brought into a running
 * organisation (owner decision 2026-11-10): with `autoSend`, the people an
 * invitation can reach are invited as soon as the list is added, once,
 * without a second button. The reviewer said so before adding the list and can
 * untick it there: names read off a terminal are often wrong ("guest card",
 * "test"), and someone bringing such a list in may want to look first. In the
 * guided setup the tick starts unticked (owner decision 2026-11-16), so
 * nobody is invited before the organisation has chosen how people sign in.
 * People no invitation can reach get a printed code each, or one starting
 * password between them.
 *
 * A list can say it per person, in its sign-in column, and a row outranks the
 * tick: a person it marks yes is invited even with the tick off, and one it
 * marks no is not invited by either the tick or the button. They stay in the
 * table, and HR can still invite them from their profile.
 *
 * @param autoSend Invite the reachable people as soon as the list is read.
 * @param inviteMarked Invite the people the list marks yes, tick or no tick.
 *   Only for the screen that has just added the list: a list opened again
 *   later must not send anything by itself.
 * @param undone The list's additions were undone: nobody it added is waiting
 *   for a way in, and "can already sign in" would not be true.
 */
export function MigrationLogins({
  batchId,
  onContinue,
  autoSend = false,
  inviteMarked = false,
  undone = false,
}: {
  batchId: string;
  onContinue?: () => void;
  autoSend?: boolean;
  inviteMarked?: boolean;
  undone?: boolean;
}) {
  const { t } = useT();
  const { can } = usePermissions();
  const query = useMigrationLogins(batchId);
  const create = useCreateEmployeeLogins();
  const [result, setResult] = useState<CreateLoginsResult | null>(null);
  // One invitation batch per slice that queued anything.
  const [invitationBatches, setInvitationBatches] = useState<string[]>([]);
  // The ids a failed slice and the ones after it left unsent.
  const [unsent, setUnsent] = useState<string[]>([]);
  // The ids this card has posted successfully. Someone whose email another
  // login already uses stays "not invited" on the server for good, so what was
  // posted once must not keep the button alive.
  const [posted, setPosted] = useState<Set<string>>(new Set());

  const pending = query.data?.without_login ?? [];
  const defaulted = query.data?.hire_dates_defaulted ?? 0;
  // The people no invitation can reach: the ones a starting password is for.
  // Someone the list says no to is not handed a code either.
  const inPerson = pending
    .filter((p) => p.channel === "in_person" && p.asked !== false)
    .map((p) => p.employee_public_id);

  // Invited by themselves, once: the people an invitation can reach who
  // have not been sent one. A second render, or a second look at the card,
  // sends nothing more.
  const sentOnce = useRef(false);
  const toInvite = pending
    .filter(
      (p) =>
        p.channel !== "in_person" &&
        !p.invited &&
        p.asked !== false &&
        (autoSend || (inviteMarked && p.asked === true)),
    )
    .map((p) => p.employee_public_id);
  // What the button sends: everyone but the people the list says not to.
  const sendable = pending.filter((p) => p.asked !== false);
  // Reachable, not yet invited, and not posted from here: a blank cell in a
  // list that only marked some people is left for the button after the
  // automatic send of the marked ones.
  const waiting = sendable.filter(
    (p) =>
      p.channel !== "in_person" &&
      !p.invited &&
      !posted.has(p.employee_public_id),
  );
  const shouldSend =
    can.inviteUsers &&
    query.isSuccess &&
    toInvite.length > 0 &&
    result === null;
  useEffect(() => {
    if (!shouldSend || sentOnce.current) return;
    sentOnce.current = true;
    void sendAll(toInvite);
    // The list of people is read once, when the card first has it.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [shouldSend]);

  function channelLabel(p: PendingLogin): string {
    return {
      email: t("migration_logins.channel_email", "Email"),
      sms: t("migration_logins.channel_sms", "Text message"),
      in_person: t("migration_logins.channel_in_person", "In person"),
    }[p.channel];
  }

  // One run covers every slice, and the button can be pressed between two
  // requests, or while the automatic send is still going: a second run would
  // post people the first had already posted. `create.isPending` only knows
  // the request in flight, so the run holds its own guard.
  const running = useRef(false);
  const [sending, setSending] = useState(false);
  async function sendAll(
    ids = unsent.length > 0
      ? unsent
      : (result === null ? sendable : waiting).map((p) => p.employee_public_id),
  ) {
    if (running.current) return;
    running.current = true;
    setSending(true);
    try {
      await sendSlices(ids);
    } finally {
      running.current = false;
      setSending(false);
    }
  }

  async function sendSlices(ids: string[]) {
    const total: CreateLoginsResult = {
      message: "",
      created: 0,
      active: 0,
      in_person: 0,
      email_in_use: 0,
      batch: null,
      queued: 0,
    };
    const batches: string[] = [];
    let sent = 0;
    try {
      while (sent < ids.length) {
        const r = await create.mutateAsync(
          ids.slice(sent, sent + INVITE_SLICE),
        );
        const slice = ids.slice(sent, sent + INVITE_SLICE);
        sent += INVITE_SLICE;
        setPosted((prev) => new Set([...prev, ...slice]));
        total.message = r.message;
        total.created += r.created;
        total.active += r.active;
        total.in_person += r.in_person;
        total.email_in_use += r.email_in_use;
        total.queued += r.queued;
        if (r.batch) batches.push(r.batch);
      }
      setUnsent([]);
      toast.success(
        t("migration_logins.queued", "Invitations are being sent: :count", {
          count: total.queued,
        }),
      );
    } catch (e) {
      // Kept: what the slices before the failure did, and the ids that were
      // not sent, which the button sends next.
      setUnsent(ids.slice(sent));
      toastError(e, t("common.error", "Something went wrong"));
    }
    if (sent === 0) return;
    setResult((prev) =>
      prev
        ? {
            ...prev,
            created: prev.created + total.created,
            active: prev.active + total.active,
            in_person: prev.in_person + total.in_person,
            email_in_use: prev.email_in_use + total.email_in_use,
            queued: prev.queued + total.queued,
          }
        : { ...total, batch: batches[0] ?? null },
    );
    setInvitationBatches((prev) => [...prev, ...batches]);
  }

  return (
    <div className="space-y-4">
      {defaulted > 0 && (
        <Alert variant="warning">
          <AlertTitle>
            {t("migration_logins.hire_date_title", "Check the hire dates")}
          </AlertTitle>
          <AlertDescription>
            {t(
              "migration_logins.hire_date_desc",
              ":count people were given today as their hire date, because the source did not have one. Leave and severance are counted from it, so correct it on each profile.",
              { count: defaulted },
            )}
          </AlertDescription>
        </Alert>
      )}

      <Card>
        <CardContent className="space-y-3 p-4">
          <div className="flex items-center gap-2 text-sm font-semibold text-foreground">
            <KeyRound className="h-4 w-4" />
            {t("migration_logins.title", "Give them a way to sign in")}
          </div>

          <QueryBoundary query={query} isEmpty={() => false}>
            {() =>
              pending.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                  {undone
                    ? t(
                        "migration_logins.none_undone",
                        "This list's additions were undone. Nobody from it is waiting for a way to sign in.",
                      )
                    : t(
                        "migration_logins.none",
                        "Everyone brought in can already sign in.",
                      )}
                </p>
              ) : (
                <>
                  <p className="text-sm text-muted-foreground">
                    {t(
                      "migration_logins.desc",
                      "People with an email get an invitation by email. People without one get a printed code each, which you hand over in person, or one starting password between them.",
                    )}
                  </p>
                  <div className="rounded-lg border">
                    <SimpleTable
                      caption={t(
                        "migration_logins.title",
                        "Give them a way to sign in",
                      )}
                      maxHeight="18rem"
                      headers={[
                        t("setup2.person", "Person"),
                        t("migration_logins.contact", "Contact"),
                        t("migration_logins.channel", "Invitation"),
                      ]}
                      rows={pending.map((p) => ({
                        key: p.employee_public_id,
                        cells: [
                          <Link
                            key="n"
                            href={`/employees/${p.employee_public_id}`}
                            className="text-primary underline-offset-2 hover:underline"
                          >
                            {p.name}
                          </Link>,
                          <span
                            key="c"
                            className="text-xs text-muted-foreground"
                          >
                            {p.email ??
                              p.phone ??
                              t("migration_logins.no_contact", "None on file")}
                          </span>,
                          <span key="ch" className="text-xs">
                            {channelLabel(p)}
                            {p.invited
                              ? ` · ${t("migration_logins.invited", "invited, not yet activated")}`
                              : ""}
                            {p.asked === false
                              ? ` · ${t("migration_logins.list_said_no", "this list did not ask to invite them")}`
                              : ""}
                          </span>,
                        ],
                      }))}
                    />
                  </div>
                </>
              )
            }
          </QueryBoundary>

          {result && (
            <p className="text-sm text-muted-foreground">
              {t(
                "migration_logins.result",
                "Logins created: :created. Invitations queued: :queued. To activate in person: :in_person.",
                {
                  created: result.created,
                  queued: result.queued,
                  in_person: result.in_person,
                },
              )}
              {result.email_in_use > 0 &&
                ` ${t(
                  "migration_logins.email_in_use",
                  "Email already used by another login: :count.",
                  { count: result.email_in_use },
                )}`}
            </p>
          )}

          {invitationBatches.map((b) => (
            <InvitationProgress
              key={b}
              batch={b}
              onRetried={(next) =>
                setInvitationBatches((prev) =>
                  prev.map((id) => (id === b ? next : id)),
                )
              }
            />
          ))}

          <div className="flex flex-wrap justify-end gap-2">
            <ActivationCodeSheetDialog employeeIds={inPerson} />
            <StartingPasswordDialog employeeIds={inPerson} />
            {can.inviteUsers &&
              sendable.length > 0 &&
              (!result || unsent.length > 0 || waiting.length > 0) && (
                <Button onClick={() => sendAll()} disabled={sending}>
                  {sending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                  {t("migration_logins.send", "Send invitations")}
                </Button>
              )}
            {onContinue && (
              <Button variant="outline" onClick={onContinue}>
                {t("migration_logins.continue", "Continue")}
              </Button>
            )}
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
