"use client";

import { useCallback, useState } from "react";
import {
  useForm,
  type DefaultValues,
  type FieldValues,
  type Path,
  type UseFormProps,
  type UseFormReturn,
} from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import type { ZodType } from "zod";

import { applyServerErrors, isNetworkError } from "./apply-server-errors";

export interface UseZodFormOptions<TSchema extends FieldValues> extends Omit<
  UseFormProps<TSchema>,
  "resolver" | "defaultValues"
> {
  schema: ZodType<TSchema>;
  defaultValues: DefaultValues<TSchema>;
  /**
   * Paths this form renders a control for. Server errors naming anything else
   * go to the summary instead of being pinned to a field that cannot be seen
   * or cleared. Defaults to the keys of `defaultValues`, which is correct for
   * every flat form; pass it explicitly for nested/array fields.
   */
  fields?: readonly Path<TSchema>[];
}

export interface UseZodFormReturn<
  TSchema extends FieldValues,
> extends UseFormReturn<TSchema> {
  /** Form-level message for `<FormErrorSummary>`; null when the form is clean. */
  rootError: string | null;
  setRootError: (message: string | null) => void;
  /**
   * Wraps `handleSubmit`. Runs Zod first (RHF does that), then awaits the
   * submit handler and routes any rejection to the right place: per-field
   * inline errors for a 422, the summary for everything else.
   */
  submit: (
    handler: (values: TSchema) => Promise<unknown>,
    fallbackMessage: string,
  ) => (event?: React.BaseSyntheticEvent) => Promise<void>;
}

/**
 * The house form hook: React Hook Form + Zod + RFC-7807 server errors.
 *
 * Three things were being re-decided per form, and mostly decided differently:
 *
 * 1. **When validation runs.** RHF defaults to `onSubmit`, which means a user
 *    fills twenty fields before learning the second one was wrong. `onTouched`
 *    validates a field when it first blurs and live-corrects after that, so a
 *    mistake is reported next to where it was made, while the fix is still
 *    cheap. It is also the mode that does not shout at a field the user has not
 *    reached yet.
 *
 * 2. **Where server errors go.** See `applyServerErrors` — the API has always
 *    named the failing fields and the frontend has mostly thrown that away.
 *
 * 3. **Whether a failed submit leaves the form usable.** `isSubmitting` is
 *    RHF's, so the button re-enables on rejection without every call site
 *    remembering a `finally`.
 *
 * Deliberately not included: auto-save and the unsaved-changes prompt. Those
 * apply to page and wizard forms only (per the Form Pattern Library), not to
 * the dialog forms that are most of this codebase, and the app already has
 * `useUnsavedChangesWarning` for the cases that want it.
 */
export function useZodForm<TSchema extends FieldValues>({
  schema,
  defaultValues,
  fields,
  ...options
}: UseZodFormOptions<TSchema>): UseZodFormReturn<TSchema> {
  const form = useForm<TSchema>({
    // `as never` only bridges zodResolver's generic to RHF's; the schema and
    // the form share one type parameter, so the values stay checked.
    resolver: zodResolver(schema as never),
    defaultValues,
    mode: "onTouched",
    ...options,
  });

  const [rootError, setRootError] = useState<string | null>(null);

  const knownFields = (fields ??
    (Object.keys(
      defaultValues as object,
    ) as Path<TSchema>[])) as readonly Path<TSchema>[];

  const { handleSubmit, setError } = form;

  const submit = useCallback(
    (handler: (values: TSchema) => Promise<unknown>, fallbackMessage: string) =>
      handleSubmit(async (values) => {
        setRootError(null);
        try {
          await handler(values);
        } catch (error) {
          if (isNetworkError(error)) {
            setRootError(fallbackMessage);
            return;
          }
          const { rootMessage } = applyServerErrors(
            setError,
            error,
            knownFields,
            fallbackMessage,
          );
          setRootError(rootMessage);
        }
      }),
    // `knownFields` is derived per render from stable inputs; depending on the
    // array identity would rebuild `submit` every render for no behavioural gain.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [handleSubmit, setError],
  );

  return { ...form, rootError, setRootError, submit };
}
