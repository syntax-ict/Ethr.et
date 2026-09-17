"use client";

import { useState, useEffect } from "react";
import Link from "next/link";
import { Menu, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";
import { LanguageSwitcher } from "@/components/shared/language-switcher";

export function MarketingHeader() {
  const [mobileOpen, setMobileOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);
  const { t, locale } = useT();

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8);
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  const navLinks = [
    { href: "/features", label: t("marketing.nav.product", "Product") },
    { href: "/pricing", label: t("marketing.nav.pricing", "Pricing") },
    { href: "/faq", label: t("marketing.nav.faq", "FAQ") },
    { href: "/contact", label: t("marketing.nav.contact", "Contact") },
  ];

  return (
    <header
      className={`sticky top-0 z-50 w-full transition-[background-color,border-color,box-shadow] duration-200 ${
        scrolled
          ? "border-b border-border/60 bg-background/95 shadow-xs backdrop-blur-lg"
          : "border-b border-transparent bg-background/80 backdrop-blur-sm"
      }`}
    >
      <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
        {/* Logo */}
        <Link href="/" className="flex items-center gap-2.5">
          <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary shadow-sm">
            <span className="text-sm font-bold text-primary-foreground">E</span>
          </div>
          <span className="text-xl font-bold tracking-tight text-foreground">
            ETHR
          </span>
        </Link>

        {/* Desktop nav */}
        <nav className="hidden items-center gap-1 md:flex">
          {navLinks.map((link) => (
            <Link
              key={link.href}
              href={link.href}
              className="rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
            >
              {link.label}
            </Link>
          ))}
        </nav>

        {/* Desktop actions */}
        <div className="hidden items-center gap-2 md:flex">
          {/* The shared switcher, not a second implementation.

              This header mapped every entry in supportedLocales into a live
              setLocale button — including the four marked `coming_soon`, whose
              dictionaries are empty, so choosing one replaced the page with raw
              translation keys. LanguageSwitcher already disables and labels
              those, explains why in a tooltip, and reads the same list, so a
              locale added in translations.ts needs no second edit here. */}
          <LanguageSwitcher />

          <div className="mx-1 h-5 w-px bg-border" />

          <Button
            variant="ghost"
            size="sm"
            className="text-muted-foreground hover:text-foreground"
            asChild
          >
            <Link href="/login">{t("marketing.nav.login", "Log In")}</Link>
          </Button>
          <Button size="sm" className="shadow-sm" asChild>
            <Link href="/register">
              {t("marketing.nav.start_trial", "Start Free Trial")}
            </Link>
          </Button>
        </div>

        {/* Mobile toggle */}
        <Button
          variant="ghost"
          size="icon"
          className="md:hidden"
          onClick={() => setMobileOpen(!mobileOpen)}
          aria-label={t("nav.open_menu", "Open menu")}
        >
          {mobileOpen ? (
            <X className="h-5 w-5" />
          ) : (
            <Menu className="h-5 w-5" />
          )}
        </Button>
      </div>

      {/* Mobile menu */}
      {mobileOpen && (
        <div className="border-t bg-background md:hidden">
          <nav className="mx-auto max-w-7xl space-y-1 px-4 py-4">
            {navLinks.map((link) => (
              <Link
                key={link.href}
                href={link.href}
                onClick={() => setMobileOpen(false)}
                className="block rounded-lg px-3 py-2.5 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
              >
                {link.label}
              </Link>
            ))}

            <div className="my-3 h-px bg-border" />

            <div className="flex items-center gap-2 px-3 pb-1">
              <span className="text-xs font-medium text-muted-foreground">
                {t("language.label", "Language")}:
              </span>
              <LanguageSwitcher />
            </div>

            <div className="my-3 h-px bg-border" />

            <div className="flex flex-col gap-2 px-3 pt-1">
              <Button variant="outline" asChild>
                <Link href="/login" onClick={() => setMobileOpen(false)}>
                  {t("marketing.nav.login", "Log In")}
                </Link>
              </Button>
              <Button asChild>
                <Link href="/register" onClick={() => setMobileOpen(false)}>
                  {t("marketing.nav.start_trial", "Start Free Trial")}
                </Link>
              </Button>
            </div>
          </nav>
        </div>
      )}
    </header>
  );
}
