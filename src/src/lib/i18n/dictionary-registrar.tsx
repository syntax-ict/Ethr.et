"use client";

import { registerLocale } from "./translations";

/**
 * Puts the public strings in place before anything below it renders.
 *
 * During render rather than in an effect, and that is the whole point: an effect
 * runs *after* the first paint, which is exactly the render that would otherwise
 * show raw translation keys and force React to discard the server's HTML. React
 * renders a parent before its children, so registering here means every
 * component underneath sees the dictionary on its very first render — server and
 * client produce identical output, and hydration has nothing to reconcile.
 *
 * Writing to a module-level store during render is impure, which is normally
 * worth avoiding. It is safe here because the write is idempotent and derived
 * entirely from props: re-running it under StrictMode or a concurrent retry
 * stores the same object. The full dictionary still arrives later through the
 * usual dynamic import and replaces this subset with a superset of it, so
 * nothing a reader sees changes when it does.
 */
export function DictionaryRegistrar({
  locale,
  dictionary,
  children,
}: {
  locale: string;
  dictionary: Record<string, string> | null;
  children: React.ReactNode;
}) {
  if (dictionary) registerLocale(locale, dictionary);

  return <>{children}</>;
}
