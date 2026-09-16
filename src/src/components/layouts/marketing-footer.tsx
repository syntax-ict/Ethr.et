"use client";

import Link from "next/link";
import { useT } from "@/lib/i18n/useT";

export function MarketingFooter() {
  const { t } = useT();

  const footerSections = [
    {
      titleKey: "marketing.footer.product",
      fallback: "Product",
      links: [
        { label: t("marketing.nav.product", "Features"), href: "/features" },
        { label: t("marketing.nav.pricing", "Pricing"), href: "/pricing" },
        { label: t("marketing.nav.faq", "FAQ"), href: "/faq" },
        { label: t("marketing.nav.contact", "Contact"), href: "/contact" },
      ],
    },
    {
      titleKey: "marketing.footer.company",
      fallback: "Company",
      links: [
        { label: t("marketing.footer.about", "About"), href: "#" },
        { label: t("marketing.footer.blog", "Blog"), href: "#" },
        { label: t("marketing.footer.careers", "Careers"), href: "#" },
      ],
    },
    {
      titleKey: "marketing.footer.legal",
      fallback: "Legal",
      links: [
        { label: t("marketing.footer.privacy", "Privacy"), href: "/privacy" },
        { label: t("marketing.footer.terms", "Terms"), href: "/terms" },
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
            <Link href="/" className="inline-flex items-center gap-2.5">
              <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary shadow-sm">
                <span className="text-sm font-bold text-primary-foreground">
                  E
                </span>
              </div>
              <span className="text-lg font-bold tracking-tight text-foreground">
                ETHR
              </span>
            </Link>
            <p className="mt-4 max-w-xs text-sm leading-relaxed text-muted-foreground">
              {t(
                "marketing.footer.description",
                "Ethiopian Workforce Operating System. Enterprise-grade HR for Ethiopian organizations.",
              )}
            </p>
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
