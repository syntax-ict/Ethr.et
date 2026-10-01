import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { fetchAllPages } from "@/api/fetch-all-pages";
import type { components } from "@/api/generated";

// Shapes come from the generated contract. The hand-written `Shift` this
// replaces typed `working_days` as `number[]`; ShiftResource sends the stored
// string, `"1,2,3,4,5"` (ISO weekdays, Monday = 1).
export type Shift = components["schemas"]["ShiftResource"];
export type ShiftAssignment = components["schemas"]["ShiftAssignmentResource"];
export type CreateShiftPayload = components["schemas"]["StoreShiftRequest"];
export type UpdateShiftPayload = components["schemas"]["UpdateShiftRequest"];
export type AssignShiftPayload = components["schemas"]["AssignShiftRequest"];
export type AssignableEmployee = components["schemas"]["EmployeeResource"];

const keys = {
  all: ["shifts"] as const,
  list: ["shifts", "list"] as const,
  schedule: (filters?: ScheduleFilters) =>
    ["shifts", "schedule", filters ?? {}] as const,
};

/**
 * Every shift, not the first page. The list feeds the Shifts page, the roster
 * legend and every shift picker (assignments, rotation steps), none of which
 * paginate — and the API returns 25 rows unless asked, at most 100.
 */
export function useShifts() {
  return useQuery<{ data: Shift[] }>({
    queryKey: keys.list,
    queryFn: async () => ({ data: await fetchAllPages<Shift>("/shifts") }),
    staleTime: 30 * 60 * 1000,
  });
}

export interface ScheduleFilters {
  /** Assignments still in force on or after this date (`YYYY-MM-DD`). */
  date_from?: string;
  /** Assignments that have started by this date (`YYYY-MM-DD`). */
  date_to?: string;
}

/**
 * Every shift assignment overlapping the window — fixed shifts and rotations
 * alike (a rotation assignment has `shift: null` and `is_rotation: true`).
 * All pages: the roster draws each one, so a dropped page is a blank day.
 */
export function useShiftSchedule(filters?: ScheduleFilters) {
  return useQuery<{ data: ShiftAssignment[] }>({
    queryKey: keys.schedule(filters),
    queryFn: async () => ({
      data: await fetchAllPages<ShiftAssignment>("/shifts/schedule", {
        "filter[date_from]": filters?.date_from,
        "filter[date_to]": filters?.date_to,
      }),
    }),
    staleTime: 5 * 60 * 1000,
  });
}

/**
 * Every employee, for the "assign to" pickers. The pickers asked for
 * `per_page=200`, which the API caps at 100, so the 101st employee could not
 * be given a shift or a rotation.
 */
export function useAssignableEmployees(enabled = true) {
  return useQuery<{ data: AssignableEmployee[] }>({
    queryKey: ["employees", "shift-assignable"],
    queryFn: async () => ({
      data: await fetchAllPages<AssignableEmployee>("/employees"),
    }),
    enabled,
    staleTime: 5 * 60 * 1000,
  });
}

function useShiftMutation<TVars, TData>(
  mutationFn: (vars: TVars) => Promise<TData>,
) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: () => {
      // The schedule embeds each assignment's shift, and the list carries
      // `assignments_count`, so every write here stales both.
      queryClient.invalidateQueries({ queryKey: keys.all });
    },
  });
}

export function useCreateShift() {
  return useShiftMutation(
    async (payload: CreateShiftPayload): Promise<Shift> =>
      (await apiClient.post("/shifts", payload)).data,
  );
}

export function useUpdateShift() {
  return useShiftMutation(
    async (vars: {
      publicId: string;
      payload: UpdateShiftPayload;
    }): Promise<Shift> =>
      (await apiClient.put(`/shifts/${vars.publicId}`, vars.payload)).data,
  );
}

export function useDeleteShift() {
  return useShiftMutation(async (publicId: string): Promise<void> => {
    await apiClient.delete(`/shifts/${publicId}`);
  });
}

export function useAssignShift() {
  return useShiftMutation(
    async (payload: AssignShiftPayload): Promise<ShiftAssignment> =>
      (await apiClient.post("/shifts/assign", payload)).data,
  );
}
