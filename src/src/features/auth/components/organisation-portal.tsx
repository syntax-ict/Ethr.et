"use client";

import { useEffect, useState } from "react";
import {
  Briefcase,
  CalendarDays,
  CalendarHeart,
  Clock,
  Megaphone,
  Receipt,
  ShieldCheck,
} from "lucide-react";
import { useTheme } from "next-themes";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { LanguageSwitcher } from "@/components/shared/language-switcher";
import { OrgMark } from "@/components/shared/org-mark";
import {
  useAuthHostContext,
  type TenantRequest,
} from "@/lib/auth/use-auth-host-context";
import { coverPosition, panelBackground } from "@/lib/auth/landing-background";
import { useLandingFeed, type LandingFeed } from "@/features/auth/landing-feed";
import { SupportContacts } from "@/app/(auth)/auth-layout-client";
import { VacancyDialog, VacancyMeta } from "./vacancy-dialog";
import { AnnouncementDialog } from "./announcement-dialog";
import { formatDate, formatDateOnly } from "@/lib/utils/date";
import { toEthiopian, formatEthiopian } from "@/lib/calendar/ethiopian";

const TIME_ZONE = "Africa/Addis_Ababa";

/**
 * The organisation portal: what the public sees at ethr.et/{slug}, the
 * organisation's subdomain or its own domain. One shared template for every
 * organisation, varied only by its content; the sign-in form inside the hero
 * card is the shared one, unchanged.
 *
 * Order on the page: identity and sign-in first (that is still the page's
 * job), then what the organisation publishes - news, open positions,
 * holidays - from the same feed the server already filtered. A section with
 * nothing to show does not render.
 */
