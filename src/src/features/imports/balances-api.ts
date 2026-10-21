import { useMutation } from "@tanstack/react-query";
import { apiClient } from "@/api/client";

/** `leave` needs leave.adjustBalance; `loans` needs payroll.manageLoan. */
export type BalanceKind = "leave" | "loans";

export interface BalanceRow {
  line: number;
  employee_code?: string;
  employee_name?: string | null;
  errors: string[];
  valid: boolean;
  [column: string]: unknown;
}

export interface BalancePreview {
  rows: BalanceRow[];
  valid: number;
  invalid: number;
  errors: string[];
}

export interface BalanceCommit {
  committed: boolean;
  created: number;
  updated: number;
  skipped: number;
  rows: BalanceRow[];
}

export async function fetchBalanceTemplate(kind: BalanceKind): Promise<string> {
  const { data } = await apiClient.get<{ template: string }>(
    `/imports/balances/${kind}/template`,
  );
  return data.template;
}

export function usePreviewBalances(kind: BalanceKind) {
  return useMutation({
    mutationFn: async (file: File) => {
      const form = new FormData();
      form.append("file", file);
      const { data } = await apiClient.post<BalancePreview>(
        `/imports/balances/${kind}/preview`,
        form,
      );
      return data;
    },
  });
}

export function useCommitBalances(kind: BalanceKind) {
  return useMutation({
    mutationFn: async (rows: BalanceRow[]) => {
      const { data } = await apiClient.post<BalanceCommit>(
        `/imports/balances/${kind}/commit`,
        { rows },
      );
      return data;
    },
  });
}
