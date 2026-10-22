"use client";

import { useEffect, useState, type ReactNode } from "react";
import {
  ArrowUpRight,
  Clock3,
  Globe,
  Mail,
  MapPin,
  Menu,
  Phone,
  ShieldCheck,
} from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Sheet } from "@/components/ui/sheet";
import { LanguageSwitcher } from "@/components/shared/language-switcher";
import { OrgMark } from "@/components/shared/org-mark";
import type {
  PortalSection,
  TenantBrand,
  TenantLanding,
} from "@/lib/auth/use-auth-host-context";
import {
  hasAbout,
  hasContact,
  hasFaq,
  hasServices,
  LINKS,
  localised,
  sectionOn,
  sectionOrder,
} from "@/lib/auth/landing-text";
import { signInHref, withOrganisation } from "@/lib/auth/entry-organisation";
import type { LandingFeed } from "@/features/auth/landing-feed";

/**
 * Where the organisation's public pages lead: its portal (the sign-in page),
 * its news page and each article, and the Sign In button. On the shared host
 * every link carries `?org=` so the next page stays this organisation's; on
 * its own host the host already says it.
 */
export interface PortalLinks {
  home: string;
  /** Null when the organisation publishes no news page. */
  news: string | null;
  article: (publicId: string) => string;
  signIn: string;
  /** A section of the portal, from wherever the visitor is. */
  section: (id: string) => string;
}

export function portalLinks(
  landing: TenantLanding | null | undefined,
  carry: string | null,
  onPortal: boolean,
): PortalLinks {
  const home = signInHref(carry);
  return {
    home,
    news: sectionOn(landing, "announcements")
      ? withOrganisation("/news", carry)
      : null,
    article: (publicId) =>
      withOrganisation(`/news/${encodeURIComponent(publicId)}`, carry),
    // On the portal the form is on the page; elsewhere it is one page away.
    signIn: onPortal ? "#sign-in" : `${home}#sign-in`,
    section: (id) => (onPortal ? `#${id}` : `${home}#${id}`),
  };
}

/**
 * The portal sections that will draw: switched on by the organisation, in its
 * order, and with something to show. The header and footer link to exactly
 * these, so no link ever leads to a section that is not there.
 */
export function visibleSections(
  landing: TenantLanding | null | undefined,
  feed: LandingFeed | null | undefined,
): PortalSection[] {
  const present: Record<PortalSection, boolean> = {
    about: hasAbout(landing, feed?.branch_count ?? null),
    services: hasServices(landing),
    announcements: (feed?.announcements ?? []).length > 0,
    vacancies: (feed?.vacancies ?? []).length > 0,
    holidays: (feed?.holidays ?? []).length > 0,
    faq: hasFaq(landing),
    contact: hasContact(landing, (feed?.branches ?? []).length),
  };
  return sectionOrder(landing).filter(
    (section) => sectionOn(landing, section) && present[section],
  );
}

export interface PortalNavItem {
  key: string;
  label: string;
  href: string;
}

/** The section anchors, as the portal's own section ids. */
const SECTION_IDS: Record<PortalSection, string> = {
  about: "about",
  services: "services",
  announcements: "news",
  vacancies: "careers",
  holidays: "holidays",
  faq: "faq",
  contact: "contact",
};

/**
 * The organisation's navigation, from its visible sections. News leads to the
 * news page (every story, searchable) when the organisation has one, else to
 * the portal's own news section. Holidays and questions are left to the
 * footer (`footer: true`): the header keeps to the destinations a visitor
 * came for (about, services, news, careers, contact).
 */
export function usePortalNav(
  sections: PortalSection[],
  links: PortalLinks | null,
  { footer = false }: { footer?: boolean } = {},
): PortalNavItem[] {
  const { t } = useT();
  if (!links) return [];
  const labels: Record<PortalSection, string> = {
    about: t("landing.nav_about", "About"),
    services: t("landing.nav_services", "Services"),
    announcements: t("landing.news_nav", "News"),
    vacancies: t("landing.nav_careers", "Careers"),
    holidays: t("landing.holidays", "Upcoming holidays"),
    faq: t("landing.nav_faq", "FAQ"),
    contact: t("landing.nav_contact", "Contact"),
  };
  const items: PortalNavItem[] = [];
  for (const section of sections) {
    if ((section === "holidays" || section === "faq") && !footer) continue;
    items.push({
      key: section,
      label: labels[section],
      href:
        section === "announcements" && links.news
          ? links.news
          : links.section(SECTION_IDS[section]),
    });
  }
  // No menu entry leads to an empty page: News appears once there is a story
  // to read, like every other section.
  return items;
}

