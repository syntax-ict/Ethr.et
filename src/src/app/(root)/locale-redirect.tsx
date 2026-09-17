"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import {
  AVAILABLE_LOCALES,
  LOCALE_COOKIE,
  localeHref,
  negotiateLocale,
  supportedLocales,
} from "@/lib/i18n/config";

function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
  return match ? decodeURIComponent(match[1]!) : null;
}

/**
 * Sends a visitor from an unprefixed public URL to the same page in their own
 * language.
 *
 * Preference order is the one a person would expect: a locale they picked
 * themselves (localStorage, then the cookie that mirrors it for the server),
 * then what their browser asks for, then the default. `middleware.ts` runs the
 * same negotiation a network hop earlier so most visitors never execute this —
 * but middleware does not run under `output: "export"`, which is still a live
 * possibility for this deployment, so correctness cannot depend on it.
 *
 * Renders nothing. That is the requirement, not an oversight: rendering the
 * landing page here and redirecting afterwards is exactly the "flash of the
 * wrong language" this route exists to remove — an English reader would read a
 * screenful of Amharic before being moved. A `<noscript>` block keeps the page
 * usable without JavaScript, labelled in each language's own name so it needs no
 * translation of its own.
 */
export function LocaleRedirect({ path }: { path: string }) {
  const router = useRouter();

  useEffect(() => {
    let stored: string | null = null;
    try {
      stored = localStorage.getItem("locale");
    } catch {
      // Private mode, or storage disabled entirely. The cookie and the browser's
      // own languages still answer the question.
    }

    const locale = negotiateLocale([
      stored,
      readCookie(LOCALE_COOKIE),
      ...(navigator.languages ?? [navigator.language]),
    ]);

    router.replace(localeHref(locale, path));
  }, [router, path]);

  return (
    <div className="min-h-screen bg-background">
      <noscript>
        <ul className="flex min-h-screen flex-col items-center justify-center gap-4">
          {supportedLocales
            .filter((locale) => AVAILABLE_LOCALES.includes(locale.code))
            .map((locale) => (
              <li key={locale.code}>
                <a
                  className="text-lg underline"
                  hrefLang={locale.code}
                  lang={locale.code}
                  href={localeHref(locale.code, path)}
                >
                  {locale.nativeName}
                </a>
              </li>
            ))}
        </ul>
      </noscript>
    </div>
  );
}
