import { isAxiosError } from "axios";

/**
 * The per-field messages of an RFC-7807 validation error, or none.
 *
 * The landing page's forms show each refusal beside its field: a sentence
 * the claim rule refused, a field the section does not use, one row too
 * many. The toast says the save failed; this says where.
 */
export function fieldErrors(err: unknown): Record<string, string> {
  if (!isAxiosError(err)) return {};
  const errors = (err.response?.data as { errors?: unknown } | undefined)
    ?.errors;
  if (!errors || typeof errors !== "object") return {};
  const out: Record<string, string> = {};
  for (const [field, messages] of Object.entries(
    errors as Record<string, unknown>,
  )) {
    const first = Array.isArray(messages) ? messages[0] : messages;
    if (typeof first === "string") out[field] = first;
  }
  return out;
}