/**
 * The organisation's header, on its portal and its news pages alike: its mark
 * and name (home), its sections, the language and Sign In. On a wide screen
 * the sections are inline; on a phone they open in a menu, so the bar never
 * crowds and nothing overlaps. It compacts once the page scrolls.
 *
 * `links` is null in the settings preview, where nothing should navigate away
 * from the settings screen; the same header draws, inert.
 */
export function PortalHeader({
  name,
  brand,
  links,
  nav,
  current,
}: {
  name: string | null;
  brand: TenantBrand | null | undefined;
  links: PortalLinks | null;
  nav: PortalNavItem[];
  current: "home" | "news";
}) {
  const { t } = useT();
  const [compact, setCompact] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);
  useEffect(() => {
    const onScroll = () => setCompact(window.scrollY > 24);
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  const identity = (
    <>
      <OrgMark
        name={name}
        logoUrl={brand?.logo_url}
        darkLogoUrl={brand?.logo_dark_url}
        className="h-9 w-9 shrink-0"
        initialClassName="text-sm"
      />
      <span className="min-w-0 truncate text-sm font-semibold text-foreground @sm:text-base">
        {name ?? ""}
      </span>
    </>
  );
  const isCurrent = (item: PortalNavItem) =>
    current === "news" && item.key === "announcements";
  const navLink =
    "rounded-md px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring";

  return (
    <header
      className={cn(
        "sticky top-0 z-40 border-b border-border/60 bg-background/90 backdrop-blur transition-[padding] duration-200 motion-reduce:transition-none",
        compact ? "py-2" : "py-3",
      )}
    >
      <div className="mx-auto flex w-full max-w-6xl items-center gap-3 px-4 @sm:px-6">
        {links ? (
          <a
            href={current === "home" ? "#top" : links.home}
            // With no organisation resolved the mark is a bare initial and the
            // name is empty, so the link needs a name of its own.
            aria-label={name ? undefined : t("news.home", "Home")}
            className="flex min-w-0 flex-1 items-center gap-3 rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring @lg:flex-none"
          >
            {identity}
          </a>
        ) : (
          <div className="flex min-w-0 flex-1 items-center gap-3 @lg:flex-none">
            {identity}
          </div>
        )}

        {nav.length > 0 && (
          <nav
            aria-label={t("landing.site_nav", "Organisation pages")}
            className="ml-4 hidden flex-1 items-center gap-1 @lg:flex"
          >
            {nav.map((item) => (
              <a
                key={item.key}
                href={item.href}
                aria-current={isCurrent(item) ? "page" : undefined}
                className={cn(
                  navLink,
                  isCurrent(item)
                    ? "bg-muted text-foreground"
                    : "text-muted-foreground hover:bg-muted hover:text-foreground",
                )}
              >
                {item.label}
              </a>
            ))}
          </nav>
        )}

        <div className="ml-auto flex shrink-0 items-center gap-2">
          <div className="hidden @sm:block">
            <LanguageSwitcher />
          </div>
          <Button size="sm" asChild>
            <a href={links?.signIn ?? "#sign-in"}>
              {t("auth.sign_in", "Sign In")}
            </a>
          </Button>
          {nav.length > 0 && (
            <Button
              variant="ghost"
              size="icon"
              className="h-9 w-9 @lg:hidden"
              aria-label={t("landing.open_menu", "Open the menu")}
              aria-expanded={menuOpen}
              onClick={() => setMenuOpen(true)}
            >
              <Menu className="h-5 w-5" aria-hidden="true" />
            </Button>
          )}
        </div>
      </div>

      <Sheet
        open={menuOpen}
        onOpenChange={setMenuOpen}
        side="right"
        title={name ?? t("landing.site_nav", "Organisation pages")}
      >
        <div className="flex h-14 items-center gap-3 border-b border-border px-5">
          {identity}
        </div>
        <nav
          aria-label={t("landing.site_nav", "Organisation pages")}
          className="flex flex-col gap-1 p-3"
        >
          {nav.map((item) => (
            <a
              key={item.key}
              href={item.href}
              onClick={() => setMenuOpen(false)}
              aria-current={isCurrent(item) ? "page" : undefined}
              className={cn(
                "flex h-11 items-center rounded-md px-3 text-base font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                isCurrent(item)
                  ? "bg-muted text-foreground"
                  : "text-foreground hover:bg-muted",
              )}
            >
              {item.label}
            </a>
          ))}
        </nav>
        <div className="mt-auto space-y-3 border-t border-border p-4">
          <LanguageSwitcher />
          <Button className="w-full" asChild>
            <a
              href={links?.signIn ?? "#sign-in"}
              onClick={() => setMenuOpen(false)}
            >
              {t("auth.sign_in", "Sign In")}
            </a>
          </Button>
        </div>
      </Sheet>
    </header>
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

/** The opening of the organisation's about text, whole sentences, short. */
function summary(text: string | null, max = 180): string | null {
  if (!text) return null;
  const flat = text.replace(/\s+/g, " ").trim();
  if (flat.length <= max) return flat;
  const cut = flat.slice(0, max);
  const end = Math.max(cut.lastIndexOf(". "), cut.lastIndexOf("። "));
  return end > 60
    ? cut.slice(0, end + 1)
    : `${cut.slice(0, cut.lastIndexOf(" "))}…`;
}

/**
 * The footer: a navy band under the tibeb stripe, the same in light and dark,
 * with three columns. The organisation's identity, the opening of its about
 * text and its own links (its site and networks, each server-checked to be
 * https on the network's host); its pages; how to reach it. Under them the
 * year, its privacy and terms (or ETHR's when it set none) and the ETHR mark.
 * A column with nothing to say does not draw.
 */
export function PortalFooter({
  name,
  brand,
  landing,
  nav,
  links,
}: {
  name: string | null;
  brand?: TenantBrand | null;
  landing: TenantLanding | null | undefined;
  nav?: PortalNavItem[];
  links?: PortalLinks | null;
}) {
  const { t, locale } = useT();
  const own = landing?.links ?? {};
  const social = (
    ["website", "linkedin", "facebook", "x", "telegram", "youtube"] as const
  ).filter((key) => Boolean(own[key]));
  const policies = [
    {
      key: "privacy" as const,
      href: own.privacy || `/${locale}/privacy`,
      label: t("landing.privacy", LINK_LABELS.privacy),
    },
    {
      key: "terms" as const,
      href: own.terms || `/${locale}/terms`,
      label: t("landing.terms", LINK_LABELS.terms),
    },
  ];
  const about = summary(localised(landing, "about", locale));
  const address = localised(landing, "address", locale);
  const hours = localised(landing, "hours", locale);
  const phone = landing?.support_phone ?? null;
  const email = landing?.support_email ?? null;
  const hasReach = Boolean(address || hours || phone || email);
  const pages = nav ?? [];

  const link =
    "rounded-sm underline-offset-4 hover:text-sidebar-foreground hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring";
  const heading = "text-sm font-semibold text-sidebar-foreground";
  const muted = "text-sidebar-foreground/75";

  return (
    <footer className="bg-sidebar text-sm text-sidebar-foreground">
      <div
        aria-hidden="true"
        className="h-1 w-full bg-[repeating-linear-gradient(90deg,rgba(255,255,255,0.3)_0px,rgba(255,255,255,0.3)_8px,rgba(232,168,56,0.6)_8px,rgba(232,168,56,0.6)_16px,rgba(255,255,255,0.3)_16px,rgba(255,255,255,0.3)_24px,transparent_24px,transparent_32px)]"
      />
      <div className="mx-auto grid w-full max-w-6xl gap-10 px-4 py-12 @sm:px-6 @lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)]">
        <div className="min-w-0 space-y-4">
          <div className="flex items-center gap-3">
            <OrgMark
              name={name}
              logoUrl={brand?.logo_url}
              darkLogoUrl={brand?.logo_dark_url}
              surface="dark"
              className="h-10 w-10 shrink-0 bg-sidebar-foreground/10 ring-0"
              initialClassName="text-base"
            />
            <p className="min-w-0 text-base font-semibold text-sidebar-foreground">
              {name ?? "ETHR"}
            </p>
          </div>
          {about && (
            <p className={cn("max-w-sm leading-relaxed", muted)}>{about}</p>
          )}
          {social.length > 0 && (
            <ul
              aria-label={t("landing.links", "Links")}
              className="flex flex-wrap gap-2"
            >
              {social.map((key) => (
                <li key={key}>
                  <a
                    href={own[key] ?? undefined}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-1.5 rounded-full border border-sidebar-foreground/20 px-3 py-1.5 text-xs font-medium text-sidebar-foreground transition-colors hover:bg-sidebar-foreground/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  >
                    {key === "website" && (
                      <Globe className="h-3.5 w-3.5" aria-hidden="true" />
                    )}
                    {key === "website"
                      ? t("landing.website", LINK_LABELS.website)
                      : LINK_LABELS[key]}
                    <ArrowUpRight
                      className="h-3 w-3 opacity-70"
                      aria-hidden="true"
                    />
                  </a>
                </li>
              ))}
            </ul>
          )}
        </div>

        {(pages.length > 0 || links) && (
          <nav
            aria-label={t("landing.footer_pages", "Pages")}
            className="min-w-0"
          >
            <h2 className={heading}>{t("landing.footer_pages", "Pages")}</h2>
            <ul className={cn("mt-4 space-y-2.5", muted)}>
              {pages.map((item) => (
                <li key={item.key}>
                  <a href={item.href} className={link}>
                    {item.label}
                  </a>
                </li>
              ))}
              {links && (
                <li>
                  <a href={links.signIn} className={link}>
                    {t("landing.employee_sign_in", "Employee sign in")}
                  </a>
                </li>
              )}
            </ul>
          </nav>
        )}

        {hasReach && (
          <div className="min-w-0">
            <h2 className={heading}>{t("landing.contact", "Contact")}</h2>
            <ul className={cn("mt-4 space-y-3", muted)}>
              {address && (
                <li className="flex items-start gap-2.5">
                  <MapPin
                    className="mt-0.5 h-4 w-4 shrink-0"
                    aria-hidden="true"
                  />
                  <span className="whitespace-pre-line">{address}</span>
                </li>
              )}
              {hours && (
                <li className="flex items-start gap-2.5">
                  <Clock3
                    className="mt-0.5 h-4 w-4 shrink-0"
                    aria-hidden="true"
                  />
                  <span className="whitespace-pre-line">{hours}</span>
                </li>
              )}
              {phone && (
                <li className="flex items-start gap-2.5">
                  <Phone
                    className="mt-0.5 h-4 w-4 shrink-0"
                    aria-hidden="true"
                  />
                  <a
                    href={`tel:${phone.replace(/[^0-9+]/g, "")}`}
                    className={link}
                  >
                    {phone}
                  </a>
                </li>
              )}
              {email && (
                <li className="flex items-start gap-2.5">
                  <Mail
                    className="mt-0.5 h-4 w-4 shrink-0"
                    aria-hidden="true"
                  />
                  <a href={`mailto:${email}`} className={cn(link, "break-all")}>
                    {email}
                  </a>
                </li>
              )}
            </ul>
          </div>
        )}
      </div>

      <div className="border-t border-sidebar-foreground/15">
        <div
          className={cn(
            "mx-auto flex w-full max-w-6xl flex-col gap-3 px-4 py-5 text-xs @sm:flex-row @sm:items-center @sm:justify-between @sm:px-6",
            muted,
          )}
        >
          <p>
            © {new Date().getFullYear()} {name ?? "ETHR"}
          </p>
          <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
            {policies.map((p) => (
              <a
                key={p.key}
                href={p.href}
                className={link}
                {...(own[p.key]
                  ? { target: "_blank", rel: "noopener noreferrer" }
                  : {})}
              >
                {p.label}
              </a>
            ))}
            <span className="inline-flex items-center gap-1.5">
              <ShieldCheck className="h-3.5 w-3.5" aria-hidden="true" />
              {t("auth.powered_by", "Powered by ETHR")}
            </span>
          </div>
        </div>
      </div>
    </footer>
  );
}

/** The first focusable thing on every organisation page. */
export function SkipToContent() {
  const { t } = useT();
  return (
    <a
      href="#main"
      className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-background focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-foreground focus:shadow-lg focus:ring-2 focus:ring-ring"
    >
      {t("landing.skip_to_content", "Skip to content")}
    </a>
  );
}

/**
 * The frame around every organisation page that is not the portal itself:
 * the same header and footer, the content between. The container query root
 * is the same as the portal's, so the news pages lay out by the same
 * breakpoints.
 */
export function PortalChrome({
  name,
  brand,
  landing,
  links,
  sections,
  current,
  children,
}: {
  name: string | null;
  brand: TenantBrand | null | undefined;
  landing: TenantLanding | null | undefined;
  links: PortalLinks | null;
  /** The portal's visible sections (`visibleSections`), for both menus. */
  sections: PortalSection[];
  current: "home" | "news";
  children: ReactNode;
}) {
  const nav = usePortalNav(sections, links);
  const footerNav = usePortalNav(sections, links, { footer: true });
  return (
    <div className="@container flex min-h-screen flex-col bg-background">
      <SkipToContent />
      <PortalHeader
        name={name}
        brand={brand}
        links={links}
        nav={nav}
        current={current}
      />
      <main
        id="main"
        tabIndex={-1}
        className="mx-auto w-full max-w-6xl flex-1 px-4 focus:outline-none @sm:px-6"
      >
        {children}
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
