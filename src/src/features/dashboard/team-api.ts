import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { operations } from "@/api/generated";

type SummaryContract = Extract<
  operations["teamMonitoring.attendanceSummary"]["responses"][200]["content"]["application/json"],
  { team_size: number }
>;

/** `absent` is a head count less the present; Scramble types it `string`. */
export type TeamAttendanceDay = Omit<
  SummaryContract["data"][number],
  "absent"
> & { absent: number };

/**
 * A caller with no team gets `{ data: [] }` and nothing else. `period` echoes
 * the query string this hook sends, which Scramble cannot narrow.
 */
export type TeamAttendanceSummaryResponse =
  | (Omit<SummaryContract, "period" | "data"> & {
      period: "weekly" | "monthly";
      data: TeamAttendanceDay[];
    })
  | { data: [] };

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
