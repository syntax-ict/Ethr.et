"use client";

import { Skeleton } from "@/components/ui/skeleton";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import {
  BarChart,
  Bar,
  LineChart,
  Line,
  PieChart,
  Pie,
  Cell,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  Legend,
} from "recharts";
import { CHART_COLORS, ChartCard } from "./chart-helpers";

export function WorkforceTab() {
  const { t } = useT();
  const { data, isLoading } = useQuery({
    queryKey: ["analytics", "workforce"],
    queryFn: async () => {
      const { data } = await apiClient.get("/dashboard/executive/workforce");
      return data;
    },
  });

  if (isLoading || !data)
    return (
      <div className="space-y-6">
        <Skeleton className="h-80 w-full" />
      </div>
    );

  return (
    <div className="space-y-6">
      {data.headcount_trend && data.headcount_trend.length > 0 && (
        <ChartCard title={t("analytics_page.headcount_trend")}>
          <ResponsiveContainer width="100%" height={300}>
            <LineChart data={data.headcount_trend}>
              <CartesianGrid strokeDasharray="3 3" className="opacity-30" />
              <XAxis dataKey="month" tick={{ fontSize: 11 }} />
              <YAxis tick={{ fontSize: 11 }} />
              <Tooltip />
              <Line
                type="monotone"
                dataKey="count"
                stroke={CHART_COLORS[0]}
                strokeWidth={2}
                dot={{ r: 4 }}
              />
            </LineChart>
          </ResponsiveContainer>
        </ChartCard>
      )}

      <div className="grid gap-6 lg:grid-cols-2">
        {data.by_gender && data.by_gender.length > 0 && (
          <ChartCard title={t("analytics_page.gender_distribution")}>
            <ResponsiveContainer width="100%" height={260}>
              <PieChart>
                <Pie
                  data={data.by_gender}
                  dataKey="count"
                  nameKey="gender"
                  label
                  outerRadius={80}
                >
                  {data.by_gender.map((_: unknown, i: number) => (
                    <Cell
                      key={i}
                      fill={CHART_COLORS[i % CHART_COLORS.length]}
                    />
                  ))}
                </Pie>
                <Tooltip />
                <Legend />
              </PieChart>
            </ResponsiveContainer>
          </ChartCard>
        )}

        {data.by_tenure && data.by_tenure.length > 0 && (
          <ChartCard title={t("analytics_page.tenure_distribution")}>
            <ResponsiveContainer width="100%" height={260}>
              <BarChart data={data.by_tenure}>
                <CartesianGrid strokeDasharray="3 3" className="opacity-30" />
                <XAxis dataKey="bucket" tick={{ fontSize: 11 }} />
                <YAxis tick={{ fontSize: 11 }} />
                <Tooltip />
                <Bar
                  dataKey="count"
                  fill={CHART_COLORS[1]}
                  radius={[6, 6, 0, 0]}
                />
              </BarChart>
            </ResponsiveContainer>
          </ChartCard>
        )}
      </div>
    </div>
  );
}
