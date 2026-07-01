import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import type { PaginatedResponse } from '@/api/types';

export interface AttendanceRecord {
  public_id: string;
  employee_name?: string;
  date: string;
  check_in: string | null;
  check_out: string | null;
  status: string;
  source: string;
  confidence_score: number;
  worked_minutes: number | null;
}

export function useMyAttendance(params?: { page?: number }) {
  return useQuery<PaginatedResponse<AttendanceRecord>>({
    queryKey: ['attendance', 'my', params],
    queryFn: async () => {
      const { data } = await apiClient.get('/attendance/my', { params });
      return data;
    },
  });
}

export interface AttendanceFilters {
  page?: number;
  per_page?: number;
  'filter[date_from]'?: string;
  'filter[date_to]'?: string;
  'filter[status]'?: string;
  'filter[source]'?: string;
  'filter[employee_public_id]'?: string;
  'filter[department_public_id]'?: string;
  'filter[branch_public_id]'?: string;
}

export function useAttendanceList(params?: AttendanceFilters) {
  return useQuery<PaginatedResponse<AttendanceRecord>>({
    queryKey: ['attendance', params],
    queryFn: async () => {
      const { data } = await apiClient.get('/attendance', { params });
      return data;
    },
  });
}

export function useCheckIn() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: { idempotency_key: string; source?: string }) => {
      const { data } = await apiClient.post('/attendance/check-in', payload, {
        headers: { 'Idempotency-Key': payload.idempotency_key },
      });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['attendance'] });
      queryClient.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });
}

export function useCheckOut() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: { idempotency_key: string }) => {
      const { data } = await apiClient.post('/attendance/check-out', payload, {
        headers: { 'Idempotency-Key': payload.idempotency_key },
      });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['attendance'] });
      queryClient.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });
}
