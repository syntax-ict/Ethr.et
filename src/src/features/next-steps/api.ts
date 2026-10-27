"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components, operations } from "@/api/generated";

// Shapes come from the generated contract, so `NextStepId` is the server's own
// list: a step added there without copy here fails the type check.

export type NextStepsResponse =
  operations["nextSteps.index"]["responses"][200]["content"]["application/json"];
export type NextStep = NextStepsResponse["steps"][number];
export type NextStepId = components["schemas"]["NextStepId"];

/**
 * Under `["dashboard"]` on purpose: every approval, leave, onboarding and
 * employee mutation already invalidates that prefix, so the list refreshes
 * when the thing a step counts has just changed.
 */
export const NEXT_STEPS_KEY = ["dashboard", "next-steps"] as const;

/**
 * The person's Setup & Next Steps list (`GET /next-steps`).
 *
 * @param enabled A platform super admin belongs to no organisation, and the
 * API answers them with an empty list; skipping the request says so without
 * asking.
 */
export function useNextSteps(enabled = true) {
  return useQuery<NextStepsResponse>({
    queryKey: NEXT_STEPS_KEY,
    queryFn: async () => {
      const { data } = await apiClient.get("/next-steps");
      return data;
    },
    staleTime: 15 * 1000,
    enabled,
  });
}

/**
 * Put a step away: until what is behind it changes, or for `snoozeDays`.
 * A blocker can only be snoozed, which the API enforces; the row leaves the
 * list at once and comes back if the request is refused.
 */
export function useDismissStep() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      step,
      snoozeDays,
    }: {
      step: NextStep;
      snoozeDays?: number;
    }) => {
      await apiClient.post(`/next-steps/${step.id}/dismiss`, {
        signature: step.signature,
        snooze_days: snoozeDays,
      });
    },
    onMutate: async ({ step }) => {
      await queryClient.cancelQueries({ queryKey: NEXT_STEPS_KEY });
      const previous =
        queryClient.getQueryData<NextStepsResponse>(NEXT_STEPS_KEY);
      if (previous) {
        queryClient.setQueryData<NextStepsResponse>(NEXT_STEPS_KEY, {
          ...previous,
          steps: previous.steps.filter((s) => s.id !== step.id),
        });
      }
      return { previous };
    },
    onError: (_error, _variables, context) => {
      if (context?.previous) {
        queryClient.setQueryData(NEXT_STEPS_KEY, context.previous);
      }
    },
    onSettled: () =>
      queryClient.invalidateQueries({ queryKey: NEXT_STEPS_KEY }),
  });
}

/** Bring back everything this person put away. */
export function useRestoreSteps() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () => {
      await apiClient.delete("/next-steps/dismissals");
    },
    onSettled: () =>
      queryClient.invalidateQueries({ queryKey: NEXT_STEPS_KEY }),
  });
}
