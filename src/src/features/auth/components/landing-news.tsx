"use client";

import { useState } from "react";
import {
  Briefcase,
  CalendarHeart,
  ExternalLink,
  Loader2,
  Mail,
  MapPin,
  Megaphone,
} from "lucide-react";
import {
  useLandingFeed,
  useLandingVacancy,
  type LandingFeed,
} from "@/features/auth/landing-feed";
import type { TenantRequest } from "@/lib/auth/use-auth-host-context";
import { useT } from "@/lib/i18n/useT";
import { formatDate, formatDateOnly } from "@/lib/utils/date";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

const TIME_ZONE = "Africa/Addis_Ababa";

type FeedVacancy = LandingFeed["vacancies"][number];

/**
 * What an organisation tells the public on its sign-in page: news marked for
 * it, open positions, upcoming holidays. All of it comes from
 * `/auth/tenant-landing/feed`, which lists only what the organisation chose to
 * publish; an empty feed renders nothing at all.
 */
export function LandingNews({ request }: { request: TenantRequest | null }) {
  const { t, locale } = useT();
  const { data } = useLandingFeed(request);
  const [vacancyId, setVacancyId] = useState<string | null>(null);

  if (
    !data ||
    (data.announcements.length === 0 &&
      data.vacancies.length === 0 &&
      data.holidays.length === 0)
  ) {
    return null;
  }

  return (
    <section
      aria-label={t("landing.news_label", "News from your organisation")}
      className="mt-8 space-y-6 border-t border-border pt-6"
    >
      {data.announcements.length > 0 && (
        <FeedBlock icon={Megaphone} title={t("landing.news", "Latest news")}>
          <ul className="space-y-3">
            {data.announcements.map((a) => (
              <li key={a.public_id} className="rounded-lg border p-3">
                <div className="flex items-start justify-between gap-2">
                  <p className="text-sm font-medium text-foreground">
                    {a.title}
                  </p>
                  {(a.priority === "urgent" || a.priority === "high") && (
                    <span
                      className={cn(
                        "shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase",
                        a.priority === "urgent"
                          ? "bg-destructive-soft text-destructive-on-soft"
                          : "bg-warning-soft text-warning-on-soft",
                      )}
                    >
                      {a.priority === "urgent"
                        ? t("landing.urgent", "Urgent")
                        : t("landing.important", "Important")}
                    </span>
                  )}
                </div>
                <p className="mt-1 line-clamp-3 whitespace-pre-line text-sm text-muted-foreground">
                  {a.excerpt}
                </p>
                {a.published_at && (
                  <p className="mt-2 text-xs text-muted-foreground">
                    {formatDate(a.published_at, TIME_ZONE)}
                  </p>
                )}
              </li>
            ))}
          </ul>
        </FeedBlock>
      )}

      {data.vacancies.length > 0 && (
        <FeedBlock
          icon={Briefcase}
          title={t("landing.vacancies", "Open positions")}
        >
          <ul className="divide-y rounded-lg border">
            {data.vacancies.map((v) => (
              <li key={v.public_id}>
                <button
                  type="button"
                  onClick={() => setVacancyId(v.public_id)}
                  className="flex w-full items-start justify-between gap-3 p-3 text-left transition-colors hover:bg-muted/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                  <span className="min-w-0">
                    <span className="block text-sm font-medium text-foreground">
                      {v.title}
                    </span>
                    <span className="mt-0.5 block text-xs text-muted-foreground">
                      <VacancyMeta vacancy={v} />
                    </span>
                  </span>
                  {v.closes_on && (
                    <span className="shrink-0 text-xs text-muted-foreground">
                      {t("landing.closes", "Closes :date", {
                        date: formatDateOnly(v.closes_on),
                      })}
                    </span>
                  )}
                </button>
              </li>
            ))}
          </ul>
        </FeedBlock>
      )}

      {data.holidays.length > 0 && (
        <FeedBlock
          icon={CalendarHeart}
          title={t("landing.holidays", "Upcoming holidays")}
        >
          <ul className="flex flex-wrap gap-2">
            {data.holidays.map((h) => (
              <li
                key={`${h.date}-${h.name}`}
                className="rounded-full border px-3 py-1 text-xs text-foreground"
              >
                {(locale === "am" && h.name_am) || h.name}
                <span className="ml-1.5 text-muted-foreground">
                  {formatDateOnly(h.date)}
                </span>
              </li>
            ))}
          </ul>
        </FeedBlock>
      )}

      <VacancyDialog
        request={request}
        publicId={vacancyId}
        onClose={() => setVacancyId(null)}
      />
    </section>
  );
}

function FeedBlock({
  icon: Icon,
  title,
  children,
}: {
  icon: typeof Megaphone;
  title: string;
  children: React.ReactNode;
}) {
  return (
    <div>
      <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold text-foreground">
        <Icon className="h-4 w-4 text-primary" aria-hidden="true" />
        {title}
      </h2>
      {children}
    </div>
  );
}

function VacancyMeta({ vacancy }: { vacancy: FeedVacancy }) {
  const { t } = useT();
  const parts = [
    t(`vacancies.type_${vacancy.employment_type}`, vacancy.employment_type),
    vacancy.department,
    vacancy.location ?? vacancy.branch,
  ].filter(Boolean);

  return <>{parts.join(" · ")}</>;
}

function VacancyDialog({
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
      <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-lg">
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
                    <Button asChild size="sm">
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
                    <Button asChild size="sm" variant="outline">
                      <a
                        href={`mailto:${data.apply_email}?subject=${encodeURIComponent(data.title)}`}
                      >
                        <Mail className="mr-2 h-3.5 w-3.5" />
                        {t("vacancies.apply_email", "Apply by e-mail")}
                      </a>
                    </Button>
                  )}
                </div>
              )}
            </div>
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}
