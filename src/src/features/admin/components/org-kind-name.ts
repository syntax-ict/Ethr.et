"use client";

import { useOrganizationTypes } from "@/features/settings/api";
import { orgKindName } from "@/features/settings/org-kind";
import { useT } from "@/lib/i18n/useT";

/**
 * Names a kind in law from the catalogue Settings already reads, in the
 * reader's language. A code the catalogue does not list (or while it has not
 * loaded) reads as the stored code, so a failed catalogue never hides the kind.
 */
export function useKindNamer(): (code: string) => string {
  const { locale } = useT();
  const kinds = useOrganizationTypes();

  return (code) => {
    const found = kinds.data?.find((kind) => kind.code === code);
    return found ? orgKindName(found, locale) : code;
  };
}
