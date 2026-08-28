import { apiClient } from "@/api/client";
import type { ApplyTemplateResponse, OnboardingProgress } from "./types";

export async function fetchProgress(): Promise<OnboardingProgress> {
  const response = await apiClient.get("/onboarding/progress");
  return response.data;
}

export async function updateStep(
  step: number,
  data: Record<string, unknown>,
): Promise<OnboardingProgress> {
  const response = await apiClient.put(`/onboarding/progress/${step}`, data);
  return response.data;
}

export async function applyTemplate(
  templateSlug: string,
): Promise<ApplyTemplateResponse> {
  const response = await apiClient.post("/onboarding/apply-template", {
    template_slug: templateSlug,
  });
  return response.data;
}

export async function inviteTeam(
  emails: string[],
  role: string = "employee",
): Promise<{
  created: Array<{ email: string; public_id: string }>;
  skipped: string[];
  message: string;
}> {
  const response = await apiClient.post("/onboarding/invite", { emails, role });
  return response.data;
}
