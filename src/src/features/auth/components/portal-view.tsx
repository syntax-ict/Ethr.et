"use client";

import { useEffect, useState, type ReactNode } from "react";
import {
  Briefcase,
  Building2,
  CalendarDays,
  CalendarHeart,
  Clock,
  Clock3,
  Factory,
  Info,
  Landmark,
  Mail,
  MapPin,
  Megaphone,
  Phone,
  Receipt,
  ShieldCheck,
  Users,
} from "lucide-react";
import { useTheme } from "next-themes";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { LanguageSwitcher } from "@/components/shared/language-switcher";
import { OrgMark } from "@/components/shared/org-mark";
import type {
  PortalSection,
  TenantBrand,
  TenantLanding,
} from "@/lib/auth/use-auth-host-context";
import { coverPosition, panelBackground } from "@/lib/auth/landing-background";
import {
  hasAbout,
  hasContact,
  LINKS,
  localised,
  mapsUrl,
  sectionOn,
  sectionOrder,
} from "@/lib/auth/landing-text";
import type { LandingFeed } from "@/features/auth/landing-feed";
import { SupportContacts } from "@/app/(auth)/auth-layout-client";
import { VacancyMeta } from "./vacancy-dialog";
import { formatDate, formatDateOnly } from "@/lib/utils/date";
import { toEthiopian, formatEthiopian } from "@/lib/calendar/ethiopian";

const TIME_ZONE = "Africa/Addis_Ababa";

export interface PortalViewProps {
  name: string | null;
  brand: TenantBrand | null | undefined;
  landing: TenantLanding | null | undefined;
  /** What the organisation publishes; `undefined` while it loads. */
  feed: LandingFeed | null | undefined;
  feedLoading: boolean;
  /** The context itself still loading: the headline is held back. */
  loading?: boolean;
  onOpenAnnouncement: (publicId: string) => void;
  onOpenVacancy: (publicId: string) => void;
  /** The shared sign-in form, placed in the hero card. */
  children: ReactNode;
}

/**
 * The organisation portal, drawn from data alone: the public page at
 * ethr.et/{slug} hands it the organisation's context and feed, and the
 * settings screen's preview hands it the form as it is being edited. One
 * template for every organisation, varied only by its content; the sign-in
 * form inside the hero card is the shared one, unchanged.
 *
 * Layout breakpoints are container queries, not viewport ones, so the same
 * markup lays out as a phone or a desktop by the width it is given: the
 * live page's container is the viewport, the preview's is the frame.
 *
 * Order on the page: identity and sign-in first (that is still the page's
 * job), then the sections the organisation switched on, in the order it
 * chose. A section with nothing to show does not render.
 */
export function PortalView({
  name,
  brand,
  landing,
  feed,
  feedLoading,
  loading = false,
  onOpenAnnouncement,
  onOpenVacancy,
  children,
}: PortalViewProps) {
  const { t, locale } = useT();
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

  const headline = localised(landing, "headline", locale);
  const message = localised(landing, "message", locale);
  const ownHeadline = Boolean(headline && name);

  // The uploaded cover, when the photo background is chosen: drawn as an
  // image with its widths rather than a CSS background, so the browser picks
  // the size for the screen and can fetch it first (it is the largest paint).
  const cover =
    !highContrast && landing?.background === "image" && brand?.cover?.url
      ? brand.cover
      : null;

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
    <div className="@container flex min-h-screen flex-col bg-background">
      <header
        className={cn(
          "sticky top-0 z-40 border-b border-border/60 bg-background/90 backdrop-blur transition-[padding] duration-200 motion-reduce:transition-none",
          compact ? "py-2" : "py-3.5",
        )}
      >
        <div className="mx-auto flex w-full max-w-6xl items-center gap-3 px-4 @sm:px-6">
          <OrgMark
            name={name}
            logoUrl={brand?.logo_url}
            darkLogoUrl={brand?.logo_dark_url}
            className="h-9 w-9 shrink-0"
            initialClassName="text-sm"
          />
          <p className="min-w-0 truncate text-sm font-semibold text-foreground">
            {name ?? ""}
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
              : panelBackground(landing, brand?.secondary_color)
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

        <div className="relative mx-auto grid w-full max-w-6xl gap-8 px-4 py-10 @sm:px-6 @lg:grid-cols-[minmax(0,1fr)_minmax(360px,420px)] @lg:items-center @lg:gap-14 @lg:py-16">
          <div className="min-w-0">
            <OrgMark
              name={name}
              logoUrl={brand?.logo_url}
              darkLogoUrl={brand?.logo_dark_url}
              surface={cover ? "dark" : undefined}
              className="h-16 w-16 rounded-2xl bg-primary-foreground/15 ring-0"
              initialClassName="text-2xl"
            />
            {ownHeadline && (
              <p className="mt-5 text-sm font-medium uppercase tracking-wider text-primary-foreground/70">
                {name}
              </p>
            )}
            <h1
              className={cn(
                "max-w-xl text-balance text-3xl font-bold leading-tight text-primary-foreground @sm:text-4xl",
                ownHeadline ? "mt-2" : "mt-6",
              )}
            >
              {loading ? "…" : ((headline || name) ?? "")}
            </h1>
            <p className="mt-3 max-w-xl whitespace-pre-line text-base leading-relaxed text-primary-foreground/80">
              {message ||
                t(
                  "auth.org_welcome",
                  "Your workspace for attendance, leave and pay.",
                )}
            </p>

            <ul className="mt-8 hidden max-w-md space-y-3 @lg:block">
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
              <SupportContacts landing={landing ?? null} />
            </div>
          </div>

          <div id="sign-in" className="min-w-0 scroll-mt-24">
            <div className="rounded-2xl border border-black/5 bg-background p-6 text-foreground shadow-2xl @sm:p-8">
              {children}
            </div>
          </div>
        </div>

        <div className="relative h-1 w-full bg-[repeating-linear-gradient(90deg,rgba(255,255,255,0.3)_0px,rgba(255,255,255,0.3)_8px,rgba(232,168,56,0.5)_8px,rgba(232,168,56,0.5)_16px,rgba(255,255,255,0.3)_16px,rgba(255,255,255,0.3)_24px,transparent_24px,transparent_32px)]" />
      </section>

      <main className="mx-auto w-full max-w-6xl flex-1 px-4 @sm:px-6">
        <PortalSections
          landing={landing}
          feed={feed}
          loading={feedLoading}
          onOpenAnnouncement={onOpenAnnouncement}
          onOpenVacancy={onOpenVacancy}
        />
      </main>

      <PortalFooter name={name} landing={landing} />
    </div>
  );
}

