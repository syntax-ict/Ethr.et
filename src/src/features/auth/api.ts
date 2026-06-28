import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import type { User } from '@/api/types';

interface MeResponse {
  user: User;
  tenant: {
    public_id: string;
    name: string;
    subdomain: string;
    status: string;
  } | null;
}

export function useCurrentUser() {
  return useQuery<User>({
    queryKey: ['auth', 'me'],
    queryFn: async () => {
      const { data } = await apiClient.get<MeResponse>('/auth/me');
      return data.user;
    },
    retry: false,
    staleTime: 5 * 60 * 1000,
  });
}

export function useCurrentTenant() {
  return useQuery<MeResponse['tenant']>({
    queryKey: ['auth', 'me'],
    queryFn: async () => {
      const { data } = await apiClient.get<MeResponse>('/auth/me');
      return data.tenant;
    },
    retry: false,
    staleTime: 5 * 60 * 1000,
    select: (data) => data,
  });
}

export function useLogout() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () => {
      await apiClient.post('/auth/logout');
    },
    onSuccess: () => {
      localStorage.removeItem('access_token');
      queryClient.clear();
      window.location.href = '/login';
    },
  });
}
