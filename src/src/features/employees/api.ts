import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { PaginatedResponse } from "@/api/types";
import type { Employee, EmployeeFormData } from "./types";

export function useEmployees(params?: {
  page?: number;
  search?: string;
  per_page?: number;
  sort?: string;
}) {
  return useQuery<PaginatedResponse<Employee>>({
    queryKey: ["employees", params],
    queryFn: async () => {
      const { data } = await apiClient.get("/employees", { params });
      return data;
    },
    staleTime: 2 * 60 * 1000,
  });
}

export function useEmployee(publicId: string) {
  return useQuery<Employee>({
    queryKey: ["employees", publicId],
    queryFn: async () => {
      const { data } = await apiClient.get(`/employees/${publicId}`);
      return data;
    },
    enabled: !!publicId,
    staleTime: 5 * 60 * 1000,
  });
}

export function useCreateEmployee() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (formData: EmployeeFormData) => {
      const { data } = await apiClient.post("/employees", formData);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["employees"] });
    },
  });
}

export function useUpdateEmployee(publicId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (formData: Partial<EmployeeFormData>) => {
      const { data } = await apiClient.put(`/employees/${publicId}`, formData);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["employees"] });
    },
  });
}

export function useDeleteEmployee() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (publicId: string) => {
      await apiClient.delete(`/employees/${publicId}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["employees"] });
    },
  });
}

export interface BulkUpdateEmployeesInput {
  employee_ids: string[];
  department_id?: string;
  branch_id?: string;
  status?: string;
}

export function useBulkUpdateEmployees() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: BulkUpdateEmployeesInput) => {
      const { data } = await apiClient.post<{ updated: number }>(
        "/employees/bulk-update",
        input,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["employees"] });
    },
  });
}

export function useEmployeeStats() {
  return useQuery({
    queryKey: ["employees", "stats"],
    queryFn: async () => {
      const { data } = await apiClient.get("/employees/stats");
      return data;
    },
  });
}

// ── Personnel actions (employment history) ─────────────────────────

export type PersonnelActionType =
  | "appointment"
  | "promotion"
  | "demotion"
  | "transfer"
  | "re_designation"
  | "salary_step_increment"
  | "acting_assignment"
  | "delegation"
  | "secondment"
  | "reassignment";

/** A `{from, to}` pair as stored in the immutable action snapshot. */
export interface PersonnelActionChange {
  from: string | number | null;
  to: string | number | null;
}

export interface PersonnelAction {
  public_id: string;
  type: PersonnelActionType;
  is_temporary: boolean;
  effective_date: string;
  end_date: string | null;
  reference_number: string | null;
  reason: string | null;
  remarks: string | null;
  changes: Partial<Record<string, PersonnelActionChange>>;
  recorded_by?: string | null;
  created_at: string;
}

export interface RecordPersonnelActionInput {
  type: PersonnelActionType;
  effective_date: string;
  end_date?: string | null;
  reference_number?: string | null;
  reason?: string | null;
  remarks?: string | null;
  position_public_id?: string | null;
  grade_public_id?: string | null;
  department_public_id?: string | null;
  branch_public_id?: string | null;
  new_salary_cents?: number | null;
  salary_step?: number | null;
}

export function useEmployeePersonnelActions(publicId: string) {
  return useQuery<PersonnelAction[]>({
    queryKey: ["employees", publicId, "personnel-actions"],
    queryFn: async () => {
      const { data } = await apiClient.get<PersonnelAction[]>(
        `/employees/${publicId}/personnel-actions`,
      );
      return data;
    },
  });
}

export function useRecordPersonnelAction(publicId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (input: RecordPersonnelActionInput) => {
      const { data } = await apiClient.post<PersonnelAction>(
        `/employees/${publicId}/personnel-actions`,
        input,
      );
      return data;
    },
    onSuccess: () => {
      // A permanent action changes the employee record, so refresh both.
      queryClient.invalidateQueries({
        queryKey: ["employees", publicId, "personnel-actions"],
      });
      queryClient.invalidateQueries({ queryKey: ["employees", publicId] });
    },
  });
}

// ── Disciplinary cases (offence → investigation → decision → sanction → appeal) ──

export type DisciplinaryCategory =
  | "misconduct"
  | "absenteeism"
  | "insubordination"
  | "negligence"
  | "policy_violation"
  | "financial_irregularity"
  | "harassment"
  | "other";

export type DisciplinaryCaseStatus =
  "reported" | "investigating" | "decided" | "appealed" | "closed";

export type DisciplinaryDecision = "guilty" | "not_guilty" | "inconclusive";

export type DisciplinarySanctionType =
  | "verbal_warning"
  | "written_warning"
  | "suspension"
  | "demotion"
  | "salary_deduction"
  | "termination";

export type DisciplinaryAppealStatus = "pending" | "upheld" | "denied";

export interface DisciplinaryInvestigationNote {
  note: string;
  by: number | null;
  by_name: string | null;
  at: string;
}

export interface DisciplinaryCase {
  public_id: string;
  reference_number: string | null;
  category: DisciplinaryCategory;
  description: string;
  incident_date: string;
  status: DisciplinaryCaseStatus;
  reported_by?: string | null;
  investigation_notes: DisciplinaryInvestigationNote[];

  decision: DisciplinaryDecision | null;
  decision_notes: string | null;
  decided_at: string | null;
  decided_by?: string | null;

  sanction_type: DisciplinarySanctionType | null;
  sanction_details: string | null;
  sanction_effective_date: string | null;

  appeal_status: DisciplinaryAppealStatus | null;
  appeal_grounds: string | null;
  appeal_filed_at: string | null;
  appeal_decision_notes: string | null;
  appeal_decided_at: string | null;
  appeal_decided_by?: string | null;

  closed_at: string | null;
  created_at: string;
}

export interface OpenDisciplinaryCaseInput {
  category: DisciplinaryCategory;
  description: string;
  incident_date: string;
  reference_number?: string | null;
}

export interface RecordDisciplinaryDecisionInput {
  decision: DisciplinaryDecision;
  decision_notes?: string | null;
  sanction_type?: DisciplinarySanctionType | null;
  sanction_details?: string | null;
  sanction_effective_date?: string | null;
}

function disciplinaryCasesKey(publicId: string) {
  return ["employees", publicId, "disciplinary-cases"];
}

export function useEmployeeDisciplinaryCases(publicId: string) {
  return useQuery<DisciplinaryCase[]>({
    queryKey: disciplinaryCasesKey(publicId),
    queryFn: async () => {
      const { data } = await apiClient.get<DisciplinaryCase[]>(
        `/employees/${publicId}/disciplinary-cases`,
      );
      return data;
    },
  });
}

function useDisciplinaryCaseMutation<TInput>(
  publicId: string,
  path: (caseId: string) => string,
) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({
      caseId,
      input,
    }: {
      caseId: string;
      input: TInput;
    }) => {
      const { data } = await apiClient.post<DisciplinaryCase>(
        `/employees/${publicId}/disciplinary-cases/${path(caseId)}`,
        input,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: disciplinaryCasesKey(publicId),
      });
    },
  });
}

export function useOpenDisciplinaryCase(publicId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (input: OpenDisciplinaryCaseInput) => {
      const { data } = await apiClient.post<DisciplinaryCase>(
        `/employees/${publicId}/disciplinary-cases`,
        input,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: disciplinaryCasesKey(publicId),
      });
    },
  });
}

export function useAddDisciplinaryNote(publicId: string) {
  return useDisciplinaryCaseMutation<{ note: string }>(
    publicId,
    (caseId) => `${caseId}/notes`,
  );
}

export function useRecordDisciplinaryDecision(publicId: string) {
  return useDisciplinaryCaseMutation<RecordDisciplinaryDecisionInput>(
    publicId,
    (caseId) => `${caseId}/decision`,
  );
}

export function useFileDisciplinaryAppeal(publicId: string) {
  return useDisciplinaryCaseMutation<{ grounds: string }>(
    publicId,
    (caseId) => `${caseId}/appeal`,
  );
}

export function useResolveDisciplinaryAppeal(publicId: string) {
  return useDisciplinaryCaseMutation<{
    outcome: "upheld" | "denied";
    decision_notes?: string | null;
  }>(publicId, (caseId) => `${caseId}/appeal-decision`);
}

export function useCloseDisciplinaryCase(publicId: string) {
  return useDisciplinaryCaseMutation<{ notes?: string | null }>(
    publicId,
    (caseId) => `${caseId}/close`,
  );
}

// ── Employee contracts ──────────────────────────────────────────────

export type ContractType =
  "probation" | "fixed_term" | "permanent" | "casual" | "consultancy";

export type ContractStatus =
  "active" | "renewed" | "expired" | "terminated_early";

export interface EmployeeContract {
  public_id: string;
  reference_number: string | null;
  contract_type: ContractType;
  start_date: string;
  end_date: string | null;
  salary_cents: number | null;
  terms: string | null;
  status: ContractStatus;
  renewed_from_id?: string | null;
  ended_at: string | null;
  end_notes: string | null;
  is_expired: boolean;
  expires_soon: boolean;
  days_until_expiry: number | null;
  employee_name?: string;
  employee_public_id?: string;
  created_at: string;
}

export interface ContractInput {
  contract_type: ContractType;
  reference_number?: string | null;
  start_date: string;
  end_date?: string | null;
  salary_cents?: number | null;
  terms?: string | null;
}

function contractsKey(publicId: string) {
  return ["employees", publicId, "contracts"];
}

export function useEmployeeContracts(publicId: string) {
  return useQuery<EmployeeContract[]>({
    queryKey: contractsKey(publicId),
    queryFn: async () => {
      const { data } = await apiClient.get<EmployeeContract[]>(
        `/employees/${publicId}/contracts`,
      );
      return data;
    },
  });
}

export function useCreateContract(publicId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (input: ContractInput) => {
      const { data } = await apiClient.post<EmployeeContract>(
        `/employees/${publicId}/contracts`,
        input,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: contractsKey(publicId) });
    },
  });
}

export function useRenewContract(publicId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({
      contractId,
      input,
    }: {
      contractId: string;
      input: ContractInput;
    }) => {
      const { data } = await apiClient.post<EmployeeContract>(
        `/employees/${publicId}/contracts/${contractId}/renew`,
        input,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: contractsKey(publicId) });
    },
  });
}

export function useEndContract(publicId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({
      contractId,
      input,
    }: {
      contractId: string;
      input: {
        status: "expired" | "terminated_early";
        end_notes?: string | null;
      };
    }) => {
      const { data } = await apiClient.post<EmployeeContract>(
        `/employees/${publicId}/contracts/${contractId}/end`,
        input,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: contractsKey(publicId) });
    },
  });
}

// ── Retirement cases (initiated → reviewed → approved/rejected → finalized) ──

export type RetirementType = "mandatory" | "voluntary" | "early";

export type RetirementCaseStatus =
  | "initiated"
  | "under_review"
  | "approved"
  | "rejected"
  | "finalized"
  | "cancelled";

export type RetirementDecision = "approved" | "rejected";

export interface RetirementCase {
  public_id: string;
  retirement_type: RetirementType;
  status: RetirementCaseStatus;
  service_years: number;
  eligible_retirement_date: string | null;
  reason: string | null;
  notes: string | null;
  initiated_by?: string | null;

  decision: RetirementDecision | null;
  decision_notes: string | null;
  decided_at: string | null;
  decided_by?: string | null;

  finalized_at: string | null;
  created_at: string;
}

export interface InitiateRetirementCaseInput {
  retirement_type: RetirementType;
  reason?: string | null;
}

export interface DecideRetirementCaseInput {
  decision: RetirementDecision;
  decision_notes?: string | null;
}

function retirementCasesKey(publicId: string) {
  return ["employees", publicId, "retirement-cases"];
}

export function useEmployeeRetirementCases(publicId: string) {
  return useQuery<RetirementCase[]>({
    queryKey: retirementCasesKey(publicId),
    queryFn: async () => {
      const { data } = await apiClient.get<RetirementCase[]>(
        `/employees/${publicId}/retirement-cases`,
      );
      return data;
    },
  });
}

function useRetirementCaseMutation<TInput>(
  publicId: string,
  path: (caseId: string) => string,
) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({
      caseId,
      input,
    }: {
      caseId: string;
      input: TInput;
    }) => {
      const { data } = await apiClient.post<RetirementCase>(
        `/employees/${publicId}/retirement-cases/${path(caseId)}`,
        input,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: retirementCasesKey(publicId),
      });
    },
  });
}

export function useInitiateRetirementCase(publicId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (input: InitiateRetirementCaseInput) => {
      const { data } = await apiClient.post<RetirementCase>(
        `/employees/${publicId}/retirement-cases`,
        input,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: retirementCasesKey(publicId),
      });
    },
  });
}

export function useAddRetirementNote(publicId: string) {
  return useRetirementCaseMutation<{ note: string }>(
    publicId,
    (caseId) => `${caseId}/notes`,
  );
}

export function useDecideRetirementCase(publicId: string) {
  return useRetirementCaseMutation<DecideRetirementCaseInput>(
    publicId,
    (caseId) => `${caseId}/decision`,
  );
}

export function useFinalizeRetirementCase(publicId: string) {
  return useRetirementCaseMutation<{ effective_date: string }>(
    publicId,
    (caseId) => `${caseId}/finalize`,
  );
}

export function useCancelRetirementCase(publicId: string) {
  return useRetirementCaseMutation<{ notes?: string | null }>(
    publicId,
    (caseId) => `${caseId}/cancel`,
  );
}
