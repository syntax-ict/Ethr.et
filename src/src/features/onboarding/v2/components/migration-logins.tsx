"use client";

import { useState } from "react";
import Link from "next/link";
import { KeyRound, Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { SimpleTable } from "@/components/shared/simple-table";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { InvitationProgress } from "@/features/users/components/invitation-progress";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { toastError } from "@/lib/errors";
import { useT } from "@/lib/i18n/useT";
import { useCreateEmployeeLogins, useMigrationLogins } from "../api";
import type { CreateLoginsResult, PendingLogin } from "../types";

/**
 * After a migration commits: the people who cannot sign in yet, how each would
 * be invited, and one button that gives them all a way in. Nothing is sent at
 * commit, because names read off a terminal are often wrong ("guest card",
 * "test"), so HR looks first. Also the owner's hire-date warning (2026-10-28):
 * the date was set to today, and is HR's to correct.
 */
export function MigrationLogins({
  batchId,
  onContinue,
}: {
  batchId: string;
  onContinue?: () => void;
}) {
  const { t } = useT();
  const { can } = usePermissions();
  const query = useMigrationLogins(batchId);
  const create = useCreateEmployeeLogins();
  const [result, setResult] = useState<CreateLoginsResult | null>(null);
  const [invitationBatch, setInvitationBatch] = useState<string | null>(null);

  const pending = query.data?.without_login ?? [];
  const defaulted = query.data?.hire_dates_defaulted ?? 0;

  function channelLabel(p: PendingLogin): string {
    return {
      email: t("migration_logins.channel_email", "Email"),
      sms: t("migration_logins.channel_sms", "Text message"),
      in_person: t("migration_logins.channel_in_person", "In person"),
    }[p.channel];
  }

  async function sendAll() {
    try {
      const r = await create.mutateAsync(
        pending.map((p) => p.employee_public_id),
      );
      setResult(r);
      setInvitationBatch(r.batch);
      toast.success(
        t("migration_logins.queued", "Invitations are being sent: :count", {
          count: r.queued,
        }),
      );
    } catch (e) {
      toastError(e, t("common.error", "Something went wrong"));
    }
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
                  {t(
                    "migration_logins.none",
                    "Everyone brought in can already sign in.",
                  )}
                </p>
              ) : (
                <>
                  <p className="text-sm text-muted-foreground">
                    {t(
                      "migration_logins.desc",
                      "Check the names first. People with an email get an invitation by email. People without one are listed for an in-person code, which you issue from their profile.",
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

          {invitationBatch && (
            <InvitationProgress
              batch={invitationBatch}
              onRetried={setInvitationBatch}
            />
          )}

          <div className="flex flex-wrap justify-end gap-2">
            {can.inviteUsers && pending.length > 0 && !result && (
              <Button onClick={sendAll} disabled={create.isPending}>
                {create.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
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
