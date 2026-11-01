"use client";

import { useState } from "react";
import { CheckCircle2, Loader2, UserX } from "lucide-react";
import { Button } from "@/components/ui/button";
import { EmployeeAvatar } from "@/components/shared/employee-avatar";
import { useT } from "@/lib/i18n/useT";
import type { KioskPunchResult } from "../api";

type Person = NonNullable<KioskPunchResult["employee"]>;

/**
 * The confirmation after a punch: the person's face, name and job title, so
 * they and anyone watching can see the punch went to the right person, and a
 * way to say it did not.
 *
 * Nothing is shown before the punch. A "this is you?" step would need the
 * server to answer a code with a name and a face without recording anything,
 * and then anyone at the terminal could page through the staff by guessing
 * codes.
 */
export function KioskPunchResultCard({
  message,
  time,
  person,
  canReport,
  onReport,
}: {
  message: string;
  time: string;
  person: Person | null;
  canReport: boolean;
  /** Resolves when HR has it; rejects when the server refused. */
  onReport: () => Promise<void>;
}) {
  const { t, locale } = useT();
  const [report, setReport] = useState<"idle" | "sending" | "sent" | "failed">(
    "idle",
  );
  const name =
    person && locale === "am" && person.name_am ? person.name_am : person?.name;

  async function sendReport() {
    setReport("sending");
    try {
      await onReport();
      setReport("sent");
    } catch {
      setReport("failed");
    }
  }

  return (
    <div className="text-center space-y-5 animate-in zoom-in duration-300">
      {person && name ? (
        <div className="relative mx-auto h-40 w-40">
          <EmployeeAvatar
            name={name}
            photoThumbUrl={person.photo_url}
            className="h-40 w-40 border-4 border-success"
            fallbackClassName="text-5xl font-bold"
          />
          <span className="absolute -bottom-1 -right-1 flex h-12 w-12 items-center justify-center rounded-full bg-background">
            <CheckCircle2
              className="h-11 w-11 text-success"
              aria-hidden="true"
            />
          </span>
        </div>
      ) : (
        <div className="mx-auto flex h-32 w-32 items-center justify-center rounded-full bg-success-soft">
          <CheckCircle2 className="h-20 w-20 text-success" aria-hidden="true" />
        </div>
      )}
      <div className="space-y-1">
        <p className="text-4xl font-bold text-foreground">{message}</p>
        {person?.position && (
          <p className="text-lg text-muted-foreground">{person.position}</p>
        )}
      </div>
      <p className="text-sm text-muted-foreground">
        {t("kiosk_page.recorded_at", undefined, { time })}
      </p>
      {canReport && report !== "sent" && (
        <Button
          variant="outline"
          size="lg"
          onClick={sendReport}
          disabled={report === "sending"}
        >
          {report === "sending" ? (
            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
          ) : (
            <UserX className="mr-2 h-4 w-4" />
          )}
          {t("kiosk_page.not_you")}
        </Button>
      )}
      {report === "sent" && (
        <p className="text-base font-medium text-warning-on-soft" role="status">
          {t("kiosk_page.reported")}
        </p>
      )}
      {report === "failed" && (
        <p className="text-sm text-destructive" role="alert">
          {t("kiosk_page.report_failed")}
        </p>
      )}
    </div>
  );
}
