import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components, operations } from "@/api/generated";
import type { PaginatedResponse } from "@/api/types";
import type { Employee } from "./types";

/**
 * The create and update bodies come from the contract. The hand-written
 * `EmployeeFormData` they replace named the relations `department_public_id`,
 * `branch_public_id` and `position_public_id`; StoreEmployeeRequest reads
 * `department_id`, `branch_id` and `position_id`, so `validated()` dropped all
 * three and every employee created from the form had no department, branch or
 * position — behind a 201 and a success toast.
 */
export type CreateEmployeePayload =
  components["schemas"]["StoreEmployeeRequest"];
export type UpdateEmployeePayload =
  components["schemas"]["UpdateEmployeeRequest"];

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
    mutationFn: async (payload: CreateEmployeePayload) => {
      const { data } = await apiClient.post("/employees", payload);
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
    mutationFn: async (payload: UpdateEmployeePayload) => {
      const { data } = await apiClient.put(`/employees/${publicId}`, payload);
      return data;
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

// ── Export ─────────────────────────────────────────────────────────

export type EmployeeExport =
  operations["employeeBulk.export"]["responses"][200]["content"]["application/json"];

/**
 * Every employee matching `search`, as one CSV the server builds from the
 * whole filtered set — not the page on screen. A one-shot download, so a plain
 * function rather than a query.
 */
export async function exportEmployees(params: {
  search?: string;
}): Promise<EmployeeExport> {
  const { data } = await apiClient.get<EmployeeExport>("/employees/export", {
    params: { search: params.search || undefined },
  });
  return data;
}

// ── CSV import (template → preview → commit) ──────────────────────

/**
 * `errors` is keyed by CSV line number — the header is line 1, so data row
 * `i` is line `i + 2` — and key 0 is a file-level error (a missing required
 * column, or no data rows), which comes with no rows at all.
 */
export type EmployeeImportPreview =
  operations["employeeImport.preview"]["responses"][200]["content"]["application/json"];

/**
 * `skipped` rows carried an import key already used; `matched` rows are a
 * person who already exists (same code, email, phone or national id). Neither
 * creates an employee.
 */
export type EmployeeImportResult =
  operations["employeeImport.commit"]["responses"][201]["content"]["application/json"];

export type EmployeeImportTemplate =
  operations["employeeImport.template"]["responses"][200]["content"]["application/json"];

/** The blank CSV template. The endpoint reads no body; it is only a POST. */
export async function fetchEmployeeImportTemplate(): Promise<EmployeeImportTemplate> {
  const { data } = await apiClient.post<EmployeeImportTemplate>(
    "/employees/import/template",
  );
  return data;
}

/** Parses and validates a CSV server-side; nothing is written. */
export function usePreviewEmployeeImport() {
  return useMutation({
    mutationFn: async (file: File): Promise<EmployeeImportPreview> => {
      const fd = new FormData();
      fd.append("file", file);
      const { data } = await apiClient.post<EmployeeImportPreview>(
        "/employees/import/preview",
        fd,
        { headers: { "Content-Type": "multipart/form-data" } },
      );
      return data;
    },
  });
}

export function useCommitEmployeeImport() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (vars: {
      importKey: string;
      rows: EmployeeImportPreview["rows"];
    }): Promise<EmployeeImportResult> => {
      const { data } = await apiClient.post<EmployeeImportResult>(
        "/employees/import/commit",
        { import_key: vars.importKey, rows: vars.rows },
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["employees"] });
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

export type ContractType = components["schemas"]["ContractType"];

export type ContractStatus = components["schemas"]["ContractStatus"];

export type EmployeeContract =
  components["schemas"]["EmployeeContractResource"];

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

// ── Profile sub-resources (bank, documents, education, emergency contacts) ──
//
// Every index below is a bare array: AppServiceProvider calls
// `JsonResource::withoutWrapping()`, so a non-paginated resource collection has
// no `data` key. The tabs these replace read `data.data` and rendered their
// empty state no matter what was on file.
//
// Toasts stay with the component, passed as `mutate(vars, { onError })`; a
// hook owns only the request and what it invalidates.

export type BankDetail = components["schemas"]["BankDetailResource"];
export type BankDetailPayload = components["schemas"]["StoreBankDetailRequest"];

export type EmployeeDocument =
  components["schemas"]["EmployeeDocumentResource"];

/** `file` is a `File` on the wire; the contract can only call it a string. */
export type DocumentUpload = Omit<
  components["schemas"]["StoreDocumentRequest"],
  "file"
> & { file: File };

export type EducationRecord = components["schemas"]["EducationResource"];
export type EducationPayload = components["schemas"]["StoreEducationRequest"];

export type EmergencyContact =
  components["schemas"]["EmergencyContactResource"];
export type EmergencyContactPayload =
  components["schemas"]["StoreEmergencyContactRequest"];

function subResourceKey(publicId: string, resource: string) {
  return ["employees", publicId, resource];
}

function useSubResourceList<T>(publicId: string, resource: string) {
  return useQuery<T[]>({
    queryKey: subResourceKey(publicId, resource),
    queryFn: async () =>
      (await apiClient.get<T[]>(`/employees/${publicId}/${resource}`)).data,
    enabled: !!publicId,
  });
}

/**
 * A write to `/employees/{id}/{resource}`. Invalidates that list, plus any
 * query outside the employee tree that reads the same rows.
 */
function useSubResourceWrite<TVars, TData>(
  publicId: string,
  resource: string,
  mutationFn: (vars: TVars) => Promise<TData>,
  alsoInvalidate: readonly (readonly string[])[] = [],
) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn,
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: subResourceKey(publicId, resource),
      });
      for (const queryKey of alsoInvalidate) {
        queryClient.invalidateQueries({ queryKey });
      }
    },
  });
}

