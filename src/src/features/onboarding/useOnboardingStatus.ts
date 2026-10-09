"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { OnboardingProgress } from "./types";

/**
 * The guided setup's steps, in order, as `OnboardingStep` numbers on the
 * server: configuration (3), workforce migration (4), access (5), go live (7).
 */
export const GUIDED_STEP_IDS: readonly number[] = [3, 4, 5, 7];

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
