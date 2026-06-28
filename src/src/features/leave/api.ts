import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import type { PaginatedResponse } from '@/api/types';

export interface LeaveType {
  public_id: string;
  name: string;
  code: string;
  default_days: number;
  is_active: boolean;
}

export interface LeaveBalance {
  leave_type: string;
  entitled_days: number;
  used_days: number;
  remaining_days: number;
}

export interface LeaveRequest {
  public_id: string;
  leave_type: string;
  start_date: string;
  end_date: string;
  days: number;
  reason: string | null;
  status: string;
  created_at: string;
}

export function useLeaveBalance() {
  return useQuery<LeaveBalance[]>({
    queryKey: ['leave', 'balance'],
    queryFn: async () => {
      const { data } = await apiClient.get('/leave/balance');
      return data;
    },
  });
}

export function useMyLeaveRequests(params?: { page?: number }) {
  return useQuery<PaginatedResponse<LeaveRequest>>({
    queryKey: ['leave', 'my', params],
    queryFn: async () => {
      const { data } = await apiClient.get('/leave/my', { params });
      return data;
    },
  });
}

export function useTeamLeaveRequests(params?: { page?: number }) {
  return useQuery<PaginatedResponse<LeaveRequest>>({
    queryKey: ['leave', 'team', params],
    queryFn: async () => {
      const { data } = await apiClient.get('/leave/team', { params });
      return data;
    },
  });
}

export function useLeaveTypes() {
  return useQuery<PaginatedResponse<LeaveType>>({
    queryKey: ['leave-types'],
    queryFn: async () => {
      const { data } = await apiClient.get('/leave-types');
      return data;
    },
  });
}

export function useSubmitLeave() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: {
      leave_type_public_id: string;
      start_date: string;
      end_date: string;
      reason?: string;
    }) => {
      const { data } = await apiClient.post('/leave/request', payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['leave'] });
    },
  });
}

export function useApproveLeave() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (publicId: string) => {
      const { data } = await apiClient.put(`/leave/${publicId}/approve`);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['leave'] });
    },
  });
}

export function useRejectLeave() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ publicId, reason }: { publicId: string; reason: string }) => {
      const { data } = await apiClient.put(`/leave/${publicId}/reject`, { reason });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['leave'] });
    },
  });
}