export function OrganisationPortal({
  children,
}: {
  children: React.ReactNode;
}) {
  const { t } = useT();
  const {
    tenantName,
    tenantBrand,
    tenantLanding,
    tenantContextLoading,
    tenantRequest,
  } = useAuthHostContext();
  const { resolvedTheme } = useTheme();
  const highContrast = resolvedTheme === "high-contrast";

  // The header compacts once the page scrolls, so it gives the content room
  // while keeping the way back to the form one click away.
  const [compact, setCompact] = useState(false);
  useEffect(() => {
    const onScroll = () => setCompact(window.scrollY > 24);
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  const ownHeadline = Boolean(tenantLanding?.headline && tenantName);

  // The uploaded cover, when the photo background is chosen: drawn as an
  // image with its widths rather than a CSS background, so the browser picks
  // the size for the screen and can fetch it first (it is the largest paint).
  const cover =
    !highContrast &&
    tenantLanding?.background === "image" &&
    tenantBrand?.cover?.url
      ? tenantBrand.cover
      : null;

  // The square mark becomes the page's icon while the portal is open; the
  // links are put back when it closes, so ETHR's own pages keep theirs.
  const markUrl = tenantBrand?.mark_url ?? null;
  useEffect(() => {
    if (!markUrl || typeof document === "undefined") return;
    const links = Array.from(
      document.querySelectorAll<HTMLLinkElement>(
        'link[rel~="icon"], link[rel="apple-touch-icon"]',
      ),
    );
    const previous = links.map((link) => link.getAttribute("href"));
    const added: HTMLLinkElement[] = [];
    if (links.length === 0) {
      const link = document.createElement("link");
      link.rel = "icon";
      document.head.appendChild(link);
      links.push(link);
      previous.push(null);
      added.push(link);
    }
    links.forEach((link) => link.setAttribute("href", markUrl));
    return () => {
      links.forEach((link, i) => {
        const href = previous[i];
        if (added.includes(link)) link.remove();
        else if (href !== null) link.setAttribute("href", href);
      });
    };
  }, [markUrl]);

  const features = [
    {
      icon: Clock,
      label: t("auth.org_feature_attendance", "Check in and out"),
    },
    {
      icon: CalendarDays,
      label: t("auth.org_feature_leave", "Request and track leave"),
    },
    {
      icon: Receipt,
      label: t("auth.org_feature_payslips", "See your payslips"),
    },
  ];

  return (
    <div className="flex min-h-screen flex-col bg-background">
      <header
        className={cn(
          "sticky top-0 z-40 border-b border-border/60 bg-background/90 backdrop-blur transition-[padding] duration-200 motion-reduce:transition-none",
          compact ? "py-2" : "py-3.5",
        )}
      >
        <div className="mx-auto flex w-full max-w-6xl items-center gap-3 px-4 sm:px-6">
          <OrgMark
            name={tenantName}
            logoUrl={tenantBrand?.logo_url}
            darkLogoUrl={tenantBrand?.logo_dark_url}
            className="h-9 w-9 shrink-0"
            initialClassName="text-sm"
          />
          <p className="min-w-0 truncate text-sm font-semibold text-foreground">
            {tenantName ?? ""}
          </p>
          <div className="ml-auto flex shrink-0 items-center gap-2">
            <LanguageSwitcher />
            <Button size="sm" asChild>
              <a href="#sign-in">{t("auth.sign_in", "Sign In")}</a>
            </Button>
          </div>
        </div>
      </header>

      <section
        className="relative overflow-hidden bg-primary"
        style={
          highContrast
            ? undefined
            : cover
              ? { ["--color-primary-foreground" as string]: "#ffffff" }
              : panelBackground(tenantLanding, tenantBrand?.secondary_color)
        }
      >
        {cover ? (
          <>
            {/* The server's own re-encoded photo, at the width the screen
                needs; a dark wash keeps the text readable over any photo. */}
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src={cover.url ?? undefined}
              srcSet={cover.srcset}
              sizes="100vw"
              width={Number(cover.width)}
              height={Number(cover.height)}
              alt=""
              fetchPriority="high"
              decoding="async"
              data-testid="hero-cover"
              className="absolute inset-0 h-full w-full object-cover"
              style={{ objectPosition: coverPosition(cover) }}
            />
            <div className="absolute inset-0 bg-[linear-gradient(180deg,rgba(0,0,0,0.45),rgba(0,0,0,0.7))]" />
          </>
        ) : (
          <div className="absolute inset-0 bg-[linear-gradient(to_right,rgba(255,255,255,0.03)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.03)_1px,transparent_1px)] bg-[size:40px_40px]" />
        )}
        <div className="absolute bottom-0 left-0 h-1/3 w-full bg-gradient-to-t from-black/10 to-transparent" />

        <div className="relative mx-auto grid w-full max-w-6xl gap-8 px-4 py-10 sm:px-6 lg:grid-cols-[minmax(0,1fr)_minmax(360px,420px)] lg:items-center lg:gap-14 lg:py-16">
          <div className="min-w-0">
            <OrgMark
              name={tenantName}
              logoUrl={tenantBrand?.logo_url}
              darkLogoUrl={tenantBrand?.logo_dark_url}
              surface={cover ? "dark" : undefined}
              className="h-16 w-16 rounded-2xl bg-primary-foreground/15 ring-0"
              initialClassName="text-2xl"
            />
            {ownHeadline && (
              <p className="mt-5 text-sm font-medium uppercase tracking-wider text-primary-foreground/70">
                {tenantName}
              </p>
            )}
            <h1
              className={cn(
                "max-w-xl text-balance text-3xl font-bold leading-tight text-primary-foreground sm:text-4xl",
                ownHeadline ? "mt-2" : "mt-6",
              )}
            >
              {tenantContextLoading
                ? "…"
                : ((tenantLanding?.headline || tenantName) ?? "")}
            </h1>
            <p className="mt-3 max-w-xl whitespace-pre-line text-base leading-relaxed text-primary-foreground/80">
              {tenantLanding?.message ||
                t(
                  "auth.org_welcome",
                  "Your workspace for attendance, leave and pay.",
                )}
            </p>

            <ul className="mt-8 hidden max-w-md space-y-3 lg:block">
              {features.map(({ icon: Icon, label }) => (
                <li
                  key={label}
                  className="flex items-center gap-3 rounded-xl bg-primary-foreground/10 px-4 py-3 text-sm text-primary-foreground backdrop-blur-sm"
                >
                  <Icon
                    className="h-4 w-4 shrink-0 opacity-80"
                    aria-hidden="true"
                  />
                  {label}
                </li>
              ))}
            </ul>

            <div className="max-w-xl">
              <SupportContacts landing={tenantLanding} />
            </div>
          </div>

          <div id="sign-in" className="min-w-0 scroll-mt-24">
            <div className="rounded-2xl border border-black/5 bg-background p-6 text-foreground shadow-2xl sm:p-8">
              {children}
            </div>
          </div>
        </div>

        <div className="relative h-1 w-full bg-[repeating-linear-gradient(90deg,rgba(255,255,255,0.3)_0px,rgba(255,255,255,0.3)_8px,rgba(232,168,56,0.5)_8px,rgba(232,168,56,0.5)_16px,rgba(255,255,255,0.3)_16px,rgba(255,255,255,0.3)_24px,transparent_24px,transparent_32px)]" />
      </section>

      <main className="mx-auto w-full max-w-6xl flex-1 px-4 sm:px-6">
        <PortalSections request={tenantRequest} />
      </main>

      <footer className="border-t border-border">
        <div className="mx-auto flex w-full max-w-6xl flex-wrap items-center justify-between gap-x-6 gap-y-2 px-4 py-6 text-xs text-muted-foreground sm:px-6">
          <p>
            © {new Date().getFullYear()} {tenantName ?? "ETHR"}
          </p>
          {tenantLanding?.support_email && (
            <a
              className="rounded-sm underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              href={`mailto:${tenantLanding.support_email}`}
            >
              {tenantLanding.support_email}
            </a>
          )}
          <p className="inline-flex items-center gap-1.5">
            <ShieldCheck className="h-3.5 w-3.5" aria-hidden="true" />
            {t("auth.powered_by", "Powered by ETHR")}
          </p>
        </div>
      </footer>
    </div>
  );
}

/**
 * News, open positions and holidays, from `/auth/tenant-landing/feed`: the
 * server lists only what the organisation chose to publish, and sections the
 * feed leaves empty render nothing.
 */
function PortalSections({ request }: { request: TenantRequest | null }) {
  const { t, locale } = useT();
  const { data, isLoading } = useLandingFeed(request);
  const [announcementId, setAnnouncementId] = useState<string | null>(null);
  const [vacancyId, setVacancyId] = useState<string | null>(null);

  if (isLoading && request !== null) {
    // The same card grid the content lands in, so nothing jumps.
    return (
      <div className="grid gap-4 py-12 sm:grid-cols-2 lg:grid-cols-3">
        {Array.from({ length: 3 }).map((_, i) => (
          <Skeleton key={i} className="h-36 rounded-xl" />
        ))}
      </div>
    );
  }

  if (
    !data ||
    (data.announcements.length === 0 &&
      data.vacancies.length === 0 &&
      data.holidays.length === 0)
  ) {
    return null;
  }

  return (
    <div className="space-y-14 py-12">
      {data.announcements.length > 0 && (
        <PortalSection
          id="news"
          icon={Megaphone}
          title={t("landing.news", "Latest news")}
        >
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {data.announcements.map((a) => (
              <button
                key={a.public_id}
                type="button"
                onClick={() => setAnnouncementId(a.public_id)}
                className="group flex min-w-0 flex-col rounded-xl border border-border bg-card p-4 text-left transition-colors hover:border-border-strong hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                <span className="flex items-center gap-2 text-xs text-muted-foreground">
                  {a.published_at && (
                    <time dateTime={a.published_at}>
                      {formatDate(a.published_at, TIME_ZONE)}
                    </time>
                  )}
                  {(a.priority === "urgent" || a.priority === "high") && (
                    <span
                      className={cn(
                        "rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase",
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
                </span>
                <span className="mt-2 text-sm font-semibold text-foreground">
                  {a.title}
                </span>
                <span className="mt-1 line-clamp-4 whitespace-pre-line text-sm text-muted-foreground">
                  {a.excerpt}
                </span>
                <span className="mt-3 text-xs font-medium text-primary group-hover:underline">
                  {t("landing.read_more", "Read more")}
                </span>
              </button>
            ))}
          </div>
        </PortalSection>
      )}

      {data.vacancies.length > 0 && (
        <PortalSection
          id="careers"
          icon={Briefcase}
          title={t("landing.vacancies", "Open positions")}
        >
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {data.vacancies.map((v) => (
              <button
                key={v.public_id}
                type="button"
                onClick={() => setVacancyId(v.public_id)}
                className="group flex min-w-0 flex-col rounded-xl border border-border bg-card p-4 text-left transition-colors hover:border-border-strong hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                <span className="text-sm font-semibold text-foreground">
                  {v.title}
                </span>
                <span className="mt-1 text-xs text-muted-foreground">
                  <VacancyMeta vacancy={v} />
                </span>
                <span className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                  {v.positions > 1 && (
                    <span>
                      {t("landing.positions", ":count positions", {
                        count: v.positions,
                      })}
                    </span>
                  )}
                  {v.closes_on && (
                    <span>
                      {t("landing.closes", "Closes :date", {
                        date: formatDateOnly(v.closes_on),
                      })}
                    </span>
                  )}
                </span>
              </button>
            ))}
          </div>
        </PortalSection>
      )}

      {data.holidays.length > 0 && (
        <PortalSection
          id="holidays"
          icon={CalendarHeart}
          title={t("landing.holidays", "Upcoming holidays")}
        >
          <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border">
            {data.holidays.map((h) => (
              <HolidayRow
                key={`${h.date}-${h.name}`}
                holiday={h}
                locale={locale}
              />
            ))}
          </ul>
        </PortalSection>
      )}

      <AnnouncementDialog
        request={request}
        publicId={announcementId}
        onClose={() => setAnnouncementId(null)}
      />
      <VacancyDialog
        request={request}
        publicId={vacancyId}
        onClose={() => setVacancyId(null)}
      />
    </div>
  );
}

function PortalSection({
  id,
  icon: Icon,
  title,
  children,
}: {
  id: string;
  icon: typeof Megaphone;
  title: string;
  children: React.ReactNode;
}) {
  return (
    <section id={id} aria-label={title} className="scroll-mt-24">
      <h2 className="mb-4 flex items-center gap-2 text-lg font-semibold text-foreground">
        <Icon className="h-5 w-5 text-primary" aria-hidden="true" />
        {title}
      </h2>
      {children}
    </section>
  );
}

/** A holiday with its date in both calendars, whatever the viewer's setting. */
function HolidayRow({
  holiday,
  locale,
}: {
  holiday: LandingFeed["holidays"][number];
  locale: string;
}) {
  const [y, m, d] = holiday.date.split("-").map(Number);
  const ethiopian =
    y && m && d ? formatEthiopian(toEthiopian(y, m, d), locale) : null;

  return (
    <li className="flex flex-wrap items-center gap-x-4 gap-y-1 bg-card px-4 py-3">
      <span
        className="h-1.5 w-1.5 shrink-0 rounded-full bg-brand-accent"
        aria-hidden="true"
      />
      <span className="min-w-0 flex-1 text-sm font-medium text-foreground">
        {(locale === "am" && holiday.name_am) || holiday.name}
      </span>
      <span className="text-xs tabular-nums text-muted-foreground">
        {formatDateOnly(holiday.date)}
        {ethiopian && <span className="ml-2">{ethiopian}</span>}
      </span>
    </li>
  );
}
