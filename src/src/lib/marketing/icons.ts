/**
 * The icon names a landing-page row may carry, exactly as
 * `App\Support\MarketingContent::ICONS` lists them. The console offers these
 * and the API refuses any other; the landing page maps each to a lucide
 * component in `components/marketing/landing/marketing-icons.ts`, and
 * `marketing-content-snapshot.test.ts` checks the committed snapshot never
 * names one outside this list.
 */
export const MARKETING_ICONS = [
  "clock",
  "wallet",
  "calendar-days",
  "wifi-off",
  "building-2",
  "languages",
  "users",
  "smartphone",
  "bar-chart-3",
  "shield",
  "landmark",
  "hospital",
  "factory",
  "graduation-cap",
  "hotel",
  "heart",
  "briefcase",
  "file-text",
  "map-pin",
  "lock",
  "scroll-text",
  "check-circle-2",
  "receipt",
  "qr-code",
  "bell",
  "clipboard-list",
  "calculator",
  "table",
  "signal-zero",
  "file-clock",
] as const;

export type MarketingIcon = (typeof MARKETING_ICONS)[number];

export function isMarketingIcon(
  name: string | null | undefined,
): name is MarketingIcon {
  return (
    typeof name === "string" &&
    (MARKETING_ICONS as readonly string[]).includes(name)
  );
}
