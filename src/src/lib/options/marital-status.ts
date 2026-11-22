import { useT } from "@/lib/i18n/useT";

type Translate = (key: string, fallback?: string) => string;

/** The marital statuses the employee record stores (App\Enums\MaritalStatus). */
export const MARITAL_STATUSES = [
  "single",
  "married",
  "divorced",
  "widowed",
] as const;
export type MaritalStatusValue = (typeof MARITAL_STATUSES)[number];

/** One set of words for every screen: the `marital_status.*` keys. */
export function maritalStatusLabel(t: Translate, value: string): string {
  switch (value) {
    case "single":
      return t("marital_status.single", "Single");
    case "married":
      return t("marital_status.married", "Married");
    case "divorced":
      return t("marital_status.divorced", "Divorced");
    case "widowed":
      return t("marital_status.widowed", "Widowed");
    default:
      return value;
  }
}

export function useMaritalStatusLabel() {
  const { t } = useT();
  return (value: string) => maritalStatusLabel(t, value);
}
