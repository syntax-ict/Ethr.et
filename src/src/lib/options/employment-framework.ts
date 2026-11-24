import { useT } from "@/lib/i18n/useT";

type Translate = (key: string, fallback?: string) => string;

/** The bodies of employment law an organisation can be governed by (App\Enums\EmploymentFramework). */
export const EMPLOYMENT_FRAMEWORKS = [
  "labour_proclamation",
  "federal_civil_service",
  "regional_civil_service",
  "unspecified",
] as const;
export type EmploymentFrameworkValue = (typeof EMPLOYMENT_FRAMEWORKS)[number];

/** One set of words for every screen: the `employment_framework.*` keys. */
export function employmentFrameworkLabel(t: Translate, value: string): string {
  switch (value) {
    case "labour_proclamation":
      return t(
        "employment_framework.labour_proclamation",
        "Labour Proclamation",
      );
    case "federal_civil_service":
      return t(
        "employment_framework.federal_civil_service",
        "Federal civil service law",
      );
    case "regional_civil_service":
      return t(
        "employment_framework.regional_civil_service",
        "Regional civil service law",
      );
    case "unspecified":
      return t("employment_framework.unspecified", "Not specified");
    default:
      return value;
  }
}

export function useEmploymentFrameworkLabel() {
  const { t } = useT();
  return (value: string) => employmentFrameworkLabel(t, value);
}
