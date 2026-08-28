import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { PaginatedResponse } from "@/api/types";

export interface Branch {
  public_id: string;
  name: string;
  name_am?: string | null;
  code?: string | null;
  address?: string | null;
  city?: string | null;
  phone?: string | null;
  latitude?: number | null;
  longitude?: number | null;
  geofence_radius_meters?: number | null;
  is_active: boolean;
  departments_count?: number;
  employees_count?: number;
}

export interface Department {
  public_id: string;
  name: string;
  name_am?: string | null;
  code?: string | null;
  is_active: boolean;
  parent?: { public_id: string; name: string } | null;
  branch?: { public_id: string; name: string } | null;
}

export interface Team {
  public_id: string;
  name: string;
  name_am?: string | null;
  is_active: boolean;
  department?: { public_id: string; name: string } | null;
}

export interface Position {
  public_id: string;
  title: string;
  title_am?: string | null;
  code?: string | null;
  description?: string | null;
  is_active: boolean;
  employees_count?: number;
}

export interface Grade {
  public_id: string;
  name: string;
  min_salary_cents: number;
  max_salary_cents: number;
  sort_order?: number;
}

export interface CostCenter {
  public_id: string;
  name: string;
  code?: string | null;
  is_active: boolean;
}

/**
 * A node in the department hierarchy returned by `GET /organization/tree`.
 * `employees_count` is the department's own direct headcount; the rolled-up
 * subtree total is derived on the client. The endpoint returns a bare array of
 * root nodes (no pagination wrapper), each nesting its descendants under
 * `children_recursive`.
 */
export interface DepartmentTreeNode {
  public_id: string;
  name: string;
  name_am?: string | null;
  code?: string | null;
  is_active: boolean;
  branch?: { public_id: string; name: string } | null;
  employees_count?: number;
  children_recursive?: DepartmentTreeNode[];
}

/**
 * A node in the reporting hierarchy from `GET /organization/reporting-tree`:
 * an employee with their chain of direct reports nested under `direct_reports`.
 */
export interface ReportingNode {
  public_id: string;
  name: string;
  employee_code: string;
  position?: string | null;
  photo_url?: string | null;
  photo_thumb_url?: string | null;
  direct_reports?: ReportingNode[];
}

export function useReportingTree() {
  return useQuery<ReportingNode[]>({
    queryKey: ["organization", "reporting-tree"],
    queryFn: async () => {
      const { data } = await apiClient.get<ReportingNode[]>(
        "/organization/reporting-tree",
      );
      return data;
    },
    staleTime: 30 * 60 * 1000,
  });
}

function makeHooks<T extends { public_id: string }>(resource: string) {
  return {
    useList: () =>
      useQuery<PaginatedResponse<T>>({
        queryKey: ["organization", resource],
        queryFn: async () => {
          const { data } = await apiClient.get(`/organization/${resource}`);
          return data;
        },
        staleTime: 30 * 60 * 1000,
      }),

    useCreate: () => {
      const qc = useQueryClient();
      return useMutation({
        mutationFn: async (payload: Partial<T>) => {
          const { data } = await apiClient.post(
            `/organization/${resource}`,
            payload,
          );
          return data;
        },
        onSuccess: () => {
          qc.invalidateQueries({ queryKey: ["organization", resource] });
          // Department/branch edits reshape the tree, and any headcount can
          // shift the chart's rolled-up totals, so keep the chart fresh too.
          qc.invalidateQueries({ queryKey: ["organization", "tree"] });
        },
      });
    },

    useUpdate: () => {
      const qc = useQueryClient();
      return useMutation({
        mutationFn: async ({
          publicId,
          payload,
        }: {
          publicId: string;
          payload: Partial<T>;
        }) => {
          const { data } = await apiClient.put(
            `/organization/${resource}/${publicId}`,
            payload,
          );
          return data;
        },
        onSuccess: () => {
          qc.invalidateQueries({ queryKey: ["organization", resource] });
          // Department/branch edits reshape the tree, and any headcount can
          // shift the chart's rolled-up totals, so keep the chart fresh too.
          qc.invalidateQueries({ queryKey: ["organization", "tree"] });
        },
      });
    },

    useDelete: () => {
      const qc = useQueryClient();
      return useMutation({
        mutationFn: async (publicId: string) => {
          await apiClient.delete(`/organization/${resource}/${publicId}`);
        },
        onSuccess: () => {
          qc.invalidateQueries({ queryKey: ["organization", resource] });
          // Department/branch edits reshape the tree, and any headcount can
          // shift the chart's rolled-up totals, so keep the chart fresh too.
          qc.invalidateQueries({ queryKey: ["organization", "tree"] });
        },
      });
    },
  };
}

export function useOrganizationTree() {
  return useQuery<DepartmentTreeNode[]>({
    queryKey: ["organization", "tree"],
    queryFn: async () => {
      const { data } =
        await apiClient.get<DepartmentTreeNode[]>("/organization/tree");
      return data;
    },
    staleTime: 30 * 60 * 1000,
  });
}

export const branchesApi = makeHooks<Branch>("branches");
export const departmentsApi = makeHooks<Department>("departments");
export const teamsApi = makeHooks<Team>("teams");
export const positionsApi = makeHooks<Position>("positions");
export const gradesApi = makeHooks<Grade>("grades");
export const costCentersApi = makeHooks<CostCenter>("cost-centers");

// ── Grade salary steps (the grade→step salary scale) ────────────────

export interface GradeSalaryStep {
  public_id: string;
  step: number;
  salary_cents: number;
  created_at: string;
}

export interface GradeSalaryStepInput {
  step: number;
  salary_cents: number;
}

function gradeStepsKey(gradePublicId: string) {
  return ["organization", "grades", gradePublicId, "salary-steps"];
}

export function useGradeSalarySteps(gradePublicId: string, enabled = true) {
  return useQuery<GradeSalaryStep[]>({
    queryKey: gradeStepsKey(gradePublicId),
    queryFn: async () => {
      const { data } = await apiClient.get<GradeSalaryStep[]>(
        `/organization/grades/${gradePublicId}/salary-steps`,
      );
      return data;
    },
    enabled: enabled && !!gradePublicId,
  });
}

export function useCreateGradeSalaryStep(gradePublicId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: GradeSalaryStepInput) => {
      const { data } = await apiClient.post<GradeSalaryStep>(
        `/organization/grades/${gradePublicId}/salary-steps`,
        input,
      );
      return data;
    },
    onSuccess: () =>
      qc.invalidateQueries({ queryKey: gradeStepsKey(gradePublicId) }),
  });
}

export function useUpdateGradeSalaryStep(gradePublicId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({
      stepPublicId,
      input,
    }: {
      stepPublicId: string;
      input: GradeSalaryStepInput;
    }) => {
      const { data } = await apiClient.put<GradeSalaryStep>(
        `/organization/grades/${gradePublicId}/salary-steps/${stepPublicId}`,
        input,
      );
      return data;
    },
    onSuccess: () =>
      qc.invalidateQueries({ queryKey: gradeStepsKey(gradePublicId) }),
  });
}

export function useDeleteGradeSalaryStep(gradePublicId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (stepPublicId: string) => {
      await apiClient.delete(
        `/organization/grades/${gradePublicId}/salary-steps/${stepPublicId}`,
      );
    },
    onSuccess: () =>
      qc.invalidateQueries({ queryKey: gradeStepsKey(gradePublicId) }),
  });
}
