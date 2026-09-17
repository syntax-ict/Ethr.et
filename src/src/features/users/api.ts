import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { PaginatedResponse } from "@/api/types";

export type UserStatus = "active" | "invited" | "inactive" | "suspended";

export interface TenantUser {
  public_id: string;
  email: string;
  /** Optional login handle; usable when the tenant enables the `username` identifier. */
  username: string | null;
  phone: string | null;
  role: string;
  status: UserStatus;
  locale: string;
  mfa_enabled: boolean;
  invited_at: string | null;
  activated_at: string | null;
  last_login_at: string | null;
  custom_role?: { public_id: string; name: string } | null;
  employee?: { public_id: string; name: string } | null;
}

export interface InviteUserPayload {
  email: string;
  username?: string;
  role: string;
  employee_id?: string;
  custom_role_id?: string;
  send_activation?: boolean;
}

export interface UpdateUserPayload {
  username?: string | null;
  role?: string;
  status?: UserStatus;
  custom_role_id?: string | null;
  locale?: string;
}

export function useUsers(params?: {
  page?: number;
  per_page?: number;
  search?: string;
}) {
  return useQuery<PaginatedResponse<TenantUser>>({
    queryKey: ["users", params],
    queryFn: async () => (await apiClient.get("/users", { params })).data,
  });
}

export function useInviteUser() {
  const qc = useQueryClient();
  return useMutation<TenantUser, unknown, InviteUserPayload>({
    mutationFn: async (payload) =>
      (await apiClient.post("/users", payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ["users"] }),
  });
}

export function useUpdateUser() {
  const qc = useQueryClient();
  return useMutation<
    TenantUser,
    unknown,
    { publicId: string; payload: UpdateUserPayload }
  >({
    mutationFn: async ({ publicId, payload }) =>
      (await apiClient.patch(`/users/${publicId}`, payload)).data,
    onSuccess: (_data, { publicId }) => {
      qc.invalidateQueries({ queryKey: ["users"] });

      // This payload can carry `role` and `custom_role_id`, and every `can.*`
      // flag and `<RoleGate>` in the interface is derived from the `permissions`
      // array on `/auth/me` — which has a five-minute staleTime. Editing your
      // own account therefore left the whole UI authorizing against the
      // permissions of the role you just left.
      //
      // Only when it *is* your own account. An admin working down a list of
      // staff should not refetch their own identity on every row, and an
      // invalidation broader than the change it follows is its own defect.
      const me = qc.getQueryData<{ user?: { public_id?: string } }>([
        "auth",
        "me",
      ]);

      if (me?.user?.public_id === publicId) {
        qc.invalidateQueries({ queryKey: ["auth", "me"] });
      }
    },
  });
}

export function useDeleteUser() {
  const qc = useQueryClient();
  return useMutation<void, unknown, string>({
    mutationFn: async (publicId) => {
      await apiClient.delete(`/users/${publicId}`);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["users"] }),
  });
}

export function useResendInvite() {
  return useMutation<{ message?: string }, unknown, string>({
    mutationFn: async (publicId) =>
      (await apiClient.post(`/users/${publicId}/resend-invite`)).data,
  });
}
