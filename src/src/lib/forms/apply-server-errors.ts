import { isAxiosError } from "axios";
import type { FieldValues, Path, UseFormSetError } from "react-hook-form";

import type { ApiError } from "@/api/client";
import { fieldErrors } from "@/lib/errors";

/**
 * What was left over after mapping a failed submit onto the form's fields.
 *
 * `rootMessage` is what belongs in the summary at the top of the form: either
 * the server's `detail` for a non-validation failure, or — for a 422 — the
 * messages whose field names the form does not actually render.
 */
export interface ServerErrorOutcome {
  /** Field names that were matched to a control and set as inline errors. */
  applied: string[];
  /** Field names the server rejected that this form has no control for. */
  unmatched: string[];
  /** Message for the form-level summary, or `null` when every error landed on a field. */
  rootMessage: string | null;
}

/**
 * Map an RFC-7807 failure onto React Hook Form's per-field error state.
 *
 * The API has always answered a 422 with `errors: { field: [message] }` naming
 * exactly what failed, and `fieldErrors()` has always been able to read it —
 * but nothing bridged that map into RHF, so server-side rules surfaced as a
 * toast or a single grey line above the form while the offending input stayed
 * unmarked. Client-side Zod rules got inline treatment; server-side rules did
 * not, even though those are the ones a user cannot anticipate (uniqueness,
 * cross-field consistency, plan limits).
 *
 * Laravel names nested fields in dot notation (`steps.0.shift_id`), which is
 * the same path syntax RHF uses, so those map across without translation.
 *
 * A field the form does not render is *not* silently dropped — it is returned
 * in `unmatched` and folded into `rootMessage`, because an error nobody can see
 * and nobody can fix is the failure mode this whole helper exists to end.
 *
 * @param setError  RHF's `setError` for the form being submitted.
 * @param error     The rejected value from the mutation (any thrown value is safe).
 * @param knownFields Paths the form actually renders. Anything outside this list
 *   goes to the summary instead of `setError`, which would otherwise register an
 *   error on a field that never blurs and can never be cleared, permanently
 *   blocking submit.
 * @param fallback  Used when the server gave no usable text at all.
 */
export function applyServerErrors<TFieldValues extends FieldValues>(
  setError: UseFormSetError<TFieldValues>,
  error: unknown,
  knownFields: readonly Path<TFieldValues>[],
  fallback: string,
): ServerErrorOutcome {
  const known = new Set<string>(knownFields);
  const fields = fieldErrors(error);
  const entries = Object.entries(fields);

  const applied: string[] = [];
  const unmatched: string[] = [];

  for (const [field, message] of entries) {
    if (known.has(field)) {
      // Focus the first rejected field. Long forms scroll, and a message
      // rendered below the fold reads as "nothing happened" — the same
      // dead-end as no message at all.
      setError(
        field as Path<TFieldValues>,
        { type: "server", message },
        { shouldFocus: applied.length === 0 },
      );
      applied.push(field);
    } else {
      unmatched.push(field);
    }
  }

  if (unmatched.length > 0) {
    return {
      applied,
      unmatched,
      rootMessage: unmatched.map((field) => fields[field]).join(" "),
    };
  }

  if (applied.length > 0) {
    return { applied, unmatched, rootMessage: null };
  }

  // Not a field-level failure: 403 plan limit, 409 conflict, 500, network drop.
  const detail = isAxiosError<ApiError>(error)
    ? error.response?.data?.detail
    : undefined;

  return { applied, unmatched, rootMessage: detail || fallback };
}

/**
 * True when the request never reached the server, so the caller can say
 * "check your connection" rather than blaming the user's input.
 */
export function isNetworkError(error: unknown): boolean {
  return isAxiosError(error) && !error.response;
}
