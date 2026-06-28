import { apiClient } from '@/api/client';
import type { OnboardingProgress, OrganizationTemplate } from './types';

export async function fetchTemplates(): Promise<OrganizationTemplate[]> {
  const response = await apiClient.get('/templates');
  return response.data.data;
}

export async function fetchProgress(): Promise<OnboardingProgress> {
  const response = await apiClient.get('/onboarding/progress');
  return response.data;
}

export async function updateStep(step: number, data: Record<string, unknown>): Promise<OnboardingProgress> {
  const response = await apiClient.put(`/onboarding/progress/${step}`, data);
  return response.data;
}

export async function applyTemplate(templateSlug: string): Promise<{ template: { slug: string }; data: Record<string, unknown> }> {
  const response = await apiClient.post('/onboarding/apply-template', { template_slug: templateSlug });
  return response.data;
}

export async function completeOnboarding(): Promise<{ redirect: string }> {
  const response = await apiClient.post('/onboarding/complete');
  return response.data;
}
