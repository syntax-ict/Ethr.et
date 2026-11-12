import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";

/**
 * What an employee is known as outside ETHR, and employees who may be one
 * person recorded twice (`EmployeeIdentityController`,
 * `PossibleDuplicatesController`; One employee identity, PR 11).
 */

/** How a record was made (`EmployeeIntake::VIA_*`). */
export type CreatedVia =
  | "manual"
  | "api"
  | "import"
  | "migration"
  | "device"
  | "recruitment"
  | "scim"
  | "sso";

/** One id the person is known by in one place. */
export interface EmployeeIdentity {
  public_id: string;
  /** An attendance terminal's number, another system's id, or anything else. */
  kind: "device" | "other_system" | "other";
  /** The terminal's or the system's name, when ETHR still knows it. */
  source: string | null;
  source_type: string;
  identifier_type: string;
  /** Null for a kind of identifier that is never shown. */
  identifier_value: string | null;
  /** A person confirmed the link; ETHR did not only infer it. */
  verified: boolean;
  linked_at: string | null;
}

export interface EmployeeIdentities {
  /** Null for a record older than the day every way in began asking first. */
  created_via: CreatedVia | null;
  identities: EmployeeIdentity[];
}

const key = (employeeId: string) =>
  ["employees", employeeId, "identities"] as const;

export function useEmployeeIdentities(employeeId: string) {
  return useQuery<EmployeeIdentities>({
    queryKey: key(employeeId),
    queryFn: async () =>
      (await apiClient.get(`/employees/${employeeId}/identities`)).data,
  });
}

/** Confirm that an id elsewhere is this employee. */
export function useVerifyIdentity(employeeId: string) {
  const qc = useQueryClient();
  return useMutation<void, unknown, string>({
    mutationFn: async (identityId) => {
      await apiClient.post(
        `/employees/${employeeId}/identities/${identityId}/verify`,
      );
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: key(employeeId) }),
  });
}

/** Remove a link that is wrong. The employee's record is not changed. */
export function useUnlinkIdentity(employeeId: string) {
  const qc = useQueryClient();
  return useMutation<void, unknown, string>({
    mutationFn: async (identityId) => {
      await apiClient.delete(
        `/employees/${employeeId}/identities/${identityId}`,
      );
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: key(employeeId) }),
  });
}

/** Why two records are put together: something a person has only one of. */
export type DuplicateReason =
  "national_id" | "email" | "phone" | "badge_number" | "name_and_birth_date";

export interface DuplicatePerson {
  public_id: string;
  name: string;
  employee_code: string | null;
  status: string;
  department: string | null;
  created_via: CreatedVia | null;
  created_at: string | null;
}

export interface PossibleDuplicates {
  groups: { reasons: DuplicateReason[]; employees: DuplicatePerson[] }[];
  /** There were more groups than one answer carries. */
  truncated: boolean;
}

export function usePossibleDuplicates() {
  return useQuery<PossibleDuplicates>({
    queryKey: ["employees", "possible-duplicates"],
    queryFn: async () =>
      (await apiClient.get("/employees/possible-duplicates")).data,
  });
}
