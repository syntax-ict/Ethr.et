import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/api/client';

export interface AdminTenant {
  public_id: string;
  name: string;
  subdomain: string;
  type: string | null;
  status: string;
  employee_count: number;
  trial_ends_at: string | null;
  created_at: string;
}

export interface AdminTenantDetail extends AdminTenant {
  updated_at: string;
}

export interface PaginatedTenants {
  data: AdminTenant[];
  current_page?: number;
  last_page?: number;
  total?: number;
  from?: number;
  to?: number;
}

export function useAdminTenants(params?: { search?: string; status?: string; page?: number; per_page?: number }) {
  return useQuery<PaginatedTenants>({
    queryKey: ['admin', 'tenants', params],
    queryFn: async () => {
      const queryParams: Record<string, unknown> = {};
      if (params?.search) queryParams.search = params.search;
      if (params?.status) queryParams['filter[status]'] = params.status;
      if (params?.page) queryParams.page = params.page;
      if (params?.per_page) queryParams.per_page = params.per_page;

      const { data } = await apiClient.get('/admin/tenants', { params: queryParams });
      return data;
    },
  });
}

export function useAdminTenant(publicId: string) {
  return useQuery<AdminTenantDetail>({
    queryKey: ['admin', 'tenants', publicId],
    queryFn: async () => {
      const { data } = await apiClient.get(`/admin/tenants/${publicId}`);
      return data;
    },
    enabled: !!publicId,
  });
}

export function useUpdateTenantStatus() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ publicId, status }: { publicId: string; status: 'active' | 'suspended' | 'cancelled' }) => {
      const { data } = await apiClient.put(`/admin/tenants/${publicId}/status`, { status });
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'tenants'] }),
  });
}

export function useExtendTrial() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ publicId, days }: { publicId: string; days: number }) => {
      const { data } = await apiClient.post(`/admin/tenants/${publicId}/extend-trial`, { days });
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'tenants'] }),
  });
}

export function useImpersonateTenant() {
  return useMutation<{ token: string; tenant: string; expires_at: string }, unknown, string>({
    mutationFn: async (publicId) => {
      const { data } = await apiClient.post(`/admin/tenants/${publicId}/impersonate`);
      return data;
    },
  });
}

export interface AdminAuditLog {
  id: number;
  action: string;
  auditable_type?: string;
  auditable_id?: number;
  user_id?: number;
  ip_address?: string;
  created_at: string;
}

export function useAdminAuditLog(params?: { action?: string; from?: string; to?: string; page?: number }) {
  return useQuery<{ data: AdminAuditLog[]; current_page?: number; last_page?: number; total?: number; from?: number; to?: number }>({
    queryKey: ['admin', 'audit', params],
    queryFn: async () => {
      const queryParams: Record<string, unknown> = { per_page: 50 };
      if (params?.action) queryParams['filter[action]'] = params.action;
      if (params?.from) queryParams['filter[from]'] = params.from;
      if (params?.to) queryParams['filter[to]'] = params.to;
      if (params?.page) queryParams.page = params.page;

      const { data } = await apiClient.get('/admin/audit', { params: queryParams });
      return data;
    },
  });
}
