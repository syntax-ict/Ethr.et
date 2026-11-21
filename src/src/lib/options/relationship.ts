import { useT } from "@/lib/i18n/useT";

type Translate = (key: string, fallback?: string) => string;

/**
 * How an emergency contact is related (App\Enums\Relationship). Rows saved
 * before this list hold free text, so a stored value that is not one of these
 * is shown as it was written and never rewritten.
 */
export const RELATIONSHIPS = [
  "spouse",
  "parent",
  "sibling",
  "child",
  "friend",
  "other",
] as const;
export type RelationshipValue = (typeof RELATIONSHIPS)[number];

export function relationshipLabel(t: Translate, value: string): string {
  switch (value) {
    case "spouse":
      return t("relationship.spouse", "Spouse");
    case "parent":
      return t("relationship.parent", "Parent");
    case "sibling":
      return t("relationship.sibling", "Sibling");
    case "child":
      return t("relationship.child", "Child");
    case "friend":
      return t("relationship.friend", "Friend");
    case "other":
      return t("relationship.other", "Other");
    default:
      return value;
  }
}

export function useRelationshipLabel() {
  const { t } = useT();
  return (value: string) => relationshipLabel(t, value);
}
