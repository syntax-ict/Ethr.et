import { useT } from "@/lib/i18n/useT";

type Translate = (key: string, fallback?: string) => string;

/** The genders the employee record stores (App\Enums\Gender). */
export const GENDERS = ["male", "female"] as const;
export type GenderValue = (typeof GENDERS)[number];

/**
 * A stored gender as a person reads it, or the stored text when it is not one
 * of the two. An empty value is left to the caller, which knows whether to say
 * "Not recorded" or show nothing.
 */
export function genderOptionLabel(t: Translate, value: string): string {
  switch (value) {
    case "male":
      return t("common.male", "Male");
    case "female":
      return t("common.female", "Female");
    default:
      return value;
  }
}

export function useGenderLabel() {
  const { t } = useT();
  return (value: string) => genderOptionLabel(t, value);
}
