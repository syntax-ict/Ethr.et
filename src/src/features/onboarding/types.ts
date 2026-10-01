export interface OnboardingProgress {
  current_step: number;
  completed_steps: number[];
  step_data: Record<string, unknown>;
  completed_at: string | null;
}
