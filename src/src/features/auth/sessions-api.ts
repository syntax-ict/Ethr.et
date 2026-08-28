import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";

/**
 * Active sessions and trusted devices (PHASE_00 S03).
 *
 * Both were dead backend tables until now — there was no way for a user to see
 * where their account was signed in, or to sign a lost device out.
 */
export interface Session {
  id: number;
  device: string;
  ip_address: string | null;
  last_used_at: string | null;
  created_at: string | null;
  expires_at: string | null;
  is_current: boolean;
}

export interface TrustedDevice {
  id: number;
  device_name: string | null;
  last_used_at: string | null;
  expires_at: string | null;
}

export function useSessions() {
  return useQuery<{ data: Session[] }>({
    queryKey: ["auth", "sessions"],
    queryFn: async () => (await apiClient.get("/auth/sessions")).data,
  });
}

export function useRevokeSession() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (id: number) =>
      (
        await apiClient.delete<{ message: string; was_current: boolean }>(
          `/auth/sessions/${id}`,
        )
      ).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["auth", "sessions"] });
    },
  });
}

export function useRevokeAllSessions() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () =>
      (
        await apiClient.post<{ message: string; revoked: number }>(
          "/auth/sessions/revoke-all",
        )
      ).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["auth", "sessions"] });
      queryClient.invalidateQueries({ queryKey: ["auth", "devices"] });
    },
  });
}

export function useTrustedDevices() {
  return useQuery<{ data: TrustedDevice[] }>({
    queryKey: ["auth", "devices"],
    queryFn: async () => (await apiClient.get("/auth/devices")).data,
  });
}

export function useRevokeTrustedDevice() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (id: number) =>
      (await apiClient.delete(`/auth/devices/${id}`)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["auth", "devices"] });
    },
  });
}
