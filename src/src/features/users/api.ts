import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components, operations } from "@/api/generated";
import type { PaginatedResponse } from "@/api/types";

type Schemas = components["schemas"];

/**
 * `users.status` is an uncast string column, so the contract says `string`.
 * Invitation writes `invited`; UpdateUserRequest admits only the other three.
 */
export type UserStatus =
  "invited" | NonNullable<Schemas["UpdateUserRequest"]["status"]>;

export type UserRole = Schemas["UserRole"];

/**
 * Shapes come from the generated contract; `username` is the optional login
 * handle, usable when the tenant enables the `username` identifier.
 */
export type TenantUser = Omit<Schemas["UserResource"], "status"> & {
  status: UserStatus;
};

export type InviteUserPayload = Schemas["StoreUserRequest"];
export type UpdateUserPayload = Schemas["UpdateUserRequest"];

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

type ResendInviteResult =
  operations["user.resendInvite"]["responses"][200]["content"]["application/json"];

export function useResendInvite() {
  return useMutation<ResendInviteResult, unknown, string>({
    mutationFn: async (publicId) =>
      (await apiClient.post(`/users/${publicId}/resend-invite`)).data,
  });
}
