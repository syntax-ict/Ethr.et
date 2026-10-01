import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";

export interface TeamAttendanceSummaryResponse {
  period: string;
  from: string;
  to: string;
  team_size: number;
  data: Array<{
    date: string;
    present: number;
    absent: number;
    late: number;
    rate: number;
  }>;
}

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
