import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { PaginatedResponse } from "@/api/types";

/**
 * Employee voice: the weekly pulse, speak-up concerns and recognition.
 * Shapes mirror PulseController, ConcernController and RecognitionController.
 */

export const PULSE_TAGS = [
  "workload",
  "manager",
  "team",
  "pay",
  "growth",
  "recognition",
  "safety",
  "facilities",
  "tools",
  "wellbeing",
] as const;
export type PulseTag = (typeof PULSE_TAGS)[number];

export const CONCERN_CATEGORIES = [
  "safety",
  "harassment",
  "discrimination",
  "manager",
  "workload",
  "pay",
  "facilities",
  "ethics",
  "wellbeing",
  "other",
] as const;
export type ConcernCategory = (typeof CONCERN_CATEGORIES)[number];

export const CONCERN_STATUSES = [
  "new",
  "in_review",
  "resolved",
  "closed",
] as const;
export type ConcernStatus = (typeof CONCERN_STATUSES)[number];

export const RECOGNITION_VALUES = [
  "teamwork",
  "customer_care",
  "integrity",
  "initiative",
  "excellence",
  "helping_hand",
] as const;
export type RecognitionValue = (typeof RECOGNITION_VALUES)[number];

export interface PulseState {
  week: string;
  answered: boolean;
}

export interface PulseInsights {
  min_group: number;
  trend: Array<{ week: string; responses: number; average: number | null }>;
  recent: {
    weeks: string[];
    responses: number;
    average: number | null;
    distribution: Record<string, number | null>;
    tags: Record<string, number>;
    departments: Array<{
      department: string | null;
      responses: number;
      average: number;
    }>;
    comments: Array<{
      week: string;
      score: number;
      tags: string[];
      comment: string;
    }>;
  };
}

export interface ConcernMessage {
  public_id: string;
  author: "reporter" | "hr";
  author_name: string | null;
  body: string;
  created_at: string | null;
}

export interface Concern {
  public_id: string;
  category: ConcernCategory;
  subject: string;
  description: string;
  status: ConcernStatus;
  resolution: string | null;
  anonymous: boolean;
  reporter_name: string | null;
  messages_count: number | null;
  created_at: string | null;
  updated_at: string | null;
  resolved_at: string | null;
  messages: ConcernMessage[] | null;
}

export interface Recognition {
  public_id: string;
  value: RecognitionValue;
  message: string;
  from: { public_id: string; name: string } | null;
  to: { public_id: string; name: string } | null;
  created_at: string | null;
}

const keys = {
  all: ["voice"] as const,
  pulse: ["voice", "pulse"] as const,
  insights: ["voice", "insights"] as const,
  mine: ["voice", "concerns", "mine"] as const,
  concerns: (status: string, page: number) =>
    ["voice", "concerns", "all", status, page] as const,
  concern: (id: string) => ["voice", "concerns", "one", id] as const,
  recognitions: (page: number) => ["voice", "recognitions", page] as const,
};

function useVoiceMutation<TVars, TData>(
  mutationFn: (vars: TVars) => Promise<TData>,
) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.all }),
  });
}

// ── Pulse ──

export function usePulse() {
  return useQuery<PulseState>({
    queryKey: keys.pulse,
    queryFn: async () => (await apiClient.get("/voice/pulse")).data,
  });
}

export function useAnswerPulse() {
  return useVoiceMutation(
    async (vars: {
      score: number;
      tags: PulseTag[];
      comment: string | null;
    }): Promise<PulseState> =>
      (await apiClient.post("/voice/pulse", vars)).data,
  );
}

export function usePulseInsights(enabled = true) {
  return useQuery<PulseInsights>({
    queryKey: keys.insights,
    queryFn: async () => (await apiClient.get("/voice/pulse/insights")).data,
    enabled,
  });
}

// ── Concerns ──

export function useRaiseConcern() {
  return useVoiceMutation(
    async (vars: {
      category: ConcernCategory;
      subject: string;
      description: string;
      anonymous: boolean;
    }): Promise<Concern & { tracking_code: string | null }> =>
      (await apiClient.post("/voice/concerns", vars)).data,
  );
}

export function useMyConcerns() {
  return useQuery<{ data: Concern[] }>({
    queryKey: keys.mine,
    queryFn: async () => (await apiClient.get("/voice/concerns/mine")).data,
  });
}

/** An anonymous concern, by its follow-up code (sent in the body, never the URL). */
export function useTrackConcern() {
  return useMutation({
    mutationFn: async (code: string): Promise<Concern> =>
      (await apiClient.post("/voice/concerns/track", { code })).data,
  });
}

export function useTrackReply() {
  return useMutation({
    mutationFn: async (vars: {
      code: string;
      body: string;
    }): Promise<Concern> =>
      (await apiClient.post("/voice/concerns/track/reply", vars)).data,
  });
}

export function useConcerns(status: string, page: number, enabled = true) {
  return useQuery<
    PaginatedResponse<Concern> & { counts: Record<string, number> }
  >({
    queryKey: keys.concerns(status, page),
    queryFn: async () =>
      (
        await apiClient.get("/voice/concerns", {
          params: {
            page,
            ...(status !== "all" ? { "filter[status]": status } : {}),
          },
        })
      ).data,
    enabled,
  });
}

export function useConcern(publicId: string | null) {
  return useQuery<Concern>({
    queryKey: keys.concern(publicId ?? ""),
    queryFn: async () =>
      (await apiClient.get(`/voice/concerns/${publicId}`)).data,
    enabled: publicId !== null,
  });
}

export function useConcernMessage() {
  return useVoiceMutation(
    async (vars: { publicId: string; body: string }): Promise<Concern> =>
      (
        await apiClient.post(`/voice/concerns/${vars.publicId}/messages`, {
          body: vars.body,
        })
      ).data,
  );
}

export function useUpdateConcern() {
  return useVoiceMutation(
    async (vars: {
      publicId: string;
      status: ConcernStatus;
      resolution: string | null;
    }): Promise<Concern> =>
      (
        await apiClient.put(`/voice/concerns/${vars.publicId}`, {
          status: vars.status,
          resolution: vars.resolution,
        })
      ).data,
  );
}

// ── Recognition ──

export function useRecognitions(page = 1) {
  return useQuery<PaginatedResponse<Recognition>>({
    queryKey: keys.recognitions(page),
    queryFn: async () =>
      (await apiClient.get("/voice/recognitions", { params: { page } })).data,
  });
}

export function useRecognise() {
  return useVoiceMutation(
    async (vars: {
      to_employee_public_id: string;
      value: RecognitionValue;
      message: string;
    }): Promise<Recognition> =>
      (await apiClient.post("/voice/recognitions", vars)).data,
  );
}
