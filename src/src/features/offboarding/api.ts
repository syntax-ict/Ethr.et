import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components, operations } from "@/api/generated";

type Schemas = components["schemas"];

/** One line of an exit clearance; `can_tick` is for the reader. */
export type ExitClearanceItem = Schemas["ExitClearanceItemResource"];
export type ExitClearance = Schemas["ExitClearanceResource"];
export type ExitClearanceOwner = "hr" | "finance" | "supervisor";
export type ExitClearanceKind = "manual" | "obligations";

type ShowResponse =
  operations["exitClearance.show"]["responses"][200]["content"]["application/json"];

/**
 * `GET /employees/{id}/exit-clearance`. The API returns `clearance: null`
 * for someone with none; the generated contract drops that null, so it is
 * stated here.
 */
export type ExitClearanceShow = Omit<ShowResponse, "clearance"> & {
  clearance: ShowResponse["clearance"] | null;
};

export interface ExitClearanceTemplateLine {
  key: string | null;
  label: string;
  owner: ExitClearanceOwner;
  kind: ExitClearanceKind;
}

export interface ExitClearanceTemplate {
  items: ExitClearanceTemplateLine[];
  is_customized: boolean;
}

export interface ExitClearancePage {
  data: ExitClearance[];
  meta?: { total?: number };
}

function clearanceKey(employeeId: string) {
  return ["employees", employeeId, "exit-clearance"] as const;
}

const listKey = ["exit-clearances"] as const;
const templateKey = ["settings", "exit-clearance"] as const;

export function useExitClearance(employeeId: string, enabled = true) {
  return useQuery<ExitClearanceShow>({
    queryKey: clearanceKey(employeeId),
    queryFn: async () =>
      (
        await apiClient.get<ExitClearanceShow>(
          `/employees/${employeeId}/exit-clearance`,
        )
      ).data,
    enabled: enabled && employeeId !== "",
  });
}

/** Start one by hand, for someone who left before the checklist existed. */
export function useStartExitClearance(employeeId: string) {
  const queryClient = useQueryClient();

  return useMutation<ExitClearance, unknown, void>({
    mutationFn: async () =>
      (await apiClient.post(`/employees/${employeeId}/exit-clearance`)).data,
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: clearanceKey(employeeId),
      });
      void queryClient.invalidateQueries({ queryKey: listKey });
    },
  });
}

export function useTickExitClearanceItem(employeeId: string) {
  const queryClient = useQueryClient();

  return useMutation<
    ExitClearanceItem,
    unknown,
    { itemId: string; done: boolean; note?: string | null }
  >({
    mutationFn: async ({ itemId, done, note }) =>
      (
        await apiClient.put(
          `/employees/${employeeId}/exit-clearance/items/${itemId}`,
          { done, note: note ?? null },
        )
      ).data,
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: clearanceKey(employeeId),
      });
      void queryClient.invalidateQueries({ queryKey: listKey });
    },
  });
}

/** `GET /exit-clearances`: the clearances the reader has a part in. */
export function useExitClearances(status: "open" | "complete") {
  return useQuery<ExitClearancePage>({
    queryKey: [...listKey, status],
    queryFn: async () =>
      (
        await apiClient.get<ExitClearancePage>("/exit-clearances", {
          params: { status, per_page: 100 },
        })
      ).data,
  });
}

export function useExitClearanceTemplate() {
  return useQuery<ExitClearanceTemplate>({
    queryKey: templateKey,
    queryFn: async () =>
      (await apiClient.get<ExitClearanceTemplate>("/settings/exit-clearance"))
        .data,
  });
}

export function useSaveExitClearanceTemplate() {
  const queryClient = useQueryClient();

  return useMutation<
    ExitClearanceTemplate,
    unknown,
    ExitClearanceTemplateLine[]
  >({
    mutationFn: async (items) =>
      (await apiClient.put("/settings/exit-clearance", { items })).data,
    onSuccess: (data) => queryClient.setQueryData(templateKey, data),
  });
}
