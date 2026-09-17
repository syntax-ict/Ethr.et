"use client";

import Link from "next/link";
import { useT } from "@/lib/i18n/useT";
import { useLocaleHref } from "@/lib/i18n/route-locale";
import { pickLocalised, useSiteContent } from "@/features/marketing/api";

export function MarketingFooter() {
  const { t, locale } = useT();
  const href = useLocaleHref();
  const site = useSiteContent();

  // The name falls back to the literal that was hardcoded here, so an operator
  // who has set nothing sees exactly what shipped before.
  const brandName =
    pickLocalised(locale, site.platform_name_am, site.platform_name) ?? "ETHR";
  const tagline = pickLocalised(locale, site.tagline_am, site.tagline);

  // Named links rather than icons: lucide-react ships no brand marks, and the
  // nearest generic glyph would tell a visitor the wrong thing about where the
  // link goes. A word is unambiguous and needs no aria-label to explain it.
  const socials = [
    { key: "linkedin", href: site.social_linkedin, label: "LinkedIn" },
    { key: "x", href: site.social_x, label: "X" },
    { key: "facebook", href: site.social_facebook, label: "Facebook" },
  ].filter((entry): entry is typeof entry & { href: string } =>
    Boolean(entry.href),
  );

  // There is no Company column. It held About, Blog and Careers, all three
  // pointing at "#" — a footer heading that tells a visitor those pages exist
  // when none of them do, which is the same defect as the invented metrics one
  // section up, in link form. Restoring it is one array entry once a page
  // exists to link to; a dead link is not a placeholder, it is a claim.
  const footerSections = [
    {
      titleKey: "marketing.footer.product",
      fallback: "Product",
      links: [
        /* `marketing.nav.features`, not `marketing.nav.product`: that key
           reads "Product" in en.json, which put a link labelled "Product"
           directly under a heading of the same name. The fallback here said
           "Features" and hid it until the dictionary loaded. */
        {
          label: t("marketing.nav.features", "Features"),
          href: href("/features"),
        },
        {
          label: t("marketing.nav.pricing", "Pricing"),
          href: href("/pricing"),
        },
        { label: t("marketing.nav.faq", "FAQ"), href: href("/faq") },
        {
          label: t("marketing.nav.contact", "Contact"),
          href: href("/contact"),
        },
      ],
    },
    {
      titleKey: "marketing.footer.legal",
      fallback: "Legal",
      links: [
        {
          label: t("marketing.footer.privacy", "Privacy"),
          href: href("/privacy"),
        },
        { label: t("marketing.footer.terms", "Terms"), href: href("/terms") },
      ],
    },
  ];

  return (
    <footer className="border-t border-border/50 bg-muted/20">
      {/* Tibeb pattern — subtle Ethiopian geometric motif */}
      <div className="h-1 w-full bg-[repeating-linear-gradient(90deg,var(--interactive-primary)_0px,var(--interactive-primary)_8px,var(--brand-accent)_8px,var(--brand-accent)_16px,var(--interactive-primary)_16px,var(--interactive-primary)_24px,transparent_24px,transparent_32px)]" />

      <div className="mx-auto max-w-7xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
        <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-5">
          {/* Brand column */}
          <div className="lg:col-span-2">
            {/* Name and mark come from platform_settings when an operator has
                set them. The E-in-a-box is the fallback, not the default — it
                was duplicated verbatim here and in the header, so a rebrand
                meant finding both. */}
            <Link href={href("/")} className="inline-flex items-center gap-2.5">
              {site.logo_url ? (
                // eslint-disable-next-line @next/next/no-img-element -- the URL
                // is operator-supplied and arbitrary, so it cannot be in
                // next.config's remotePatterns; the same call branding-card.tsx
                // documents for tenant logos.
                <img
                  src={site.logo_url}
                  alt=""
                  className="h-8 w-8 rounded-lg object-contain"
                />
              ) : (
                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary shadow-sm">
                  <span className="text-sm font-bold text-primary-foreground">
                    E
                  </span>
                </div>
              )}
              <span className="text-lg font-bold tracking-tight text-foreground">
                {brandName}
              </span>
            </Link>
            <p className="mt-4 max-w-xs text-sm leading-relaxed text-muted-foreground">
              {tagline ??
                t(
                  "marketing.footer.description",
                  "Ethiopian Workforce Operating System. Enterprise-grade HR for Ethiopian organizations.",
                )}
            </p>

            {/* Social links appear only where an operator has given a URL.
                Rendering an icon that goes nowhere is the same defect as the
                footer's three remaining `#` links, one layer prettier. */}
            {socials.length > 0 && (
              <div className="mt-6 flex items-center gap-4">
                {socials.map(({ key, href, label }) => (
                  <a
                    key={key}
                    href={href}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-sm text-muted-foreground underline-offset-4 transition-colors hover:text-foreground hover:underline"
                  >
                    {label}
                  </a>
                ))}
              </div>
            )}
          </div>

          {/* Link columns */}
          {footerSections.map((section) => (
            <div key={section.titleKey}>
              <h4 className="text-sm font-semibold text-foreground">
                {t(section.titleKey, section.fallback)}
              </h4>
              <ul className="mt-4 space-y-3">
                {section.links.map((link) => (
                  <li key={link.href + link.label}>
                    <Link
                      href={link.href}
                      className="text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                      {link.label}
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>

        <div className="mt-12 flex flex-col items-center justify-between gap-4 border-t border-border/50 pt-8 sm:flex-row">
          <p className="text-xs text-muted-foreground">
            &copy;{" "}
            {t("marketing.footer.copyright", "2026 ETHR. All rights reserved.")}
          </p>
          <p className="text-xs text-muted-foreground">
            {t("marketing.footer.tagline", "Made in Ethiopia for Ethiopia")}
          </p>
        </div>
      </div>
    </footer>
  );
}
