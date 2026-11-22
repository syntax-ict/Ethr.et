import { useT } from "@/lib/i18n/useT";

type Translate = (key: string, fallback?: string) => string;

/** The levels an education record sits at (App\Enums\EducationLevel). */
export const EDUCATION_LEVELS = [
  "primary",
  "secondary",
  "preparatory",
  "certificate",
  "diploma",
  "bachelor",
  "master",
  "doctorate",
  "other",
] as const;
export type EducationLevelValue = (typeof EDUCATION_LEVELS)[number];

export function educationLevelLabel(t: Translate, value: string): string {
  switch (value) {
    case "primary":
      return t("employee.education.level_primary", "Primary school");
    case "secondary":
      return t("employee.education.level_secondary", "Secondary school");
    case "preparatory":
      return t("employee.education.level_preparatory", "Preparatory");
    case "certificate":
      return t("employee.education.level_certificate", "Certificate");
    case "diploma":
      return t("employee.education.level_diploma", "Diploma");
    case "bachelor":
      return t("employee.education.level_bachelor", "Bachelor's degree");
    case "master":
      return t("employee.education.level_master", "Master's degree");
    case "doctorate":
      return t("employee.education.level_doctorate", "Doctorate");
    case "other":
      return t("employee.education.level_other", "Other");
    default:
      return value;
  }
}

export function useEducationLevelLabel() {
  const { t } = useT();
  return (value: string) => educationLevelLabel(t, value);
}
