import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";

/** The languages a letter is written in: `Locales::SHIPPED` on the server. */
export const LETTER_LANGUAGES = ["en", "am", "om"] as const;
export type LetterLanguage = (typeof LETTER_LANGUAGES)[number];

export function toLetterLanguage(value: string): LetterLanguage {
  return (LETTER_LANGUAGES as readonly string[]).includes(value)
    ? (value as LetterLanguage)
    : "en";
}

/** EmploymentLetters::present() on the server. */
export interface LetterRequest {
  public_id: string;
  employee: { public_id: string; name: string } | null;
  purpose: string;
  addressed_to: string | null;
  include_salary: boolean;
  language: LetterLanguage;
  status: "pending" | "issued" | "rejected";
  reference: string | null;
  rejection_reason: string | null;
  decided_at: string | null;
  created_at: string | null;
}

export interface NewLetterRequest {
  purpose: string;
  addressed_to?: string;
  include_salary: boolean;
  language: LetterLanguage;
}

const MY_KEY = ["profile", "letters"];
const HR_KEY = ["employees", "letters"];

export function useMyLetters() {
  return useQuery<LetterRequest[]>({
    queryKey: MY_KEY,
    queryFn: async () => {
      const { data } = await apiClient.get<{ data: LetterRequest[] }>(
        "/profile/letters",
      );
      return data.data;
    },
  });
}

export function useRequestLetter() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (payload: NewLetterRequest) => {
      const { data } = await apiClient.post<LetterRequest>(
        "/profile/letters",
        payload,
      );
      return data;
    },
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: MY_KEY }),
  });
}

/** The issued letter as a PDF, saved by the browser. */
export async function downloadMyLetter(letter: LetterRequest): Promise<void> {
  const { data } = await apiClient.get<Blob>(
    `/profile/letters/${letter.public_id}/pdf`,
    { responseType: "blob" },
  );
  const url = URL.createObjectURL(data);
  const link = document.createElement("a");
  link.href = url;
  link.download = `employment-letter-${letter.reference ?? letter.public_id}.pdf`;
  link.click();
  URL.revokeObjectURL(url);
}

export function useLetterRequests(status: LetterRequest["status"]) {
  return useQuery<LetterRequest[]>({
    queryKey: [...HR_KEY, status],
    queryFn: async () => {
      const { data } = await apiClient.get<{ data: LetterRequest[] }>(
        "/employees/letters",
        { params: { status } },
      );
      return data.data;
    },
  });
}

export function useIssueLetter() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (publicId: string) => {
      const { data } = await apiClient.post<LetterRequest>(
        `/employees/letters/${publicId}/issue`,
      );
      return data;
    },
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: HR_KEY }),
  });
}

export function useRejectLetter() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (vars: { publicId: string; reason: string }) => {
      const { data } = await apiClient.post<LetterRequest>(
        `/employees/letters/${vars.publicId}/reject`,
        { reason: vars.reason },
      );
      return data;
    },
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: HR_KEY }),
  });
}
