import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/api/client';

export interface TeamAttendanceEmployee {
  public_id: string;
  name: string;
  department: string | null;
  photo_path: string | null;
  status: 'present' | 'absent' | 'late' | 'on_leave' | 'checked_in';
  check_in: string | null;
  check_out: string | null;
}

export interface TeamAttendanceTodayResponse {
  employees: TeamAttendanceEmployee[];
  summary: { present: number; absent: number; late: number; on_leave: number };
  date: string;
}

export function useTeamAttendanceToday() {
  return useQuery<TeamAttendanceTodayResponse>({
    queryKey: ['team', 'attendance', 'today'],
    queryFn: async () => {
      const { data } = await apiClient.get('/team/attendance/today');
      return data;
    },
    refetchInterval: 60000,
  });
}

export interface TeamAttendanceSummaryResponse {
  period: string;
  from: string;
  to: string;
  team_size: number;
  data: Array<{
    date: string;
    present: number;
    absent: number;
    late: number;
    rate: number;
  }>;
}

export function useTeamAttendanceSummary(period: 'weekly' | 'monthly' = 'weekly') {
  return useQuery<TeamAttendanceSummaryResponse>({
    queryKey: ['team', 'attendance', 'summary', period],
    queryFn: async () => {
      const { data } = await apiClient.get('/team/attendance/summary', { params: { period } });
      return data;
    },
  });
}

export interface TeamOvertimeResponse {
  month: string;
  employees: Array<{
    public_id: string;
    name: string;
    days_worked: number;
    overtime_hours: number;
    overtime_minutes: number;
  }>;
  total_overtime_hours: number;
}

export function useTeamOvertime() {
  return useQuery<TeamOvertimeResponse>({
    queryKey: ['team', 'overtime'],
    queryFn: async () => {
      const { data } = await apiClient.get('/team/overtime');
      return data;
    },
  });
}

export interface TeamLeaveCalendarResponse {
  month: string;
  team_size: number;
  employees: Array<{
    public_id: string;
    name: string;
    photo_path: string | null;
    days: Record<string, { on_leave: boolean; leave_type: string; color: string }>;
  }>;
  daily_summary: Record<string, { on_leave_count: number }>;
}

export function useTeamLeaveCalendar(month?: string) {
  return useQuery<TeamLeaveCalendarResponse>({
    queryKey: ['team', 'leave', 'calendar', month],
    queryFn: async () => {
      const { data } = await apiClient.get('/team/leave/calendar', {
        params: month ? { month } : undefined,
      });
      return data;
    },
  });
}
