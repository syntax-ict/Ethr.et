import type { useT } from "@/lib/i18n/useT";

type Translate = ReturnType<typeof useT>["t"];

/**
 * What the next Art. 85 sick day pays, as SickLeaveTiers decides it: the
 * first month in full, the next two at half pay, the last three unpaid, and
 * nothing more within the twelve months (K-50).
 */
export function sickNextTierLabel(t: Translate, tier: string): string {
  switch (tier) {
    case "half":
      return t("leave_page.sick_next_half");
    case "unpaid":
      return t("leave_page.sick_next_unpaid");
    case "beyond":
      return t("leave_page.sick_next_beyond");
    default:
      return t("leave_page.sick_next_full");
  }
}
