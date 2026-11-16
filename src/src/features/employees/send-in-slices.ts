import type { CreateLoginsResult } from "@/features/onboarding/v2/types";

/**
 * How many people one request carries. The server takes 1,000 a request but
 * creates every login inside it, so a long list goes up in slices, one after
 * another (the device migration card slices at the same size).
 */
export const SIGN_IN_SLICE = 200;

export interface SlicedRun<R> {
  /** What each slice that went through answered, in order. */
  results: R[];
  /** How many ids went through. */
  sent: number;
  /** The ids a failed slice and every slice after it left unsent. */
  unsent: string[];
  /** The failure that stopped the run, or null when every slice went through. */
  error: unknown;
}

/**
 * Post `ids` in slices, one after another, and stop at the first slice that
 * fails. It never throws: what went through, and what did not, is the answer,
 * because a retry must send only the people not yet sent.
 */
export async function sendInSlices<R>(
  ids: string[],
  post: (slice: string[]) => Promise<R>,
  size: number = SIGN_IN_SLICE,
): Promise<SlicedRun<R>> {
  const results: R[] = [];
  let sent = 0;

  while (sent < ids.length) {
    const slice = ids.slice(sent, sent + size);
    try {
      results.push(await post(slice));
    } catch (error) {
      return { results, sent, unsent: ids.slice(sent), error };
    }
    sent += slice.length;
  }

  return { results, sent, unsent: [], error: null };
}

export type LoginTotals = Pick<
  CreateLoginsResult,
  "created" | "active" | "in_person" | "email_in_use" | "queued"
>;

export const NO_TOTALS: LoginTotals = {
  created: 0,
  active: 0,
  in_person: 0,
  email_in_use: 0,
  queued: 0,
};

/** The answers of several slices, added up. */
export function addTotals(
  totals: LoginTotals,
  results: LoginTotals[],
): LoginTotals {
  return results.reduce<LoginTotals>(
    (sum, r) => ({
      created: sum.created + r.created,
      active: sum.active + r.active,
      in_person: sum.in_person + r.in_person,
      email_in_use: sum.email_in_use + r.email_in_use,
      queued: sum.queued + r.queued,
    }),
    totals,
  );
}
