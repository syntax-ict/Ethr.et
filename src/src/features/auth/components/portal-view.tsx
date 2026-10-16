"use client";

import { type ReactNode } from "react";
import {
  ArrowRight,
  Briefcase,
  Building2,
  CalendarDays,
  CalendarHeart,
  ChevronDown,
  CircleHelp,
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
  Users,
} from "lucide-react";
import { useTheme } from "next-themes";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { OrgMark } from "@/components/shared/org-mark";
import { NewsCard } from "@/features/news/components/news-card";
import {
  PortalFooter,
  PortalHeader,
  SkipToContent,
  usePortalNav,
  visibleSections,
  type PortalLinks,
} from "./portal-chrome";
import type {
  PortalSection,
  TenantBrand,
  TenantLanding,
} from "@/lib/auth/use-auth-host-context";
import { coverPosition, panelBackground } from "@/lib/auth/landing-background";
import { localised, localisedFaqs, mapsUrl } from "@/lib/auth/landing-text";
import { paragraphs, segments } from "@/features/news/news-format";
import type { LandingFeed } from "@/features/auth/landing-feed";
import { SupportContacts } from "@/app/(auth)/auth-layout-client";
import { VacancyMeta } from "./vacancy-dialog";
import { formatDateOnly } from "@/lib/utils/date";
import { toEthiopian, formatEthiopian } from "@/lib/calendar/ethiopian";

