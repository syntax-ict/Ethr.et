"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { OnboardingProgress } from "./types";

/**
 * The guided setup's steps, in the order they are shown, as `OnboardingStep`
 * numbers on the server: configuration (3), access (5), workforce migration
 * (4), go live (7). The numbers are the server's and do not change; the order
 * here is the screen's. Sign-in comes before People since 2026-11-16, so that
 * the way people sign in is chosen before anyone is invited.
 */
export const GUIDED_STEP_IDS: readonly number[] = [3, 5, 4, 7];

export function useOnboardingStatus(enabled = true) {
  const { data, isLoading, isError } = useQuery<OnboardingProgress>({
    queryKey: ["onboarding", "progress"],
    queryFn: async () => {
      const { data } = await apiClient.get("/onboarding/progress");
      return data;
    },
    staleTime: 5 * 60 * 1000,
    retry: false,
    enabled,
  });

  const isComplete = !enabled || isError || !!data?.completed_at;
  const currentStep = data?.current_step ?? 1;
  // Only the guided setup's steps. The server's enum keeps seven numbers from
  // the old wizard, and nothing marks 1, 2 or 6, so counting against seven
  // stopped the bar at 3 of 7 however far setup got (2026-10-09). The total
  // was six once, which put a finished tenant at "7 of 6" and 117%.
  const completedSteps = (data?.completed_steps ?? []).filter((s) =>
    GUIDED_STEP_IDS.includes(s),
  );
  const totalSteps = GUIDED_STEP_IDS.length;
  const progress = Math.round((completedSteps.length / totalSteps) * 100);

  return {
    isLoading: enabled && isLoading,
    isComplete,
    currentStep,
    completedSteps,
    totalSteps,
    progress,
  };
}
