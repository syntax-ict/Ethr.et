"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { OnboardingProgress } from "./types";

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
  const completedSteps = data?.completed_steps ?? [];
  // `App\Enums\OnboardingStep` has seven cases; six put a finished tenant at
  // "7 of 6" and 117%.
  const totalSteps = 7;
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