export interface PortalViewProps {
  name: string | null;
  brand: TenantBrand | null | undefined;
  landing: TenantLanding | null | undefined;
  /** What the organisation publishes; `undefined` while it loads. */
  feed: LandingFeed | null | undefined;
  feedLoading: boolean;
  /** The context itself still loading: the headline is held back. */
  loading?: boolean;
  /**
   * Where the page's links lead (news page, articles). Null in the settings
   * preview, where the same page draws but nothing navigates away.
   */
  links: PortalLinks | null;
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
  links,
  onOpenVacancy,
  children,
}: PortalViewProps) {
  const { t, locale } = useT();
  const { resolvedTheme } = useTheme();
  const highContrast = resolvedTheme === "high-contrast";

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

  const sections = visibleSections(landing, feed);
  const nav = usePortalNav(sections, links);
  const footerNav = usePortalNav(sections, links, { footer: true });
  // Only to what is there: news once a story is published, contact once
  // the organisation gave a way to reach it.
  const heroLinks = links
    ? [
        ...(links.news && sections.includes("announcements")
          ? [
              {
                key: "news",
                href: links.news,
                label: t("landing.hero_news", "Read the latest news"),
              },
            ]
          : []),
        ...(sections.includes("contact")
          ? [
              {
                key: "contact",
                href: links.section("contact"),
                label: t("landing.hero_contact", "Contact us"),
              },
            ]
          : []),
      ]
    : [];

  return (
    <div
      id="top"
      className="@container flex min-h-screen flex-col bg-background"
    >
      <SkipToContent />
      <PortalHeader
        name={name}
        brand={brand}
        links={links}
        nav={nav}
        current="home"
      />

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
              <p className="mt-5 text-base font-medium text-primary-foreground/80">
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

            {heroLinks.length > 0 && (
              <p className="mt-5 flex flex-wrap gap-x-6 gap-y-2">
                {heroLinks.map((l) => (
                  <a
                    key={l.key}
                    href={l.href}
                    className="group inline-flex items-center gap-1.5 rounded-sm text-sm font-semibold text-primary-foreground underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-foreground"
                  >
                    {l.label}
                    <ArrowRight
                      className="h-4 w-4 transition-transform group-hover:translate-x-0.5 motion-reduce:transition-none"
                      aria-hidden="true"
                    />
                  </a>
                ))}
              </p>
            )}

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

      <main
        id="main"
        tabIndex={-1}
        className="w-full flex-1 focus:outline-none"
      >
        <PortalSections
          landing={landing}
          feed={feed}
          loading={feedLoading}
          links={links}
          sections={sections}
          name={name}
          onOpenVacancy={onOpenVacancy}
        />
      </main>

      <PortalFooter
        name={name}
        brand={brand}
        landing={landing}
        nav={footerNav}
        links={links}
      />
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
  links,
  sections,
  name,
  onOpenVacancy,
}: {
  landing: TenantLanding | null | undefined;
  feed: LandingFeed | null | undefined;
  loading: boolean;
  links: PortalLinks | null;
  /** The visible sections, in order (`visibleSections`). */
  sections: PortalSection[];
  name: string | null;
  onOpenVacancy: (publicId: string) => void;
}) {
  const { t, locale } = useT();

  if (loading && !feed) {
    // The same card grid the content lands in, so nothing jumps.
    return (
      <div className="mx-auto grid w-full max-w-6xl gap-4 px-4 py-14 @sm:grid-cols-2 @sm:px-6 @lg:grid-cols-3">
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
  const lead = announcements[0]?.featured ? announcements[0] : null;
  const name_ = name ?? "";

  if (sections.length === 0) return null;

  return (
    <div>
      {sections.map((section, index) => {
        // Every other section sits on a quiet band, so the page reads as
        // distinct parts without boxing each one in.
        const band = index % 2 === 1;
        switch (section) {
          case "about":
            return (
              <PortalSectionFrame
                key={section}
                id="about"
                icon={Info}
                band={band}
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
                band={band}
                title={t("landing.news", "Latest news")}
                description={t(
                  "landing.news_desc",
                  "News and announcements from :name.",
                  { name: name_ },
                )}
                action={
                  links?.news ? (
                    <a
                      href={links.news}
                      className="group inline-flex items-center gap-1 rounded-sm text-sm font-semibold text-primary underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                      {t("landing.all_news", "All news")}
                      <ArrowRight
                        className="h-4 w-4 transition-transform group-hover:translate-x-0.5 motion-reduce:transition-none"
                        aria-hidden="true"
                      />
                    </a>
                  ) : null
                }
              >
                <div className="grid gap-4 @sm:grid-cols-2 @lg:grid-cols-3">
                  {announcements.map((a) => (
                    <div
                      key={a.public_id}
                      className={cn(
                        "flex min-w-0",
                        a === lead &&
                          "@sm:col-span-2 @lg:col-span-2 @lg:row-span-2",
                        "[&>*]:flex-1",
                      )}
                    >
                      <NewsCard
                        item={a}
                        size={a === lead ? "feature" : "card"}
                        href={links ? links.article(a.public_id) : null}
                      />
                    </div>
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
                band={band}
                title={t("landing.vacancies", "Open positions")}
                description={t(
                  "landing.vacancies_desc",
                  "Roles you can apply for now.",
                )}
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
                band={band}
                title={t("landing.holidays", "Upcoming holidays")}
                description={t(
                  "landing.holidays_desc",
                  "Days the organisation is closed.",
                )}
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
          case "faq":
            return (
              <PortalSectionFrame
                key={section}
                id="faq"
                icon={CircleHelp}
                band={band}
                title={t("landing.faq", "Frequently asked questions")}
              >
                <FaqSection landing={landing} />
              </PortalSectionFrame>
            );
          case "contact":
            return (
              <PortalSectionFrame
                key={section}
                id="contact"
                icon={MapPin}
                band={band}
                title={t("landing.contact", "Contact")}
                description={t(
                  "landing.contact_desc",
                  "How to reach us, and where to find us.",
                )}
              >
                <ContactSection landing={landing} branches={branches} />
              </PortalSectionFrame>
            );
        }
      })}
    </div>
  );
}

/**
 * One section of the page: full width, on a quiet band when `band` is set,
 * with its content in the page's column. The heading says what the section
 * is and, when it helps, a line says what it is for.
 */
function PortalSectionFrame({
  id,
  icon: Icon,
  title,
  description,
  action,
  band = false,
  children,
}: {
  id: string;
  icon: typeof Megaphone;
  title: string;
  description?: string;
  /** A link beside the heading, such as "All news". */
  action?: ReactNode;
  band?: boolean;
  children: ReactNode;
}) {
  const headingId = `${id}-heading`;
  return (
    <section
      id={id}
      aria-labelledby={headingId}
      className={cn("scroll-mt-20", band && "bg-muted/50")}
    >
      <div className="mx-auto w-full max-w-6xl px-4 py-14 @sm:px-6 @lg:py-16">
        <div className="mb-6 flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
          <div className="min-w-0">
            <h2
              id={headingId}
              className="flex items-center gap-2.5 text-2xl font-bold tracking-tight text-foreground"
            >
              <Icon
                className="h-6 w-6 shrink-0 text-primary"
                aria-hidden="true"
              />
              {title}
            </h2>
            {description && (
              <p className="mt-1.5 max-w-prose text-base text-muted-foreground">
                {description}
              </p>
            )}
          </div>
          {action}
        </div>
        {children}
      </div>
    </section>
  );
}

/**
 * The organisation's questions, each opening to its answer. Native
 * disclosure (`details`), so it works by keyboard and screen reader and with
 * no script at all. Answers are text: paragraphs from blank lines, https
 * addresses as links, as a news story reads.
 */
function FaqSection({
  landing,
}: {
  landing: TenantLanding | null | undefined;
}) {
  const { t, locale } = useT();
  const faqs = localisedFaqs(landing, locale);

  return (
    <div className="max-w-3xl divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
      {faqs.map((faq, i) => (
        <details key={i} className="group">
          <summary className="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 text-left text-base font-medium text-foreground transition-colors hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring [&::-webkit-details-marker]:hidden">
            <span className="min-w-0">{faq.question}</span>
            <ChevronDown
              className="h-4 w-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-180 motion-reduce:transition-none"
              aria-hidden="true"
            />
          </summary>
          <div className="space-y-3 px-5 pb-5 text-sm leading-relaxed text-muted-foreground">
            {paragraphs(faq.answer).map((paragraph, j) => (
              <p key={j} className="whitespace-pre-line">
                {segments(paragraph).map((segment, k) =>
                  segment.kind === "link" ? (
                    <a
                      key={k}
                      href={segment.href}
                      target="_blank"
                      rel="noopener noreferrer nofollow ugc"
                      className="break-words text-primary underline underline-offset-4 hover:no-underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                      {segment.href}
                      <span className="sr-only">
                        {" "}
                        {t("news.opens_new_tab", "(opens in a new tab)")}
                      </span>
                    </a>
                  ) : (
                    <span key={k}>{segment.text}</span>
                  ),
                )}
              </p>
            ))}
          </div>
        </details>
      ))}
    </div>
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
