import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components } from "@/api/generated";
import { fetchAllPages } from "@/api/fetch-all-pages";

type Schemas = components["schemas"];

export type ApplicationStage =
  | "applied"
  | "screening"
  | "interview"
  | "offer"
  | "hired"
  | "rejected"
  | "withdrawn";

export interface PossibleDuplicate {
  kind: "application" | "employee";
  public_id: string;
  name: string;
  vacancy: string | null;
}

/**
 * The generated type cannot see two things the resource does: the stage is
 * an enum, and `possible_duplicates` is a list (Scramble reads the closure
 * that fills it as a string). Both are restated here, and nothing else.
 */
export type JobApplication = Omit<
  Schemas["JobApplicationResource"],
  "stage" | "possible_duplicates"
> & {
  stage: ApplicationStage;
  possible_duplicates?: PossibleDuplicate[];
};
export type ApplicationEvent = Schemas["JobApplicationEventResource"];
export type NewApplication = Schemas["StoreJobApplicationRequest"];
export type MovePayload = Schemas["MoveJobApplicationRequest"];
export type RecordPayload = Schemas["RecordApplicationEventRequest"];
export type HirePayload = Schemas["HireApplicationRequest"];

export const APPLICATION_SOURCES: readonly NewApplication["source"][] = [
  "email",
  "walk_in",
  "referral",
  "internal",
  "agency",
  "website",
  "other",
];

/** The moves the API accepts from each stage; hiring has its own action. */
export const NEXT_STAGES: Record<ApplicationStage, MovePayload["stage"][]> = {
  applied: ["screening", "rejected", "withdrawn"],
  screening: ["interview", "rejected", "withdrawn"],
  interview: ["offer", "rejected", "withdrawn"],
  offer: ["rejected", "withdrawn"],
  hired: [],
  rejected: [],
  withdrawn: [],
};

const keys = {
  all: ["recruitment"] as const,
  list: (vacancy: string) => ["recruitment", "vacancy", vacancy] as const,
  one: (application: string) =>
    ["recruitment", "application", application] as const,
};

export function useApplications(vacancyId: string | null) {
  return useQuery<JobApplication[]>({
    queryKey: keys.list(vacancyId ?? ""),
    queryFn: () =>
      fetchAllPages<JobApplication>(`/vacancies/${vacancyId}/applications`),
    enabled: vacancyId !== null,
  });
}

export function useApplication(applicationId: string | null) {
  return useQuery<JobApplication>({
    queryKey: keys.one(applicationId ?? ""),
    queryFn: async () =>
      (
        await apiClient.get<JobApplication>(
          `/job-applications/${applicationId}`,
        )
      ).data,
    enabled: applicationId !== null,
  });
}

function useRecruitmentMutation<TVars, TData>(
  mutationFn: (vars: TVars) => Promise<TData>,
) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: keys.all });
    },
  });
}

export function useAddApplication() {
  return useRecruitmentMutation(
    async (vars: {
      vacancyId: string;
      payload: NewApplication;
    }): Promise<JobApplication> =>
      (
        await apiClient.post<JobApplication>(
          `/vacancies/${vars.vacancyId}/applications`,
          vars.payload,
        )
      ).data,
  );
}

export function useMoveApplication() {
  return useRecruitmentMutation(
    async (vars: {
      id: string;
      payload: MovePayload;
    }): Promise<JobApplication> =>
      (
        await apiClient.post<JobApplication>(
          `/job-applications/${vars.id}/stage`,
          vars.payload,
        )
      ).data,
  );
}

export function useRecordApplicationEvent() {
  return useRecruitmentMutation(
    async (vars: {
      id: string;
      payload: RecordPayload;
    }): Promise<ApplicationEvent> =>
      (
        await apiClient.post<ApplicationEvent>(
          `/job-applications/${vars.id}/events`,
          vars.payload,
        )
      ).data,
  );
}

export function useApproveOffer() {
  return useRecruitmentMutation(
    async (id: string): Promise<JobApplication> =>
      (
        await apiClient.post<JobApplication>(
          `/job-applications/${id}/approve-offer`,
          {},
        )
      ).data,
  );
}

export function useHireApplication() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (vars: {
      id: string;
      payload: HirePayload;
    }): Promise<Schemas["EmployeeResource"]> =>
      (
        await apiClient.post<Schemas["EmployeeResource"]>(
          `/job-applications/${vars.id}/hire`,
          vars.payload,
        )
      ).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: keys.all });
      queryClient.invalidateQueries({ queryKey: ["employees"] });
    },
  });
}

export function useEraseApplication() {
  return useRecruitmentMutation(
    async (id: string): Promise<JobApplication> =>
      (
        await apiClient.post<JobApplication>(
          `/job-applications/${id}/erase`,
          {},
        )
      ).data,
  );
}