export function useEmployeeBankDetails(publicId: string) {
  return useSubResourceList<BankDetail>(publicId, "bank-details");
}

export function useAddBankDetail(publicId: string) {
  return useSubResourceWrite(
    publicId,
    "bank-details",
    async (payload: BankDetailPayload) =>
      (
        await apiClient.post<BankDetail>(
          `/employees/${publicId}/bank-details`,
          payload,
        )
      ).data,
  );
}

export function useDeleteBankDetail(publicId: string) {
  return useSubResourceWrite(publicId, "bank-details", async (id: string) => {
    await apiClient.delete(`/employees/${publicId}/bank-details/${id}`);
  });
}

/**
 * The executive compliance card counts expiring documents, so a document
 * upload or deletion refreshes it too.
 */
const COMPLIANCE_KEY = ["dashboard", "executive", "compliance"] as const;

export function useEmployeeDocuments(publicId: string) {
  return useSubResourceList<EmployeeDocument>(publicId, "documents");
}

export function useUploadEmployeeDocument(publicId: string) {
  return useSubResourceWrite(
    publicId,
    "documents",
    async (upload: DocumentUpload) => {
      const fd = new FormData();
      fd.append("file", upload.file);
      fd.append("title", upload.title);
      fd.append("type", upload.type);
      if (upload.expiry_date) fd.append("expiry_date", upload.expiry_date);
      return (
        await apiClient.post<EmployeeDocument>(
          `/employees/${publicId}/documents`,
          fd,
          { headers: { "Content-Type": "multipart/form-data" } },
        )
      ).data;
    },
    [COMPLIANCE_KEY],
  );
}

export function useDeleteEmployeeDocument(publicId: string) {
  return useSubResourceWrite(
    publicId,
    "documents",
    async (id: string) => {
      await apiClient.delete(`/employees/${publicId}/documents/${id}`);
    },
    [COMPLIANCE_KEY],
  );
}

export function useEmployeeEducation(publicId: string) {
  return useSubResourceList<EducationRecord>(publicId, "education");
}

export function useAddEducation(publicId: string) {
  return useSubResourceWrite(
    publicId,
    "education",
    async (payload: EducationPayload) =>
      (
        await apiClient.post<EducationRecord>(
          `/employees/${publicId}/education`,
          payload,
        )
      ).data,
  );
}

export function useDeleteEducation(publicId: string) {
  return useSubResourceWrite(publicId, "education", async (id: string) => {
    await apiClient.delete(`/employees/${publicId}/education/${id}`);
  });
}

export function useEmployeeEmergencyContacts(publicId: string) {
  return useSubResourceList<EmergencyContact>(publicId, "emergency-contacts");
}

export function useAddEmergencyContact(publicId: string) {
  return useSubResourceWrite(
    publicId,
    "emergency-contacts",
    async (payload: EmergencyContactPayload) =>
      (
        await apiClient.post<EmergencyContact>(
          `/employees/${publicId}/emergency-contacts`,
          payload,
        )
      ).data,
  );
}

export function useDeleteEmergencyContact(publicId: string) {
  return useSubResourceWrite(
    publicId,
    "emergency-contacts",
    async (id: string) => {
      await apiClient.delete(`/employees/${publicId}/emergency-contacts/${id}`);
    },
  );
}

// ── Status transitions (lifecycle) ──────────────────────────────────

export type EmployeeTransition =
  components["schemas"]["EmployeeTransitionResource"];
export type TransitionPayload =
  components["schemas"]["TransitionEmployeeRequest"];

export function useEmployeeTransitions(publicId: string) {
  return useSubResourceList<EmployeeTransition>(publicId, "transitions");
}

/**
 * A transition changes the employee's status, which every employee query and
 * every dashboard headcount reads, so this refreshes both trees rather than
 * only the history list.
 */
export function useTransitionEmployee(publicId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (payload: TransitionPayload) =>
      (
        await apiClient.post<EmployeeTransition>(
          `/employees/${publicId}/transition`,
          payload,
        )
      ).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["employees"] });
      queryClient.invalidateQueries({ queryKey: ["dashboard"] });
    },
  });
}

// ── Attendance timeline ─────────────────────────────────────────────

export type AttendanceTimeline =
  operations["employee.employeeAttendanceTimeline"]["responses"][200]["content"]["application/json"];
export type AttendanceTimelineDay = AttendanceTimeline["days"][number];

export function useEmployeeAttendanceTimeline(
  publicId: string,
  range: { from: string; to: string },
) {
  return useQuery<AttendanceTimeline>({
    queryKey: [...subResourceKey(publicId, "attendance-timeline"), range],
    queryFn: async () =>
      (
        await apiClient.get<AttendanceTimeline>(
          `/employees/${publicId}/attendance/timeline`,
          { params: range },
        )
      ).data,
    enabled: !!publicId,
  });
}
