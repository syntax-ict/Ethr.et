import { useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components } from "@/api/generated";

/**
 * A sheet of one-time activation codes, one for each of many employees no
 * invitation can reach (`ActivationCodeSheetController`, owner decision
 * 2026-11-10). HR prints it, checks each person's identity and hands them
 * their slip; the person chooses their own password.
 */
export type IssueActivationCodesInput =
  components["schemas"]["IssueActivationCodesRequest"];

/** The most people one sheet covers (`ActivationCodeService::SHEET_MAX`). */
export const CODE_SHEET_MAX = 200;

/**
 * How long a sheet's codes may live, in hours: three days, or the week a
 * large office needs to hand them all out
 * (`ActivationCodeService::SHEET_DEFAULT_TTL_HOURS`, `SHEET_MAX_TTL_HOURS`).
 */
export const CODE_SHEET_HOURS = [72, 168] as const;

export interface ActivationCodeSlip {
  employee_public_id: string;
  name: string;
  employee_code: string;
  /** As it is read aloud and typed: `ABCD-EFGH-JKMN`. */
  code: string;
  /** What the QR on the slip opens. */
  activation_url: string;
}

/** Someone the sheet could not cover, and why, in the reader's language. */
export interface ActivationCodeRefusal {
  employee_public_id: string;
  name: string;
  reason: string;
  detail: string;
}

/** What issuing returns: the only time the codes are ever visible. */
export interface ActivationCodeSheet {
  batch: string | null;
  expires_at: string;
  issued: number;
  codes: ActivationCodeSlip[];
  refused: ActivationCodeRefusal[];
}

export function useIssueActivationCodes() {
  const qc = useQueryClient();
  return useMutation({
    // The codes live in this mutation's result and nowhere else; the dialog
    // resets the mutation when it closes so they do not outlive it.
    gcTime: 0,
    mutationFn: async (input: IssueActivationCodesInput) => {
      const { data } = await apiClient.post<ActivationCodeSheet>(
        "/employees/activation-codes",
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
