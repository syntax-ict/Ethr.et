import { intlLocale } from "@/lib/i18n/config";

/**
 * A distance in the reader's language: metres to the whole metre, kilometres
 * to one decimal from 1000 m. Falls back to plain English units where the
 * runtime has no `Intl` unit support.
 */
export function formatDistance(meters: number, locale = "en"): string {
  const km = meters >= 1000;
  try {
    return new Intl.NumberFormat(intlLocale(locale), {
      style: "unit",
      unit: km ? "kilometer" : "meter",
      unitDisplay: "short",
      maximumFractionDigits: km ? 1 : 0,
    }).format(km ? meters / 1000 : Math.round(meters));
  } catch {
    return km ? `${(meters / 1000).toFixed(1)} km` : `${Math.round(meters)} m`;
  }
}
