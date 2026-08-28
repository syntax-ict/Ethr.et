import { isAxiosError } from "axios";
import { toast } from "sonner";
import type { ApiError } from "@/api/client";

/** Field name → first message. RFC-7807 `errors` is `string[]` per field; forms
 *  show one message at a time, so the array is flattened here rather than at
 *  every call site. */
export type FieldErrors = Record<string, string>;

/**
 * Pull the per-field validation map out of a 422.
 *
 * The API has always returned RFC-7807 with an `errors` object naming the exact
 * fields that failed (see `ApiError`), but the frontend only ever read `detail`
 * and dropped it in a toast. On a twenty-field form that told the user
 * "Validation Failed" and left them to guess which field. This is the
 * extractor that lets a form put the message under the input it belongs to.
 *
 * Returns an empty object for non-validation errors, so callers can always
 * spread the result without a null check.
 */
export function fieldErrors(error: unknown): FieldErrors {
  if (!isAxiosError<ApiError>(error)) return {};

  const errors = error.response?.data?.errors;
  if (!errors) return {};

  return Object.entries(errors).reduce<FieldErrors>(
    (out, [field, messages]) => {
      const first = Array.isArray(messages) ? messages[0] : messages;
      if (typeof first === "string" && first.length > 0) out[field] = first;
      return out;
    },
    {},
  );
}

/**
 * Toast for an error that has no field to attach to.
 *
 * When the error *is* a field-level validation failure, prefer surfacing it via
 * `fieldErrors()` under the inputs — a toast that says "The name field is
 * required" disappears after a few seconds and never points at the field. Pass
 * `skipValidation` to stay silent in that case and let the form render it.
 */
export function toastError(
  error: unknown,
  fallback: string,
  options?: { skipValidation?: boolean },
): void {
  if (options?.skipValidation && Object.keys(fieldErrors(error)).length > 0) {
    return;
  }

  if (isAxiosError<ApiError>(error) && error.response?.data?.detail) {
    toast.error(error.response.data.detail);
    return;
  }
  toast.error(fallback);
}
