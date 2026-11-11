import { useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components } from "@/api/generated";

/**
 * One starting password for a batch of employees who have no email to be
 * invited by (`StartingPasswordController`, owner decision 2026-11-10). ETHR
 * makes the password; each person signs in with their employee number and
 * must choose their own before anything else.
 */
export type IssueStartingPasswordInput =
  components["schemas"]["IssueStartingPasswordRequest"];

/** The most people one request takes (`StartingPasswords::MAX_BATCH`). */
export const STARTING_PASSWORD_BATCH = 200;

/** How long an unused starting password works (`StartingPasswords::TTL_HOURS`). */
export const STARTING_PASSWORD_HOURS = 72;

export interface StartingPasswordPerson {
  employee_public_id: string;
  name: string;
  employee_code: string;
}

/** Why someone named in the batch was left exactly as they were. */
export interface StartingPasswordSkipped {
  active: number;
  role: number;
  closed: number;
  reachable: number;
}

/** What issuing returns: the only time the password is ever visible. */
export interface IssuedStartingPassword {
  password: string;
  expires_at: string;
  issued: number;
  people: StartingPasswordPerson[];
  skipped: StartingPasswordSkipped;
}

export function useIssueStartingPassword() {
  const qc = useQueryClient();
  return useMutation({
    // The password lives in this mutation's result and nowhere else; the
    // dialog resets the mutation when it closes so it does not outlive it.
    gcTime: 0,
    mutationFn: async (input: IssueStartingPasswordInput) => {
      const { data } = await apiClient.post<IssuedStartingPassword>(
        "/employees/starting-password",
        input,
      );
      return data;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["onboarding", "migration"] });
      qc.invalidateQueries({ queryKey: ["users"] });
    },
  });
}
