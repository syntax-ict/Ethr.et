import { describe, it, expect } from "vitest";
import { narrowOrgKinds } from "@/features/settings/org-kind";
import type { OrganizationTypeOption } from "@/features/settings/api";
import {
  industriesOfType,
  preselectedIndustry,
  signupTypeOf,
  typicalPolicies,
  typicalWorkforce,
} from "@/features/onboarding/sector";
import type { Industry } from "@/features/onboarding/v2/types";

function kind(
  code: string,
  suggested_for: string[],
  policy_profile: "ethiopia_public" | "ethiopia_private" | null = null,
): OrganizationTypeOption {
  return {
    code,
    name: code,
    name_am: code,
    description: null,
    group: "private",
    parent_code: null,
    suggested_for,
    policy_profile,
  } as OrganizationTypeOption;
}

function industry(
  key: string,
  base: string,
  profile?: string,
  policy?: string,
): Industry {
  return {
    key,
    label: key,
    label_am: key,
    base,
    icon: "x",
    group: "g",
    profile,
    policy,
  };
}

describe("narrowOrgKinds", () => {
  const kinds = [
    kind("government", ["government", "general"]),
    kind("sole_proprietor", ["general"]),
  ];

  it("suggests the kinds listing the sector and sets the rest aside", () => {
    const { suggested, rest } = narrowOrgKinds(kinds, "government");
    expect(suggested.map((k) => k.code)).toEqual(["government"]);
    expect(rest.map((k) => k.code)).toEqual(["sole_proprietor"]);
  });

  it("suggests everything for no sector or one no kind lists", () => {
    expect(narrowOrgKinds(kinds, null).rest).toEqual([]);
    expect(narrowOrgKinds(kinds, "").rest).toEqual([]);
    expect(narrowOrgKinds(kinds, "mystery").rest).toEqual([]);
  });

  it("keeps the kind already chosen", () => {
    const { suggested, rest } = narrowOrgKinds(
      kinds,
      "government",
      "sole_proprietor",
    );
    expect(suggested.map((k) => k.code)).toEqual([
      "government",
      "sole_proprietor",
    ]);
    expect(rest).toEqual([]);
  });
});

describe("sector helpers", () => {
  const all = [
    industry("ministry", "government", "standard_office", "ethiopia_public"),
    industry("bureau", "government", "shift_based", "ethiopia_public"),
    industry("bank", "bank", "standard_office", "ethiopia_private"),
  ];

  it("reads general and empty as no sector", () => {
    expect(signupTypeOf("general")).toBeNull();
    expect(signupTypeOf("")).toBeNull();
    expect(signupTypeOf(undefined)).toBeNull();
    expect(signupTypeOf("bank")).toBe("bank");
  });

  it("finds the industries of a type by key or base", () => {
    expect(industriesOfType(all, null)).toEqual([]);
    expect(industriesOfType(all, "government").map((i) => i.key)).toEqual([
      "ministry",
      "bureau",
    ]);
    expect(industriesOfType(all, "bank").map((i) => i.key)).toEqual(["bank"]);
  });

  it("preselects an exact key, else the only industry, else none", () => {
    expect(preselectedIndustry(industriesOfType(all, "bank"), "bank")).toBe(
      "bank",
    );
    expect(
      preselectedIndustry(industriesOfType(all, "government"), "government"),
    ).toBeNull();
    expect(preselectedIndustry([all[2]], "finance")).toBe("bank");
  });

  it("lists the workforce profiles of a base, none for an unknown base", () => {
    expect([...typicalWorkforce(all, "government")].sort()).toEqual([
      "shift_based",
      "standard_office",
    ]);
    expect(typicalWorkforce(all, "nothing").size).toBe(0);
    expect(typicalWorkforce(all, undefined).size).toBe(0);
  });

  it("joins the kind's policy and the industry's, minus empties", () => {
    const set = typicalPolicies(
      all[2],
      kind("government", [], "ethiopia_public"),
    );
    expect([...set].sort()).toEqual(["ethiopia_private", "ethiopia_public"]);
    expect(typicalPolicies(undefined, undefined).size).toBe(0);
  });
});
