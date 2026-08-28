"use client";

import { Check, ChevronDown, Globe } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { setLocale, supportedLocales } from "@/lib/i18n/translations";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";

/**
 * The one reusable language switcher — reads `supportedLocales` from
 * `lib/i18n/translations.ts` rather than keeping its own list, so a locale
 * added there appears here with no second edit.
 */
export function LanguageSwitcher() {
  const { t, locale } = useT();
  const current =
    supportedLocales.find((l) => l.code === locale) ?? supportedLocales[0];

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <button
          type="button"
          className="flex items-center gap-1.5 rounded-lg border border-border/60 bg-background px-3 py-1.5 text-xs font-medium text-foreground transition-colors hover:bg-muted"
          aria-label={t("language.change", "Change language")}
        >
          <Globe
            className="h-3.5 w-3.5 text-muted-foreground"
            aria-hidden="true"
          />
          {current.nativeName}
          <ChevronDown
            className="h-3 w-3 text-muted-foreground"
            aria-hidden="true"
          />
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-52">
        {supportedLocales.map((loc) => {
          const comingSoon = loc.status === "coming_soon";

          // A stub locale is listed but not selectable: its dictionary is
          // empty, so choosing it produced a UI of raw key names. Disabled +
          // labelled is honest; hiding it would lose the roadmap signal.
          if (comingSoon) {
            return (
              <Tooltip key={loc.code}>
                <TooltipTrigger asChild>
                  <div>
                    <DropdownMenuItem
                      disabled
                      className="justify-between"
                      // Radix disables pointer events on disabled items, which
                      // would swallow the hover the tooltip needs.
                      onSelect={(e) => e.preventDefault()}
                    >
                      <span>{loc.nativeName}</span>
                      <span className="rounded-full bg-neutral-soft px-1.5 py-0.5 text-[10px] font-medium text-neutral-on-soft">
                        {t("language.coming_soon", "Coming soon")}
                      </span>
                    </DropdownMenuItem>
                  </div>
                </TooltipTrigger>
                <TooltipContent side="left">
                  {t(
                    "language.coming_soon_hint",
                    "This language is not translated yet. English and Amharic are fully available.",
                  )}
                </TooltipContent>
              </Tooltip>
            );
          }

          return (
            <DropdownMenuItem
              key={loc.code}
              className="cursor-pointer justify-between"
              onClick={() => setLocale(loc.code)}
            >
              <span>{loc.nativeName}</span>
              {locale === loc.code && (
                <Check
                  className="h-3.5 w-3.5 text-primary"
                  aria-hidden="true"
                />
              )}
            </DropdownMenuItem>
          );
        })}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
