"use client";

import Link from "next/link";
import { ChevronDown, HelpCircle } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";
import { useLocaleHref } from "@/lib/i18n/route-locale";
import { FAQ_CATEGORIES } from "./faq-items";

export function FaqContent() {
  const { t } = useT();
  const href = useLocaleHref();

  return (
    <div>
      {/* Hero */}
      <section className="relative overflow-hidden border-b border-border/50 bg-muted/20">
        <div className="pointer-events-none absolute inset-0 -z-10">
          <div className="absolute left-1/2 top-0 h-[400px] w-[600px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-primary/[0.04] blur-3xl" />
        </div>
        <div className="mx-auto max-w-7xl px-4 py-20 sm:px-6 sm:py-24 lg:px-8">
          <div className="mx-auto max-w-2xl text-center">
            <p className="text-sm font-semibold uppercase tracking-wider text-primary">
              {t("marketing.faq_page.overline", "FAQ")}
            </p>
            <h1 className="mt-2 text-4xl font-bold tracking-tight text-foreground sm:text-5xl">
              {t("marketing.faq_page.title", "Frequently Asked Questions")}
            </h1>
            <p className="mt-6 text-lg text-muted-foreground">
              {t(
                "marketing.faq_page.subtitle",
                "Everything you need to know about ETHR.",
              )}
            </p>
          </div>
        </div>
      </section>

      {/* Categories */}
      <section className="py-20 sm:py-24">
        <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
          <div className="space-y-14">
            {FAQ_CATEGORIES.map((cat) => (
              <div key={cat.key}>
                <h2 className="text-xs font-semibold uppercase tracking-wider text-primary">
                  {t(`marketing.faq_page.cat_${cat.key}`)}
                </h2>
                <div className="mt-4 divide-y divide-border">
                  {cat.items.map((item) => (
                    <details key={item} className="group">
                      <summary className="flex cursor-pointer items-center justify-between gap-4 py-5 text-sm font-medium text-foreground transition-colors hover:text-primary [&::-webkit-details-marker]:hidden">
                        <span>{t(`marketing.faq_page.${item}_q`)}</span>
                        <ChevronDown className="h-4 w-4 shrink-0 text-muted-foreground transition-transform duration-200 group-open:rotate-180" />
                      </summary>
                      <p className="pb-5 pr-8 text-sm leading-relaxed text-muted-foreground">
                        {t(`marketing.faq_page.${item}_a`)}
                      </p>
                    </details>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* CTA */}
      <section className="border-t border-border/50 bg-muted/20 py-16 sm:py-20">
        <div className="mx-auto max-w-2xl px-4 text-center sm:px-6 lg:px-8">
          <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10">
            <HelpCircle className="h-6 w-6 text-primary" />
          </div>
          <h2 className="mt-6 text-2xl font-bold tracking-tight text-foreground">
            {t("marketing.faq_page.cta_title", "Still have questions?")}
          </h2>
          <p className="mt-3 text-muted-foreground">
            {t(
              "marketing.faq_page.cta_subtitle",
              "Our team is happy to help. Reach out and we'll get back to you quickly.",
            )}
          </p>
          <div className="mt-8">
            <Button size="lg" asChild>
              <Link href={href("/contact")}>
                {t("marketing.faq_page.cta_button", "Contact us")}
              </Link>
            </Button>
          </div>
        </div>
      </section>
    </div>
  );
}
