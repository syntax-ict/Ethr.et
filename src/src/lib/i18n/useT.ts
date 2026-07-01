import { useCallback, useEffect, useState } from "react";
import { t, getLocale } from "./translations";

/**
 * React hook that returns a translation function bound to the current locale.
 * Re-renders when the locale changes (via localStorage + custom event).
 *
 * Usage:
 *   const { t } = useT();
 *   return <button>{t('common.save')}</button>;
 */
export function useT() {
  const [locale, setLocaleState] = useState<string>(() => getLocale());

  useEffect(() => {
    function onLocaleChange(e: Event) {
      setLocaleState((e as CustomEvent<string>).detail);
    }
    window.addEventListener("locale-changed", onLocaleChange);
    return () => window.removeEventListener("locale-changed", onLocaleChange);
  }, []);

  const translate = useCallback(
    (key: string, fallback?: string): string => {
      const result = t(key, locale);
      // t() returns the key itself when not found — use explicit fallback if provided
      return result !== key ? result : (fallback ?? key);
    },
    [locale],
  );

  return { t: translate, locale };
}
