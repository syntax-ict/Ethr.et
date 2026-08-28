"use client";

import { useState, useEffect, useRef } from "react";
import Link from "next/link";
import { Menu, X, Globe, ChevronDown } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";
import { setLocale, supportedLocales } from "@/lib/i18n/translations";

export function MarketingHeader() {
  const [mobileOpen, setMobileOpen] = useState(false);
  const [langOpen, setLangOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);
  const langRef = useRef<HTMLDivElement>(null);
  const { t, locale } = useT();

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8);
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  useEffect(() => {
    function handleClickOutside(e: MouseEvent) {
      if (langRef.current && !langRef.current.contains(e.target as Node)) {
        setLangOpen(false);
      }
    }
    if (langOpen) {
      document.addEventListener("mousedown", handleClickOutside);
      return () =>
        document.removeEventListener("mousedown", handleClickOutside);
    }
  }, [langOpen]);

  const navLinks = [
    { href: "/features", label: t("marketing.nav.product", "Product") },
    { href: "/pricing", label: t("marketing.nav.pricing", "Pricing") },
    { href: "/faq", label: t("marketing.nav.faq", "FAQ") },
    { href: "/contact", label: t("marketing.nav.contact", "Contact") },
  ];

  const currentLocale = supportedLocales.find((l) => l.code === locale);

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
          <div ref={langRef} className="relative">
            <button
              onClick={() => setLangOpen(!langOpen)}
              className="flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
            >
              <Globe className="h-4 w-4" />
              <span>{currentLocale?.nativeName}</span>
              <ChevronDown
                className={`h-3 w-3 transition-transform duration-150 ${langOpen ? "rotate-180" : ""}`}
              />
            </button>
            {langOpen && (
              <div className="absolute right-0 top-full mt-1 w-40 rounded-lg border border-border bg-popover p-1 shadow-lg">
                {supportedLocales.map((loc) => (
                  <button
                    key={loc.code}
                    onClick={() => {
                      setLocale(loc.code);
                      setLangOpen(false);
                    }}
                    className={`flex w-full items-center rounded-md px-3 py-2 text-sm transition-colors hover:bg-accent ${
                      locale === loc.code
                        ? "font-medium text-foreground"
                        : "text-muted-foreground"
                    }`}
                  >
                    {loc.nativeName}
                  </button>
                ))}
              </div>
            )}
          </div>

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
              {supportedLocales.map((loc) => (
                <button
                  key={loc.code}
                  onClick={() => {
                    setLocale(loc.code);
                    setMobileOpen(false);
                  }}
                  className={`rounded-md px-2.5 py-1 text-sm transition-colors ${
                    locale === loc.code
                      ? "bg-primary text-primary-foreground"
                      : "bg-muted text-muted-foreground hover:text-foreground"
                  }`}
                >
                  {loc.nativeName}
                </button>
              ))}
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
