import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import type { PaginatedResponse } from '@/api/types';

export interface PayrollRun {
  public_id: string;
  period_label: string;
  period_start: string;
  period_end: string;
  status: string;
  employee_count: number;
  gross_total_cents: number;
  net_total_cents: number;
  tax_total_cents: number;
  processed_at: string | null;
  approved_at: string | null;
  entries?: PayrollEntry[];
}

export interface PayrollEntry {
  public_id: string;
  employee_public_id: string;
  basic_salary_cents: number;
  gross_cents: number;
  income_tax_cents: number;
  employee_pension_cents: number;
  employer_pension_cents: number;
  other_deductions_cents: number;
  net_cents: number;
}

export function usePayrollRuns(params?: { page?: number }) {
  return useQuery<PaginatedResponse<PayrollRun>>({
    queryKey: ['payroll', 'runs', params],
    queryFn: async () => {
      const { data } = await apiClient.get('/payroll/runs', { params });
      return data;
    },
  });
}

export function usePayrollRun(publicId: string) {
  return useQuery<PayrollRun>({
    queryKey: ['payroll', 'runs', publicId],
    queryFn: async () => {
      const { data } = await apiClient.get(`/payroll/runs/${publicId}`);
      return data;
    },
    enabled: !!publicId,
  });
}

export function useMyPayslips(params?: { page?: number }) {
  return useQuery<PaginatedResponse<PayrollEntry>>({
    queryKey: ['payroll', 'payslips', 'my', params],
    queryFn: async () => {
      const { data } = await apiClient.get('/payroll/payslips/my', { params });
      return data;
    },
  });
}
