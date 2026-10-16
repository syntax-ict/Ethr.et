"use client";

import { useEffect, useState, type ReactNode } from "react";
import { Newspaper, ShieldCheck } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { LanguageSwitcher } from "@/components/shared/language-switcher";
import { OrgMark } from "@/components/shared/org-mark";
import type {
  TenantBrand,
  TenantLanding,
} from "@/lib/auth/use-auth-host-context";
import { LINKS, sectionOn } from "@/lib/auth/landing-text";
import { signInHref, withOrganisation } from "@/lib/auth/entry-organisation";

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
  };
}

/**
 * The organisation's header, on its portal and its news pages alike: its
 * mark and name (home), the News link when it publishes news, the language
 * and Sign In. It compacts once the page scrolls, giving content room while
 * keeping the way to the form one click away.
 *
 * `links` is null in the settings preview, where nothing should navigate
 * away from the settings screen; the same header draws, inert.
 */
export function PortalHeader({
  name,
  brand,
  links,
  current,
}: {
  name: string | null;
  brand: TenantBrand | null | undefined;
  links: PortalLinks | null;
  current: "home" | "news";
}) {
  const { t } = useT();
  const [compact, setCompact] = useState(false);
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
      <span className="min-w-0 truncate text-sm font-semibold text-foreground">
        {name ?? ""}
      </span>
    </>
  );
  const newsLabel = t("landing.news_nav", "News");

  return (
    <header
      className={cn(
        "sticky top-0 z-40 border-b border-border/60 bg-background/90 backdrop-blur transition-[padding] duration-200 motion-reduce:transition-none",
        compact ? "py-2" : "py-3.5",
      )}
    >
      <div className="mx-auto flex w-full max-w-6xl items-center gap-3 px-4 @sm:px-6">
        {links && current !== "home" ? (
          <a
            href={links.home}
            className="flex min-w-0 items-center gap-3 rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            {identity}
          </a>
        ) : (
          <div className="flex min-w-0 items-center gap-3">{identity}</div>
        )}
        <nav
          aria-label={t("landing.site_nav", "Organisation pages")}
          className="ml-auto flex shrink-0 items-center gap-1.5 @sm:gap-2"
        >
          {links?.news && (
            <Button
              variant={current === "news" ? "secondary" : "ghost"}
              size="sm"
              asChild
            >
              <a
                href={links.news}
                aria-current={current === "news" ? "page" : undefined}
              >
                <Newspaper className="h-4 w-4 @sm:mr-2" aria-hidden="true" />
                <span className="sr-only @sm:not-sr-only">{newsLabel}</span>
              </a>
            </Button>
          )}
          <LanguageSwitcher />
          <Button size="sm" asChild>
            <a href={links?.signIn ?? "#sign-in"}>
              {t("auth.sign_in", "Sign In")}
            </a>
          </Button>
        </nav>
      </div>
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

/**
 * The footer: the organisation's links (its own site and networks, each
 * server-checked to be https on the network's own host), its privacy and
 * terms or ETHR's when it set none, the support email, the year and the
 * ETHR mark.
 */
export function PortalFooter({
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
  current,
  children,
}: {
  name: string | null;
  brand: TenantBrand | null | undefined;
  landing: TenantLanding | null | undefined;
  links: PortalLinks | null;
  current: "home" | "news";
  children: ReactNode;
}) {
  return (
    <div className="@container flex min-h-screen flex-col bg-background">
      <PortalHeader name={name} brand={brand} links={links} current={current} />
      <main id="main" className="mx-auto w-full max-w-6xl flex-1 px-4 @sm:px-6">
        {children}
      </main>
      <PortalFooter name={name} landing={landing} />
    </div>
  );
}
