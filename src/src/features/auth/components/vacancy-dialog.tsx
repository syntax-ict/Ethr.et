"use client";

import { ExternalLink, Loader2, Mail, MapPin, Printer } from "lucide-react";
import {
  useLandingVacancy,
  type LandingFeed,
} from "@/features/auth/landing-feed";
import type { TenantRequest } from "@/lib/auth/use-auth-host-context";
import { useT } from "@/lib/i18n/useT";
import { formatDateOnly } from "@/lib/utils/date";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

type FeedVacancy = LandingFeed["vacancies"][number];

/** Type, department and place on one line, as the cards and the detail show it. */
export function VacancyMeta({ vacancy }: { vacancy: FeedVacancy }) {
  const { t } = useT();
  const parts = [
    t(`vacancies.type_${vacancy.employment_type}`, vacancy.employment_type),
    vacancy.department,
    vacancy.location ?? vacancy.branch,
  ].filter(Boolean);

  return <>{parts.join(" · ")}</>;
}

/**
 * One vacancy in full: description, requirements, and how to apply. The
 * `print-surface` class and the Print button make it the one thing a printed
 * page carries, so a position can be pinned on a notice board.
 */
export function VacancyDialog({
  request,
  publicId,
  onClose,
}: {
  request: TenantRequest | null;
  publicId: string | null;
  onClose: () => void;
}) {
  const { t } = useT();
  const { data, isLoading, isError } = useLandingVacancy(request, publicId);

  return (
    <Dialog
      open={publicId !== null}
      onOpenChange={(open) => !open && onClose()}
    >
      <DialogContent className="print-surface max-h-[85vh] overflow-y-auto sm:max-w-lg">
        {isLoading || !data ? (
          <div className="flex justify-center py-10">
            {isError ? (
              <p className="text-sm text-muted-foreground">
                {t("landing.vacancy_gone", "This position is no longer open.")}
              </p>
            ) : (
              <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
            )}
            <DialogTitle className="sr-only">
              {t("landing.vacancies", "Open positions")}
            </DialogTitle>
          </div>
        ) : (
          <>
            <DialogHeader>
              <DialogTitle>{data.title}</DialogTitle>
              <DialogDescription className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <VacancyMeta vacancy={data} />
                {data.positions > 1 && (
                  <span>
                    {t("landing.positions", ":count positions", {
                      count: data.positions,
                    })}
                  </span>
                )}
              </DialogDescription>
            </DialogHeader>
            <div className="space-y-4 text-sm">
              <p className="whitespace-pre-line text-foreground">
                {data.description}
              </p>
              {data.requirements && (
                <div>
                  <h3 className="mb-1 font-semibold">
                    {t("vacancies.requirements", "Requirements")}
                  </h3>
                  <p className="whitespace-pre-line text-muted-foreground">
                    {data.requirements}
                  </p>
                </div>
              )}
              {(data.location || data.closes_on) && (
                <p className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                  {data.location && (
                    <span className="inline-flex items-center gap-1">
                      <MapPin className="h-3.5 w-3.5" aria-hidden="true" />
                      {data.location}
                    </span>
                  )}
                  {data.closes_on && (
                    <span>
                      {t("landing.closes", "Closes :date", {
                        date: formatDateOnly(data.closes_on),
                      })}
                    </span>
                  )}
                </p>
              )}
              {(data.apply_url || data.apply_email) && (
                <div className="flex flex-wrap gap-2 border-t pt-4">
                  {data.apply_url && (
                    <Button asChild size="sm" className="print:hidden">
                      <a
                        href={data.apply_url}
                        target="_blank"
                        rel="noopener noreferrer nofollow"
                      >
                        <ExternalLink className="mr-2 h-3.5 w-3.5" />
                        {t("vacancies.apply_online", "Apply online")}
                      </a>
                    </Button>
                  )}
                  {data.apply_email && (
                    <Button
                      asChild
                      size="sm"
                      variant="outline"
                      className="print:hidden"
                    >
                      <a
                        href={`mailto:${data.apply_email}?subject=${encodeURIComponent(data.title)}`}
                      >
                        <Mail className="mr-2 h-3.5 w-3.5" />
                        {t("vacancies.apply_email", "Apply by e-mail")}
                      </a>
                    </Button>
                  )}
                  <Button
                    size="sm"
                    variant="ghost"
                    className="print:hidden"
                    onClick={() => window.print()}
                  >
                    <Printer className="mr-2 h-3.5 w-3.5" />
                    {t("landing.print", "Print")}
                  </Button>
                  {/* On paper the links above are buttons that lead nowhere,
                      so the printed page states the address itself. */}
                  <p className="hidden w-full text-xs text-muted-foreground print:block">
                    {data.apply_email &&
                      `${t("vacancies.apply_email", "Apply by e-mail")}: ${data.apply_email}`}
                    {data.apply_email && data.apply_url && " · "}
                    {data.apply_url &&
                      `${t("vacancies.apply_online", "Apply online")}: ${data.apply_url}`}
                  </p>
                </div>
              )}
            </div>
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}