/**
 * The sections the organisation switched on, in its order; each renders
 * only with something to show.
 */
function PortalSections({
  landing,
  feed,
  loading,
  onOpenAnnouncement,
  onOpenVacancy,
}: {
  landing: TenantLanding | null | undefined;
  feed: LandingFeed | null | undefined;
  loading: boolean;
  onOpenAnnouncement: (publicId: string) => void;
  onOpenVacancy: (publicId: string) => void;
}) {
  const { t, locale } = useT();

  if (loading && !feed) {
    // The same card grid the content lands in, so nothing jumps.
    return (
      <div className="grid gap-4 py-12 @sm:grid-cols-2 @lg:grid-cols-3">
        {Array.from({ length: 3 }).map((_, i) => (
          <Skeleton key={i} className="h-36 rounded-xl" />
        ))}
      </div>
    );
  }

  const announcements = feed?.announcements ?? [];
  const vacancies = feed?.vacancies ?? [];
  const holidays = feed?.holidays ?? [];
  const branches = feed?.branches ?? [];
  const branchCount = feed?.branch_count ?? null;

  const present: Record<PortalSection, boolean> = {
    about: hasAbout(landing, branchCount),
    announcements: announcements.length > 0,
    vacancies: vacancies.length > 0,
    holidays: holidays.length > 0,
    contact: hasContact(landing, branches.length),
  };
  const sections = sectionOrder(landing).filter(
    (section) => sectionOn(landing, section) && present[section],
  );
  if (sections.length === 0) return null;

  return (
    <div className="space-y-14 py-12">
      {sections.map((section) => {
        switch (section) {
          case "about":
            return (
              <PortalSectionFrame
                key={section}
                id="about"
                icon={Info}
                title={t("landing.about", "About us")}
              >
                <AboutSection landing={landing} branchCount={branchCount} />
              </PortalSectionFrame>
            );
          case "announcements":
            return (
              <PortalSectionFrame
                key={section}
                id="news"
                icon={Megaphone}
                title={t("landing.news", "Latest news")}
              >
                <div className="grid gap-4 @sm:grid-cols-2 @lg:grid-cols-3">
                  {announcements.map((a) => (
                    <button
                      key={a.public_id}
                      type="button"
                      onClick={() => onOpenAnnouncement(a.public_id)}
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
              </PortalSectionFrame>
            );
          case "vacancies":
            return (
              <PortalSectionFrame
                key={section}
                id="careers"
                icon={Briefcase}
                title={t("landing.vacancies", "Open positions")}
              >
                <div className="grid gap-4 @sm:grid-cols-2 @lg:grid-cols-3">
                  {vacancies.map((v) => (
                    <button
                      key={v.public_id}
                      type="button"
                      onClick={() => onOpenVacancy(v.public_id)}
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
              </PortalSectionFrame>
            );
          case "holidays":
            return (
              <PortalSectionFrame
                key={section}
                id="holidays"
                icon={CalendarHeart}
                title={t("landing.holidays", "Upcoming holidays")}
              >
                <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border">
                  {holidays.map((h) => (
                    <HolidayRow
                      key={`${h.date}-${h.name}`}
                      holiday={h}
                      locale={locale}
                    />
                  ))}
                </ul>
              </PortalSectionFrame>
            );
          case "contact":
            return (
              <PortalSectionFrame
                key={section}
                id="contact"
                icon={MapPin}
                title={t("landing.contact", "Contact")}
              >
                <ContactSection landing={landing} branches={branches} />
              </PortalSectionFrame>
            );
        }
      })}
    </div>
  );
}

function PortalSectionFrame({
  id,
  icon: Icon,
  title,
  children,
}: {
  id: string;
  icon: typeof Megaphone;
  title: string;
  children: ReactNode;
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

/** The organisation in its own words, and the facts it chose to state. */
function AboutSection({
  landing,
  branchCount,
}: {
  landing: TenantLanding | null | undefined;
  branchCount: number | null;
}) {
  const { t, locale } = useT();
  const about = localised(landing, "about", locale);
  const facts: Array<{ icon: typeof Info; label: string }> = [];
  if (landing?.founded_year) {
    facts.push({
      icon: Landmark,
      label: t("landing.founded", "Founded :year", {
        year: landing.founded_year,
      }),
    });
  }
  if (landing?.sector) {
    facts.push({
      icon: Factory,
      label: t(`landing.sector_${landing.sector}`, landing.sector),
    });
  }
  if (landing?.staff_band) {
    facts.push({
      icon: Users,
      label: t("landing.staff", ":band employees", {
        band: landing.staff_band,
      }),
    });
  }
  if (landing?.show_branch_count && branchCount) {
    facts.push({
      icon: Building2,
      // One place is "1 branch", not "1 branches" (found on the live
      // portal, 2026-10-15).
      label:
        branchCount === 1
          ? t("landing.branch_count_one", "1 branch")
          : t("landing.branch_count", ":count branches", {
              count: branchCount,
            }),
    });
  }

  return (
    <div className="grid gap-6 @lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
      {about && (
        <p className="max-w-prose whitespace-pre-line text-base leading-relaxed text-foreground">
          {about}
        </p>
      )}
      {facts.length > 0 && (
        <ul className="grid gap-3 @sm:grid-cols-2 @lg:grid-cols-1">
          {facts.map(({ icon: Icon, label }) => (
            <li
              key={label}
              className="flex items-center gap-3 rounded-xl border border-border bg-card px-4 py-3 text-sm text-foreground"
            >
              <Icon
                className="h-4 w-4 shrink-0 text-primary"
                aria-hidden="true"
              />
              {label}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/** How to reach the organisation, and where its places are. */
function ContactSection({
  landing,
  branches,
}: {
  landing: TenantLanding | null | undefined;
  branches: LandingFeed["branches"];
}) {
  const { t, locale } = useT();
  const address = localised(landing, "address", locale);
  const hours = localised(landing, "hours", locale);
  const phone = landing?.support_phone;
  const email = landing?.support_email;
  const coordinates =
    landing?.latitude != null && landing?.longitude != null
      ? mapsUrl(landing.latitude, landing.longitude)
      : null;
  const row = "flex items-start gap-3 text-sm text-foreground";
  const icon = "mt-0.5 h-4 w-4 shrink-0 text-primary";
  const link =
    "rounded-sm underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring";
  const showBranches = Boolean(landing?.show_branches) && branches.length > 0;

  return (
    <div
      className={cn(
        "grid gap-6",
        showBranches && "@lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]",
      )}
    >
      <ul className="space-y-3 rounded-xl border border-border bg-card p-5">
        {address && (
          <li className={row}>
            <MapPin className={icon} aria-hidden="true" />
            <span className="whitespace-pre-line">{address}</span>
          </li>
        )}
        {hours && (
          <li className={row}>
            <Clock3 className={icon} aria-hidden="true" />
            <span className="whitespace-pre-line">{hours}</span>
          </li>
        )}
        {phone && (
          <li className={row}>
            <Phone className={icon} aria-hidden="true" />
            <a href={`tel:${phone.replace(/[^0-9+]/g, "")}`} className={link}>
              {phone}
            </a>
          </li>
        )}
        {email && (
          <li className={row}>
            <Mail className={icon} aria-hidden="true" />
            <a href={`mailto:${email}`} className={link}>
              {email}
            </a>
          </li>
        )}
        {coordinates && (
          <li className="pt-1">
            <Button variant="outline" size="sm" asChild>
              <a href={coordinates} target="_blank" rel="noopener noreferrer">
                <MapPin className="mr-2 h-3.5 w-3.5" aria-hidden="true" />
                {t("landing.open_in_maps", "Open in Maps")}
              </a>
            </Button>
          </li>
        )}
      </ul>
      {showBranches && (
        <div>
          <h3 className="mb-3 text-sm font-semibold text-foreground">
            {t("landing.our_places", "Our places")}
          </h3>
          <ul className="grid gap-3 @sm:grid-cols-2">
            {branches.map((b) => {
              const place =
                b.latitude != null && b.longitude != null
                  ? mapsUrl(b.latitude, b.longitude)
                  : null;
              return (
                <li
                  key={b.public_id}
                  className="rounded-xl border border-border bg-card p-4 text-sm"
                >
                  <p className="font-semibold text-foreground">
                    {(locale === "am" && b.name_am) || b.name}
                  </p>
                  {(b.address || b.city) && (
                    <p className="mt-1 text-muted-foreground">
                      {[b.address, b.city].filter(Boolean).join(", ")}
                    </p>
                  )}
                  <p className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                    {b.phone && (
                      <a
                        href={`tel:${b.phone.replace(/[^0-9+]/g, "")}`}
                        className={cn(link, "text-foreground")}
                      >
                        {b.phone}
                      </a>
                    )}
                    {place && (
                      <a
                        href={place}
                        target="_blank"
                        rel="noopener noreferrer"
                        className={cn(link, "font-medium text-primary")}
                      >
                        {t("landing.open_in_maps", "Open in Maps")}
                      </a>
                    )}
                  </p>
                </li>
              );
            })}
          </ul>
        </div>
      )}
    </div>
  );
}

const LINK_LABELS: Record<(typeof LINKS)[number], string> = {
  website: "Website",
  linkedin: "LinkedIn",
  facebook: "Facebook",
  x: "X",
  telegram: "Telegram",
  youtube: "YouTube",
  privacy: "Privacy",
  terms: "Terms",
};

/**
 * The footer: the organisation's links (its own site and networks, each
 * server-checked to be https on the network's own host), its privacy and
 * terms or ETHR's when it set none, the support email, the year and the
 * ETHR mark.
 */
function PortalFooter({
  name,
  landing,
}: {
  name: string | null;
  landing: TenantLanding | null | undefined;
}) {
  const { t, locale } = useT();
  const links = landing?.links ?? {};
  const social = (
    ["website", "linkedin", "facebook", "x", "telegram", "youtube"] as const
  ).filter((key) => Boolean(links[key]));
  const policies = [
    {
      key: "privacy" as const,
      href: links.privacy || `/${locale}/privacy`,
      label: t("landing.privacy", LINK_LABELS.privacy),
    },
    {
      key: "terms" as const,
      href: links.terms || `/${locale}/terms`,
      label: t("landing.terms", LINK_LABELS.terms),
    },
  ];
  const link =
    "rounded-sm underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring";

  return (
    <footer className="border-t border-border">
      <div className="mx-auto w-full max-w-6xl space-y-3 px-4 py-6 text-xs text-muted-foreground @sm:px-6">
        {social.length > 0 && (
          <nav
            aria-label={t("landing.links", "Links")}
            className="flex flex-wrap gap-x-5 gap-y-1"
          >
            {social.map((key) => (
              <a
                key={key}
                href={links[key] ?? undefined}
                target="_blank"
                rel="noopener noreferrer"
                className={cn(link, "font-medium text-foreground")}
              >
                {key === "website"
                  ? t("landing.website", LINK_LABELS.website)
                  : LINK_LABELS[key]}
              </a>
            ))}
          </nav>
        )}
        <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
          <p>
            © {new Date().getFullYear()} {name ?? "ETHR"}
          </p>
          <span className="flex flex-wrap gap-x-4 gap-y-1">
            {policies.map((p) => (
              <a
                key={p.key}
                href={p.href}
                className={link}
                {...(links[p.key]
                  ? { target: "_blank", rel: "noopener noreferrer" }
                  : {})}
              >
                {p.label}
              </a>
            ))}
          </span>
          {landing?.support_email && (
            <a className={link} href={`mailto:${landing.support_email}`}>
              {landing.support_email}
            </a>
          )}
          <p className="inline-flex items-center gap-1.5">
            <ShieldCheck className="h-3.5 w-3.5" aria-hidden="true" />
            {t("auth.powered_by", "Powered by ETHR")}
          </p>
        </div>
      </div>
    </footer>
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
