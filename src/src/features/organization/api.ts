import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { fetchAllPages } from "@/api/fetch-all-pages";
import type { components } from "@/api/generated";

type Schemas = components["schemas"];

// Shapes come from the generated contract, so a renamed resource field fails
// tsc here instead of rendering blank. The hand-written types these replace
// had drifted: `Branch.latitude`/`longitude` were `number`, but the model casts
// both `decimal:7`, which serialises as a string.
export type Branch = Schemas["BranchResource"];
export type Department = Schemas["DepartmentResource"];
export type Team = Schemas["TeamResource"];
export type Position = Schemas["PositionResource"];
export type Grade = Schemas["GradeResource"];
export type CostCenter = Schemas["CostCenterResource"];

/**
 * A node in the department hierarchy returned by `GET /organization/tree`: a
 * bare array of root departments, each nesting its descendants under
 * `children_recursive`. `employees_count` is the department's own direct
 * headcount; the rolled-up subtree total is derived on the client.
 */
export type DepartmentTreeNode = Schemas["DepartmentResource"];

/**
 * A node in the reporting hierarchy from `GET /organization/reporting-tree`:
 * an employee with their chain of direct reports nested under `direct_reports`.
 */
export type ReportingNode = Schemas["EmployeeReportingNodeResource"];

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

/** `T` is the resource read back; the bodies are the FormRequests' own shapes. */
function makeHooks<T extends { public_id: string }, TCreate, TUpdate>(
  resource: string,
) {
  return {
    // Every row, not the first page: these feed the Organization page and the
    // branch/department/position/grade pickers, which have no pagination, so
    // a 26th record was invisible and unpickable.
    useList: () =>
      useQuery<{ data: T[] }>({
        queryKey: ["organization", resource],
        queryFn: async () => ({
          data: await fetchAllPages<T>(`/organization/${resource}`),
        }),
        staleTime: 30 * 60 * 1000,
      }),

    useCreate: () => {
      const qc = useQueryClient();
      return useMutation({
        mutationFn: async (payload: TCreate) => {
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
          payload: TUpdate;
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

export const branchesApi = makeHooks<
  Branch,
  Schemas["StoreBranchRequest"],
  Schemas["UpdateBranchRequest"]
>("branches");
export const departmentsApi = makeHooks<
  Department,
  Schemas["StoreDepartmentRequest"],
  Schemas["UpdateDepartmentRequest"]
>("departments");
export const teamsApi = makeHooks<
  Team,
  Schemas["StoreTeamRequest"],
  Schemas["UpdateTeamRequest"]
>("teams");
export const positionsApi = makeHooks<
  Position,
  Schemas["StorePositionRequest"],
  Schemas["UpdatePositionRequest"]
>("positions");
export const gradesApi = makeHooks<
  Grade,
  Schemas["StoreGradeRequest"],
  Schemas["UpdateGradeRequest"]
>("grades");
export const costCentersApi = makeHooks<
  CostCenter,
  Schemas["StoreCostCenterRequest"],
  Schemas["UpdateCostCenterRequest"]
>("cost-centers");

// ── Grade salary steps (the grade→step salary scale) ────────────────

export type GradeSalaryStep = Schemas["GradeSalaryStepResource"];
export type GradeSalaryStepInput = Schemas["StoreGradeSalaryStepRequest"];

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
