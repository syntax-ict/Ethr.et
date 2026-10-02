import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { operations } from "@/api/generated";

type SummaryContract = Extract<
  operations["teamMonitoring.attendanceSummary"]["responses"][200]["content"]["application/json"],
  { team_size: number }
>;

export type TeamAttendanceDay = SummaryContract["data"][number];

/**
 * A caller with no team gets `{ data: [] }` and nothing else; the contract
 * types that literal empty array as `string[]`, so this branch is stated here.
 */
export type TeamAttendanceSummaryResponse = SummaryContract | { data: [] };

export function useTeamAttendanceSummary(
  period: "weekly" | "monthly" = "weekly",
) {
  return useQuery<TeamAttendanceSummaryResponse>({
    queryKey: ["team", "attendance", "summary", period],
    queryFn: async () => {
      const { data } = await apiClient.get("/team/attendance/summary", {
        params: { period },
      });
      return data;
    },
    staleTime: 5 * 60 * 1000,
  });
}
