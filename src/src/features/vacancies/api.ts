import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components } from "@/api/generated";
import { fetchAllPages } from "@/api/fetch-all-pages";

export type Vacancy = components["schemas"]["VacancyResource"];
export type VacancyPayload = components["schemas"]["VacancyRequest"];
export type EmploymentType = VacancyPayload["employment_type"];
export type VacancyStatus = NonNullable<VacancyPayload["status"]>;
export type VacancyVisibility = NonNullable<VacancyPayload["visibility"]>;

export const EMPLOYMENT_TYPES: readonly EmploymentType[] = [
  "full_time",
  "part_time",
  "contract",
  "temporary",
  "internship",
];

const keys = {
  all: ["vacancies"] as const,
};

/**
 * Every vacancy the caller may see: what is advertised now for an employee,
 * and drafts and closed ones too for whoever holds `vacancy.manage` - the API
 * decides which.
 */
export function useVacancies() {
  return useQuery<Vacancy[]>({
    queryKey: [...keys.all, "list"],
    queryFn: () => fetchAllPages<Vacancy>("/vacancies"),
  });
}

function useVacancyMutation<TVars, TData>(
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

export function useCreateVacancy() {
  return useVacancyMutation(
    async (payload: VacancyPayload): Promise<Vacancy> =>
      (await apiClient.post<Vacancy>("/vacancies", payload)).data,
  );
}

export function useUpdateVacancy() {
  return useVacancyMutation(
    async (vars: {
      publicId: string;
      payload: Partial<VacancyPayload>;
    }): Promise<Vacancy> =>
      (
        await apiClient.put<Vacancy>(
          `/vacancies/${vars.publicId}`,
          vars.payload,
        )
      ).data,
  );
}

export function useDeleteVacancy() {
  return useVacancyMutation(async (publicId: string): Promise<void> => {
    await apiClient.delete(`/vacancies/${publicId}`);
  });
}
