import type { OrganizationTypeOption } from "@/features/settings/api";
import type { Industry } from "./v2/types";

/**
 * What an organisation said it is at sign-up, as a type the lists can narrow
 * on. "General" and an empty answer name no sector, so they narrow nothing
 * (K-49: ten catalogue industries carry the base `general`).
 */
export function signupTypeOf(raw: string | null | undefined): string | null {
  if (!raw || raw === "general") return null;
  return raw;
}

/** The industries of a sign-up type: its own key, or the base they share. */
export function industriesOfType(
  industries: Industry[],
  type: string | null,
): Industry[] {
  if (type === null) return [];
  return industries.filter((ind) => ind.key === type || ind.base === type);
}

/**
 * The industry the sign-up answer starts selected on: the one with that key,
 * or the only one of that kind; otherwise none, so the question is asked.
 */
export function preselectedIndustry(
  ofType: Industry[],
  type: string | null,
): string | null {
  return (
    ofType.find((ind) => ind.key === type)?.key ??
    (ofType.length === 1 ? ofType[0].key : null)
  );
}

/** The workforce profiles the industries of one base usually run on. */
export function typicalWorkforce(
  industries: Industry[],
  base: string | null | undefined,
): Set<string> {
  const out = new Set<string>();
  if (!base) return out;
  for (const ind of industries) {
    if (ind.base === base && ind.profile) out.add(ind.profile);
  }
  return out;
}

/** The policy profiles an industry and a kind in law each start from. */
export function typicalPolicies(
  industry: Industry | undefined,
  kind: OrganizationTypeOption | undefined,
): Set<string> {
  const out = new Set<string>();
  if (kind?.policy_profile) out.add(kind.policy_profile);
  if (industry?.policy) out.add(industry.policy);
  return out;
}
