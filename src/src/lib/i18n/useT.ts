import { useCallback, useEffect, useState, useSyncExternalStore } from "react";
import {
  getLocale,
  preloadLocale,
  syncDocumentLang,
  translateStatic,
  DEFAULT_LOCALE,
} from "./translations";
import { useRouteLocale } from "./route-locale";

/**
 * The locale is external state — it lives in localStorage and changes via a
 * `locale-changed` window event — so it is read with `useSyncExternalStore`
 * rather than mirrored into component state from an effect.
 *
 * The previous shape (`useState(DEFAULT_LOCALE)` plus an effect that immediately
 * called `setLocaleState(getLocale())`) re-rendered every component using this
 * hook on mount, which is every page in the app. `getServerSnapshot` returns the
 * default so server and first client render still agree.
 */
function subscribeToLocale(onStoreChange: () => void): () => void {
  window.addEventListener("locale-changed", onStoreChange);
  return () => window.removeEventListener("locale-changed", onStoreChange);
}

export function useT() {
  /**
   * On `/am/*` and `/en/*` the URL is the answer, and it is known on the
   * server. Off those routes it is `null` and the stored preference wins, which
   * is the only signal the dashboard has.
   *
   * Both hooks run unconditionally — `useSyncExternalStore` is not skipped when
   * a route locale exists — because a conditional hook would change the hook
   * order between the marketing tree and the app tree.
   */
  const routeLocale = useRouteLocale();
  const storedLocale = useSyncExternalStore(
    subscribeToLocale,
    getLocale,
    () => DEFAULT_LOCALE,
  );
  const locale = routeLocale ?? storedLocale;

  // Translation dictionaries load asynchronously. This forces one re-render once
  // the active locale's strings are in memory, so the first paint's fallbacks are
  // replaced by real translations. The setState is inside a promise callback, not
  // synchronous in the effect body, so it costs one render only when a dictionary
  // actually arrives.
  const [, setDictionaryVersion] = useState(0);

  useEffect(() => {
    let cancelled = false;

    // Keep <html lang> equal to the locale actually rendered. Inside the
    // locale-prefixed tree the server already emitted the right value and this
    // is a no-op; everywhere else the layout emits DEFAULT_LOCALE and a reader
    // whose stored preference differs is only knowable after hydration, because
    // that preference lives in localStorage.
    //
    // Here rather than in a one-off provider effect because this hook is the
    // single place that knows the resolved locale, and syncDocumentLang is a
    // no-op when the attribute already matches — so the repetition costs an
    // equality check, not a DOM write.
    syncDocumentLang(locale);

    preloadLocale(locale).then(() => {
      if (!cancelled) setDictionaryVersion((v) => v + 1);
    });

    return () => {
      cancelled = true;
    };
  }, [locale]);

  /**
   * `replacements` substitutes `:name` placeholders, matching the convention the
   * backend lang files already use (`'throttle' => '... :seconds ...'`).
   *
   * Without it the only way to get a value into a translated string was to pass a
   * template literal as the *fallback* — which works right up until the key exists,
   * at which point the translation wins and the interpolated values vanish with no
   * error. That silently dropped the employee count on /employees. Interpolating
   * inside the string also lets Amharic put the number where its grammar wants it,
   * which prefix/suffix concatenation cannot do.
   */
  const translate = useCallback(
    (
      key: string,
      fallback?: string,
      replacements?: Record<string, string | number>,
    ): string => {
      return translateStatic(key, locale, fallback, replacements);
    },
    [locale],
  );

  return { t: translate, locale };
}
