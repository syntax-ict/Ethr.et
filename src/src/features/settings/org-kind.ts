import type { OrganizationTypeOption } from "./api";

/** The groups `GET /organization-types` sorts the kinds of organisation into. */
export const ORG_KIND_GROUPS = [
  "private",
  "non_profit",
  "public",
  "international",
] as const;

/**
 * A group's heading in the reader's language. Literal `t()` calls, not a
 * template key, so the i18n gate checks every heading exists in each locale.
 */
export function orgKindGroupLabel(
  group: string,
  t: (key: string, fallback: string) => string,
): string {
  switch (group) {
    case "private":
      return t("settings.org_kind_group_private", "Private sector");
    case "non_profit":
      return t("settings.org_kind_group_non_profit", "Non-profit");
    case "public":
      return t("settings.org_kind_group_public", "Public sector");
    case "international":
      return t("settings.org_kind_group_international", "International");
    default:
      return group;
  }
}

/** The kind's own name: the catalogue carries English and Amharic. */
export function orgKindName(
  kind: Pick<OrganizationTypeOption, "name" | "name_am">,
  locale: string,
): string {
  return locale === "am" ? kind.name_am : kind.name;
}

/**
 * The kinds in their groups, in the catalogue's order. A kind with no known
 * group is never dropped: it follows, in a section of its own.
 */
export function groupOrgKinds(
  kinds: OrganizationTypeOption[],
): { group: string; kinds: OrganizationTypeOption[] }[] {
  const known: readonly string[] = ORG_KIND_GROUPS;
  const sections = ORG_KIND_GROUPS.map((group) => ({
    group: group as string,
    kinds: kinds.filter((kind) => kind.group === group),
  }));
  sections.push({
    group: "",
    kinds: kinds.filter((kind) => !known.includes(kind.group)),
  });

  return sections.filter((section) => section.kinds.length > 0);
}

/**
 * Splits the kinds into those suggested for a sector and the rest. A
 * suggestion, never a removal: `keep` (the kind already chosen) is always
 * suggested, and a sector no kind lists suggests everything.
 */
export function narrowOrgKinds(
  kinds: OrganizationTypeOption[],
  sector: string | null | undefined,
  keep?: string | null,
): { suggested: OrganizationTypeOption[]; rest: OrganizationTypeOption[] } {
  // An older API sends no `suggested_for`: nothing is narrowed then.
  const suggests = (kind: OrganizationTypeOption, base: string) =>
    (kind.suggested_for ?? []).includes(base);
  if (!sector || !kinds.some((kind) => suggests(kind, sector))) {
    return { suggested: kinds, rest: [] };
  }
  const suggested: OrganizationTypeOption[] = [];
  const rest: OrganizationTypeOption[] = [];
  for (const kind of kinds) {
    if (suggests(kind, sector) || kind.code === keep) {
      suggested.push(kind);
    } else {
      rest.push(kind);
    }
  }
  return { suggested, rest };
}
