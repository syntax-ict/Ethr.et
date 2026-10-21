import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { operations } from "@/api/generated";
import { saveBlob } from "@/lib/utils/csv-export";

/** `GET /settings/data-export`: the organisation's recent exports. */
export type DataExportList =
  operations["dataExport.index"]["responses"][200]["content"]["application/json"];

export type DataExport = DataExportList["data"][number];

const key = ["settings", "data-export"] as const;

export function useDataExports() {
  return useQuery<DataExportList>({
    queryKey: key,
    queryFn: async () => (await apiClient.get("/settings/data-export")).data,
    // An export is built by the queue, which runs every few minutes on the
    // host. Poll while one is waiting so the page turns to "Ready" on its own.
    refetchInterval: (query) =>
      query.state.data?.data.some(
        (e) => e.status === "queued" || e.status === "running",
      )
        ? 30_000
        : false,
  });
}

export function useRequestDataExport() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () =>
      (await apiClient.post<DataExport>("/settings/data-export")).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: key }),
  });
}

/**
 * Download an export through the API client rather than a plain link: on the
 * single production host the organisation travels in the `X-Tenant` header,
 * which a navigation cannot send.
 */
export async function downloadDataExport(exp: DataExport): Promise<void> {
  const { data, headers } = await apiClient.get<Blob>(
    `/settings/data-export/${exp.public_id}/download`,
    { responseType: "blob" },
  );
  const disposition = String(headers["content-disposition"] ?? "");
  const match = /filename="?([^";]+)"?/.exec(disposition);
  saveBlob(
    match?.[1] ?? `ethr-export-${exp.created_at?.slice(0, 10) ?? "data"}.zip`,
    data,
  );
}
