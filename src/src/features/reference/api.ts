import { useQuery } from "@tanstack/react-query";

import { apiClient } from "@/api/client";
import type { operations } from "@/api/generated";
import { localizedField } from "@/features/onboarding/v2/localized";

/** `GET /lookups`: the pick lists, with a `version` that changes when any entry does. */
export type Lookups =
  operations["lookup.index"]["responses"][200]["content"]["application/json"];

/** The pick lists: regions, the sub-cities of Addis Ababa, towns and banks. */
export type ReferenceKind = keyof Lookups["items"];

/** One entry of a pick list. `code` is what a record stores, and never changes. */
export type ReferenceItem = Lookups["items"][ReferenceKind][number];

/**
 * `GET /lookups`: the regions, the sub-cities of Addis Ababa, the main towns
 * and the banks, in one request. Public, so a sign-up form can use it. The
 * service worker keeps the answer for offline use, and an hour is as stale as
 * a form should read it in memory.
 */
export function useLookups() {
  return useQuery<Lookups>({
    queryKey: ["lookups"],
    queryFn: async () => (await apiClient.get("/lookups")).data,
    staleTime: 60 * 60 * 1000,
  });
}

/** An entry's name in the reader's language, falling back to English. */
export function referenceName(item: ReferenceItem, locale: string): string {
  return localizedField(item, "name", locale);
}

/** What a form stores for one of these fields: the code, and the text beside it. */
export interface ReferenceValue {
  /** The list entry's code, or null when the text was typed or is older than the list. */
  code: string | null;
  text: string;
}

export const EMPTY_REFERENCE: ReferenceValue = { code: null, text: "" };
