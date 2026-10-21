"use client";

import { useEffect, useRef, useState } from "react";
import { Languages, Monitor, Moon, Smartphone, Sun } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { ScopedLocaleProvider } from "@/lib/i18n/route-locale";
import { cn } from "@/lib/utils";
import type {
  TenantBrand,
  TenantLanding,
} from "@/lib/auth/use-auth-host-context";
import { useLandingFeed } from "@/features/auth/landing-feed";
import { PortalView } from "@/features/auth/components/portal-view";

// quiet-on-error: a preview of the public portal, which itself renders without its feed on failure; the preview shows exactly that.

const WIDTHS = { phone: 375, desktop: 1280 } as const;

/**
 * The portal as it will render, from the form being edited: the same
 * `PortalView` the public page uses, inside a frame at phone or desktop
 * width, in light or dark, in English or Amharic. The frame is scaled to
 * fit the card; the view lays itself out by the frame's width because its
 * breakpoints are container queries. The sign-in card holds a stand-in: the
 * real form is the shared one and does not change here.
 */
export function PortalPreview({
  name,
  brand,
  landing,
}: {
  name: string;
  brand: TenantBrand | null;
  landing: TenantLanding;
}) {
  const { t } = useT();
  const [width, setWidth] = useState<keyof typeof WIDTHS>("phone");
  const [dark, setDark] = useState(false);
  const [locale, setLocale] = useState<"en" | "am">("en");
  // The organisation's own feed, as the page will show it.
  const { data: feed, isLoading } = useLandingFeed({ mode: "client" });

  const frame = useRef<HTMLDivElement>(null);
  const inner = useRef<HTMLDivElement>(null);
  const [scale, setScale] = useState(1);
  const [height, setHeight] = useState(640);

  useEffect(() => {
    const outer = frame.current;
    const content = inner.current;
    if (!outer || !content || typeof ResizeObserver === "undefined") return;
    const measure = () => {
      const next = Math.min(1, outer.clientWidth / WIDTHS[width]);
      setScale(next);
      setHeight(content.offsetHeight * next);
    };
    measure();
    const observer = new ResizeObserver(measure);
    observer.observe(outer);
    observer.observe(content);
    return () => observer.disconnect();
  }, [width]);

  const segment = (active: boolean) =>
    cn(
      "inline-flex h-8 items-center gap-1.5 rounded-md px-2.5 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
      active
        ? "bg-background text-foreground shadow-xs"
        : "text-muted-foreground hover:text-foreground",
    );

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center gap-3">
        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
          {t("settings.branding_preview", "Preview")}
        </p>
        <div
          role="radiogroup"
          aria-label={t("settings.preview_width", "Preview width")}
          className="inline-flex rounded-lg border bg-muted/40 p-0.5"
        >
          <button
            type="button"
            role="radio"
            aria-checked={width === "phone"}
            className={segment(width === "phone")}
            onClick={() => setWidth("phone")}
          >
            <Smartphone className="h-3.5 w-3.5" aria-hidden="true" />
            {t("settings.preview_phone", "Phone")}
          </button>
          <button
            type="button"
            role="radio"
            aria-checked={width === "desktop"}
            className={segment(width === "desktop")}
            onClick={() => setWidth("desktop")}
          >
            <Monitor className="h-3.5 w-3.5" aria-hidden="true" />
            {t("settings.preview_desktop", "Desktop")}
          </button>
        </div>
        <div
          role="radiogroup"
          aria-label={t("settings.preview_theme", "Preview theme")}
          className="inline-flex rounded-lg border bg-muted/40 p-0.5"
        >
          <button
            type="button"
            role="radio"
            aria-checked={!dark}
            className={segment(!dark)}
            onClick={() => setDark(false)}
          >
            <Sun className="h-3.5 w-3.5" aria-hidden="true" />
            {t("settings.preview_light", "Light")}
          </button>
          <button
            type="button"
            role="radio"
            aria-checked={dark}
            className={segment(dark)}
            onClick={() => setDark(true)}
          >
            <Moon className="h-3.5 w-3.5" aria-hidden="true" />
            {t("settings.preview_dark", "Dark")}
          </button>
        </div>
        <div
          role="radiogroup"
          aria-label={t("settings.preview_language", "Preview language")}
          className="inline-flex rounded-lg border bg-muted/40 p-0.5"
        >
          <button
            type="button"
            role="radio"
            aria-checked={locale === "en"}
            className={segment(locale === "en")}
            onClick={() => setLocale("en")}
          >
            <Languages className="h-3.5 w-3.5" aria-hidden="true" />
            English
          </button>
          <button
            type="button"
            role="radio"
            aria-checked={locale === "am"}
            className={segment(locale === "am")}
            onClick={() => setLocale("am")}
          >
            አማርኛ
          </button>
        </div>
      </div>

      <div
        ref={frame}
        data-testid="portal-preview"
        data-width={width}
        data-theme={dark ? "dark" : "light"}
        className="overflow-hidden rounded-xl border border-border bg-muted"
      >
        <div style={{ height }} className="relative">
          <div
            ref={inner}
            className={cn(
              "absolute left-0 top-0 origin-top-left bg-background text-foreground",
              dark && "dark",
            )}
            style={{ width: WIDTHS[width], transform: `scale(${scale})` }}
          >
            <ScopedLocaleProvider locale={locale}>
              <PortalView
                name={name}
                brand={brand}
                landing={landing}
                feed={feed}
                feedLoading={isLoading}
                links={null}
                onOpenVacancy={() => {}}
              >
                <SignInStandIn />
              </PortalView>
            </ScopedLocaleProvider>
          </div>
        </div>
      </div>
    </div>
  );
}

/** The place of the sign-in form in the preview; the form itself is shared and unchanged. */
function SignInStandIn() {
  const { t } = useT();
  return (
    <div aria-hidden="true" className="space-y-3">
      <div className="h-9 w-9 rounded-lg bg-primary" />
      <p className="text-lg font-semibold text-foreground">
        {t("auth.sign_in", "Sign In")}
      </p>
      <div className="h-9 rounded-md border border-border bg-muted/40" />
      <div className="h-9 rounded-md border border-border bg-muted/40" />
      <div className="h-9 rounded-md bg-primary" />
      <p className="text-xs text-muted-foreground">
        {t(
          "settings.preview_form_note",
          "The sign-in form is the shared one and does not change here.",
        )}
      </p>
    </div>
  );
}
