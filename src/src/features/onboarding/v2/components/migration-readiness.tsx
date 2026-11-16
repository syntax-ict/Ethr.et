"use client";

import Link from "next/link";
import { UserCheck } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { useT } from "@/lib/i18n/useT";
import type { SignInPlan } from "../types";

/**
 * What adding a list will do about signing in, said before anyone is added, so
 * the tick below it is a choice made with the numbers in front of you.
 *
 * The list's own sign-in column outranks the tick: a person it marks yes is
 * invited even with the tick off, and one it marks no is not invited with it
 * on. Someone who can only be activated in person is listed for a printed
 * code, and that works only where people can sign in with their employee
 * number, which is the organisation's choice in the Sign-in step.
 *
 * It counts only the people the list adds. People it matched who still cannot
 * sign in are listed after the commit, not counted here.
 */
export function MigrationReadiness({
  plan,
  inviteNow,
}: {
  plan: SignInPlan;
  inviteNow: boolean;
}) {
  const { t } = useT();
  const { invitable, in_person: inPerson } = plan;

  const invitedNow = invitable.yes + (inviteNow ? invitable.blank : 0);
  // With the tick off, the people the list says nothing about wait for the
  // button that follows the commit.
  const afterwards = inviteNow ? 0 : invitable.blank;
  const code = inPerson.yes + inPerson.blank;
  const marked = invitable.no + inPerson.no;

  if (plan.people === 0) return null;

  return (
    <div className="space-y-3" data-testid="migration-readiness">
      <div className="flex items-center gap-2 text-sm font-semibold text-foreground">
        <UserCheck className="h-4 w-4" aria-hidden="true" />
        {t(
          "migration_ready.title",
          "What adding this list will do about signing in, for the people it adds",
        )}
      </div>
      <ul className="space-y-1 text-sm text-foreground">
        <li>
          {t(
            "migration_ready.invited",
            "Invited by email or text when the list is added: :count",
            { count: invitedNow },
          )}
        </li>
        {afterwards > 0 && (
          <li>
            {t(
              "migration_ready.afterwards",
              "Can be invited with one button after the list is added: :count",
              { count: afterwards },
            )}
          </li>
        )}
        <li>
          {t(
            "migration_ready.code",
            "Listed for a printed code to hand over in person: :count",
            { count: code },
          )}
        </li>
        {marked > 0 && (
          <li>
            {t(
              "migration_ready.marked_no",
              "Marked no in the list, so not invited: :count",
              { count: marked },
            )}
          </li>
        )}
      </ul>

      {code > 0 && !plan.in_person_possible && (
        <Alert variant="warning">
          <AlertTitle>
            {t(
              "migration_ready.warn_title",
              "Some people can only be activated in person",
            )}
          </AlertTitle>
          <AlertDescription>
            {t(
              "migration_ready.warn_desc",
              ":count people have no email or phone to reach, and your organisation does not let people sign in with their employee number, so a code handed over in person would not let them in. Turn on Employee number in the Sign-in step of the guided setup, or give them an email or a phone.",
              { count: code },
            )}{" "}
            <Link
              href="/setup/guided"
              className="font-medium underline underline-offset-2"
            >
              {t("migration_ready.warn_link", "Open the Sign-in step")}
            </Link>
          </AlertDescription>
        </Alert>
      )}
    </div>
  );
}
