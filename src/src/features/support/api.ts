import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { PaginationMeta } from "@/components/shared/pagination-controls";

/**
 * Get help. Shapes mirror SupportDesk::present(); the organisation's side is
 * SupportRequestController, ETHR's is AdminSupportController.
 */

export const SUPPORT_CATEGORIES = [
  "sign_in",
  "attendance",
  "leave",
  "payroll",
  "employees",
  "devices",
  "billing",
  "other",
] as const;
export type SupportCategory = (typeof SUPPORT_CATEGORIES)[number];

export const SUPPORT_STATUSES = ["open", "answered", "resolved"] as const;
export type SupportStatus = (typeof SUPPORT_STATUSES)[number];

export type SupportHandler = "organisation" | "ethr";
export type SupportAuthor = "requester" | "organisation" | "ethr";

export interface SupportFailedRequest {
  method: string;
  path: string | null;
  status: number;
  type: string | null;
  at: string | null;
}

export interface SupportContext {
  role?: string;
  release?: string | null;
  included?: boolean;
  page?: string | null;
  locale?: string | null;
  viewport?: string | null;
  online?: boolean | null;
  pending_offline?: number | null;
  recent_errors?: SupportFailedRequest[];
  user_agent?: string | null;
}

export interface SupportMessage {
  public_id: string;
  author: SupportAuthor;
  author_name: string | null;
  body: string;
  created_at: string | null;
}

export interface SupportRequest {
  public_id: string;
  /** Who is reading: the person who asked, the organisation or ETHR. */
  viewer: SupportAuthor;
  category: SupportCategory;
  body: string;
  status: SupportStatus;
  handled_by: SupportHandler;
  requester: { name: string; email: string | null; role: string | null } | null;
  escalated_at: string | null;
  escalated_by_name: string | null;
  escalation_note: string | null;
  context: SupportContext | null;
  organisation: { public_id: string; name: string; subdomain: string } | null;
  created_at: string | null;
  updated_at: string | null;
  resolved_at: string | null;
  messages: SupportMessage[] | null;
}

export interface SupportPage {
  data: SupportRequest[];
  meta: PaginationMeta;
  counts?: Partial<Record<SupportStatus, number>>;
}

/** What the person typed, and what their screen knew. */
export interface NewSupportRequest {
  category: SupportCategory;
  body: string;
  send_to?: SupportHandler;
  include_context: boolean;
  context?: {
    page: string;
    locale: string;
    viewport: string;
    online: boolean;
    pending_offline: number | null;
    recent_errors: SupportFailedRequest[];
  };
}

const keys = {
  all: ["support"] as const,
  mine: (page: number) => ["support", "mine", page] as const,
  organisation: (status: string, page: number) =>
    ["support", "organisation", status, page] as const,
  one: (id: string) => ["support", "one", id] as const,
  console: (status: string, page: number) =>
    ["support", "console", status, page] as const,
  consoleOne: (id: string) => ["support", "console-one", id] as const,
};

function statusParams(status: string, page: number) {
  return {
    page,
    ...(status === "all" ? {} : { "filter[status]": status }),
  };
}

function useSupportMutation<TVars>(
  mutationFn: (vars: TVars) => Promise<SupportRequest>,
) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.all }),
  });
}

// ── The organisation ──

export function useCreateSupportRequest() {
  return useSupportMutation(
    async (vars: NewSupportRequest): Promise<SupportRequest> =>
      (await apiClient.post("/support-requests", vars)).data,
  );
}

export function useMySupportRequests(page: number) {
  return useQuery<SupportPage>({
    queryKey: keys.mine(page),
    queryFn: async () =>
      (await apiClient.get("/support-requests/mine", { params: { page } }))
        .data,
  });
}

/** Every request in the organisation, for support.manage. */
export function useSupportRequests(
  status: string,
  page: number,
  enabled = true,
) {
  return useQuery<SupportPage>({
    queryKey: keys.organisation(status, page),
    queryFn: async () =>
      (
        await apiClient.get("/support-requests", {
          params: statusParams(status, page),
        })
      ).data,
    enabled,
  });
}

export function useSupportRequest(publicId: string | null) {
  return useQuery<SupportRequest>({
    queryKey: keys.one(publicId ?? ""),
    queryFn: async () =>
      (await apiClient.get(`/support-requests/${publicId}`)).data,
    enabled: publicId !== null,
  });
}

export function useSupportReply() {
  return useSupportMutation(
    async (vars: { publicId: string; body: string }) =>
      (
        await apiClient.post(`/support-requests/${vars.publicId}/messages`, {
          body: vars.body,
        })
      ).data,
  );
}

export function useEscalateSupportRequest() {
  return useSupportMutation(
    async (vars: { publicId: string; note: string | null }) =>
      (
        await apiClient.post(`/support-requests/${vars.publicId}/escalate`, {
          note: vars.note,
        })
      ).data,
  );
}

export function useResolveSupportRequest() {
  return useSupportMutation(
    async (publicId: string) =>
      (await apiClient.post(`/support-requests/${publicId}/resolve`)).data,
  );
}

// ── ETHR, in the platform console ──

export function useConsoleSupportRequests(status: string, page: number) {
  return useQuery<SupportPage>({
    queryKey: keys.console(status, page),
    queryFn: async () =>
      (
        await apiClient.get("/admin/support-requests", {
          params: statusParams(status, page),
        })
      ).data,
  });
}

export function useConsoleSupportRequest(publicId: string | null) {
  return useQuery<SupportRequest>({
    queryKey: keys.consoleOne(publicId ?? ""),
    queryFn: async () =>
      (await apiClient.get(`/admin/support-requests/${publicId}`)).data,
    enabled: publicId !== null,
  });
}

export function useConsoleSupportReply() {
  return useSupportMutation(
    async (vars: { publicId: string; body: string }) =>
      (
        await apiClient.post(
          `/admin/support-requests/${vars.publicId}/messages`,
          { body: vars.body },
        )
      ).data,
  );
}

export function useConsoleResolveSupportRequest() {
  return useSupportMutation(
    async (publicId: string) =>
      (await apiClient.post(`/admin/support-requests/${publicId}/resolve`))
        .data,
  );
}
