import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components } from "@/api/generated";

/**
 * In-person activation for an employee with no email, and no phone a text can
 * reach (text invitations are off until SMS is live). HR issues a
 * one-time code face to face; the employee redeems it and picks their own
 * password (`ActivationCodeController`, `ActivationRedeemController`).
 */
export type ActivationDocument = NonNullable<
  components["schemas"]["IssueActivationCodeRequest"]["document_type"]
>;

export const ACTIVATION_DOCUMENTS: readonly ActivationDocument[] = [
  "national_id",
  "kebele_id",
  "passport",
  "driving_licence",
  "employer_id",
];

export interface ActivationStatus {
  /** `none` until a login exists, then the account's status. */
  login_status: string;
  /**
   * A link or a text can reach them, so no code is issued. A phone counts only
   * once the platform sends invitations by text; until then a person with
   * only a phone is activated in person.
   */
  reachable: boolean;
  state: "none" | "live" | "used" | "expired" | "revoked" | "locked";
  purpose: "activation" | "recovery" | null;
  expires_at: string | null;
  document_type: string | null;
}

/** What issuing returns: the only time the code is ever visible. */
export interface IssuedActivationCode {
  code: string;
  employee_code: string | null;
  purpose: "activation" | "recovery";
  expires_at: string;
  activation_url: string;
}

export interface IssueActivationInput {
  document_type: ActivationDocument;
  note?: string;
  current_password: string;
  mfa_code?: string;
}

const key = (publicId: string) => ["employees", publicId, "activation-code"];

export function useActivationStatus(publicId: string, enabled: boolean) {
  return useQuery({
    queryKey: key(publicId),
    enabled,
    // The status says whether a code is live; never reuse an old answer.
    staleTime: 0,
    gcTime: 0,
    queryFn: async () => {
      const { data } = await apiClient.get<ActivationStatus>(
        `/employees/${publicId}/activation-code`,
      );
      return data;
    },
  });
}

export function useIssueActivationCode(publicId: string) {
  const qc = useQueryClient();
  return useMutation({
    // The code lives in this mutation's result and nowhere else; the dialog
    // resets the mutation when it closes so it does not outlive the screen.
    gcTime: 0,
    mutationFn: async (input: IssueActivationInput) => {
      const { data } = await apiClient.post<IssuedActivationCode>(
        `/employees/${publicId}/activation-code`,
        { attested: true, ...input },
      );
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: key(publicId) }),
  });
}

export function useRevokeActivationCode(publicId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.delete<{ revoked: boolean }>(
        `/employees/${publicId}/activation-code`,
      );
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: key(publicId) }),
  });
}
