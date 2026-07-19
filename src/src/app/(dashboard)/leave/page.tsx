"use client";

import { useState } from "react";
import {
  CalendarDays,
  Plus,
  Loader2,
  Check,
  X,
  List,
  LayoutGrid,
  ChevronLeft,
  ChevronRight,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import { PageHeader } from "@/components/shared/page-header";
import { StatusBadge } from "@/components/shared/status-badge";
import { EmptyState } from "@/components/shared/empty-state";
import { Badge } from "@/components/ui/badge";
import {
  useLeaveBalance,
  useMyLeaveRequests,
  useLeaveTypes,
  useSubmitLeave,
  useTeamLeaveRequests,
  useApproveLeave,
  useRejectLeave,
  useCancelLeave,
  type LeaveBalance,
  type LeaveRequest,
} from "@/features/leave/api";
import { cn } from "@/lib/utils";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

function leaveTypeName(
  lt: LeaveBalance["leave_type"],
  unknown: string,
): string {
  if (typeof lt === "string") return lt;
  return lt?.name ?? unknown;
}

export default function LeavePage() {
  const { t } = useT();
  const [dialogOpen, setDialogOpen] = useState(false);
  const { isSupervisor } = usePermissions();
  const { data: leaveTypes } = useLeaveTypes();

  const [leaveForm, setLeaveForm] = useState({
    leave_type_public_id: "",
    start_date: "",
    end_date: "",
    reason: "",
  });
  const submitLeave = useSubmitLeave();

  function handleSubmitLeave(e: React.FormEvent) {
    e.preventDefault();
    submitLeave.mutate(leaveForm, {
      onSuccess: () => {
        toast.success(t("leave_page.submitted"));
        setDialogOpen(false);
        setLeaveForm({
          leave_type_public_id: "",
          start_date: "",
          end_date: "",
          reason: "",
        });
      },
      onError: (err: unknown) => {
        const axiosError = err as { response?: { data?: { detail?: string } } };
        toast.error(
          axiosError.response?.data?.detail || t("leave_page.submit_failed"),
        );
      },
    });
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("nav.leave")}
        description={t("leave_page.description")}
        actions={
          <Button onClick={() => setDialogOpen(true)}>
            <Plus className="mr-2 h-4 w-4" /> {t("common.apply_leave")}
          </Button>
        }
      />

      <Tabs defaultValue="my-leave">
        <TabsList>
          <TabsTrigger value="my-leave">{t("leave_page.my_leave")}</TabsTrigger>
          {isSupervisor && (
            <TabsTrigger value="team">{t("leave_page.team_leave")}</TabsTrigger>
          )}
        </TabsList>

        <TabsContent value="my-leave" className="mt-4 space-y-6">
          <MyLeaveTab />
        </TabsContent>

        {isSupervisor && (
          <TabsContent value="team" className="mt-4">
            <TeamLeaveTab />
          </TabsContent>
        )}
      </Tabs>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("common.apply_leave")}</DialogTitle>
          </DialogHeader>
          <form onSubmit={handleSubmitLeave} className="space-y-4">
            <div>
              <Label>{t("leave_page.leave_type")}</Label>
              <Select
                value={leaveForm.leave_type_public_id}
                onValueChange={(v) =>
                  setLeaveForm((p) => ({ ...p, leave_type_public_id: v }))
                }
              >
                <SelectTrigger className="mt-1">
                  <SelectValue
                    placeholder={t("leave_page.select_leave_type")}
                  />
                </SelectTrigger>
                <SelectContent>
                  {leaveTypes?.data?.map((lt) => (
                    <SelectItem key={lt.public_id} value={lt.public_id}>
                      {lt.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <Label>{t("leave_page.start_date")}</Label>
                <Input
                  type="date"
                  value={leaveForm.start_date}
                  onChange={(e) =>
                    setLeaveForm((p) => ({ ...p, start_date: e.target.value }))
                  }
                  required
                  className="mt-1"
                />
              </div>
              <div>
                <Label>{t("leave_page.end_date")}</Label>
                <Input
                  type="date"
                  value={leaveForm.end_date}
                  onChange={(e) =>
                    setLeaveForm((p) => ({ ...p, end_date: e.target.value }))
                  }
                  required
                  className="mt-1"
                />
              </div>
            </div>
            <div>
              <Label>{t("attendance.corrections.reason")}</Label>
              <Textarea
                value={leaveForm.reason}
                onChange={(e) =>
                  setLeaveForm((p) => ({ ...p, reason: e.target.value }))
                }
                placeholder={t("leave_page.optional")}
                className="mt-1"
              />
            </div>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setDialogOpen(false)}
              >
                {t("common.cancel")}
              </Button>
              <Button type="submit" disabled={submitLeave.isPending}>
                {submitLeave.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                {t("common.submit")}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}

function MyLeaveTab() {
  const { t } = useT();
  const [page, setPage] = useState(1);
  const { data: balances, isLoading: balancesLoading } = useLeaveBalance();
  const { data: requests, isLoading: requestsLoading } = useMyLeaveRequests({
    page,
  });
  const cancelLeave = useCancelLeave();

  return (
    <>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {balancesLoading
          ? Array.from({ length: 3 }).map((_, i) => (
              <Skeleton key={i} className="h-24" />
            ))
          : balances && Array.isArray(balances)
            ? balances.map((b, i) => (
                <Card key={i}>
                  <CardContent className="p-4">
                    <p className="text-sm text-muted-foreground">
                      {leaveTypeName(b.leave_type, t("leave_page.unknown"))}
                    </p>
                    <p className="mt-1 text-2xl font-bold text-foreground">
                      {b.remaining_days}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {b.used_days} {t("leave_page.used_of")} {b.entitled_days}
                    </p>
                  </CardContent>
                </Card>
              ))
            : null}
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{t("leave_page.my_requests")}</CardTitle>
        </CardHeader>
        <CardContent className="p-0">
          {requestsLoading ? (
            <div className="space-y-3 p-4">
              {Array.from({ length: 3 }).map((_, i) => (
                <Skeleton key={i} className="h-12 w-full" />
              ))}
            </div>
          ) : !requests?.data?.length ? (
            <EmptyState
              icon={CalendarDays}
              title={t("leave_page.no_requests")}
              description={t("leave_page.no_requests_desc")}
            />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b bg-muted/50">
                    <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">
                      {t("leave_page.type")}
                    </th>
                    <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">
                      {t("leave_page.dates")}
                    </th>
                    <th className="hidden px-4 py-3 text-left text-sm font-medium text-muted-foreground sm:table-cell">
                      {t("leave_page.days")}
                    </th>
                    <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">
                      {t("common.status")}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {requests.data.map((req) => (
                    <tr
                      key={req.public_id}
                      className="border-b last:border-0 hover:bg-muted/30"
                    >
                      <td className="px-4 py-3 text-sm font-medium text-foreground">
                        {leaveTypeName(req.leave_type, t("leave_page.unknown"))}
                      </td>
                      <td className="px-4 py-3 text-sm text-muted-foreground">
                        {req.start_date} — {req.end_date}
                      </td>
                      <td className="hidden px-4 py-3 text-sm text-muted-foreground sm:table-cell">
                        {req.days}
                      </td>
                      <td className="px-4 py-3">
                        <StatusBadge status={req.status} />
                      </td>
                      <td className="px-4 py-3 text-right">
                        {req.status === "pending" && (
                          <Button
                            size="sm"
                            variant="ghost"
                            className="h-7 text-muted-foreground hover:text-red-600"
                            onClick={() => {
                              if (confirm(t("leave_page.withdraw_confirm"))) {
                                cancelLeave.mutate(req.public_id, {
                                  onSuccess: () =>
                                    toast.success(t("leave_page.withdrawn")),
                                  onError: () =>
                                    toast.error(
                                      t("leave_page.withdraw_failed"),
                                    ),
                                });
                              }
                            }}
                            disabled={cancelLeave.isPending}
                            aria-label={t("leave_page.withdraw")}
                          >
                            <X className="h-3 w-3" />
                          </Button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </>
  );
}

function TeamLeaveTab() {
  const { t } = useT();
  const [page, setPage] = useState(1);
  const [view, setView] = useState<"list" | "calendar">("list");
  const { data, isLoading } = useTeamLeaveRequests({ page, per_page: 100 } as {
    page: number;
  });
  const approveLeave = useApproveLeave();
  const rejectLeave = useRejectLeave();

  const requests = data?.data ?? [];

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-medium text-muted-foreground">
          {requests.length}{" "}
          {requests.length !== 1
            ? t("leave_page.requests_plural")
            : t("leave_page.requests_singular")}
        </h3>
        <div className="flex rounded-lg border p-0.5">
          <Button
            variant={view === "list" ? "secondary" : "ghost"}
            size="sm"
            className="h-7 px-2"
            onClick={() => setView("list")}
          >
            <List className="h-3 w-3" />
          </Button>
          <Button
            variant={view === "calendar" ? "secondary" : "ghost"}
            size="sm"
            className="h-7 px-2"
            onClick={() => setView("calendar")}
          >
            <LayoutGrid className="h-3 w-3" />
          </Button>
        </div>
      </div>

      {view === "calendar" ? (
        <TeamLeaveCalendar
          requests={requests as (LeaveRequest & { employee_name?: string })[]}
        />
      ) : (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              {t("leave_page.team_requests")}
            </CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            {isLoading ? (
              <div className="space-y-3 p-4">
                {Array.from({ length: 3 }).map((_, i) => (
                  <Skeleton key={i} className="h-12 w-full" />
                ))}
              </div>
            ) : requests.length === 0 ? (
              <EmptyState
                icon={CalendarDays}
                title={t("leave_page.no_team_requests")}
                description={t("leave_page.no_team_requests_desc")}
              />
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="border-b bg-muted/50">
                      <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">
                        {t("attendance.employee")}
                      </th>
                      <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">
                        {t("leave_page.type")}
                      </th>
                      <th className="hidden px-4 py-3 text-left text-sm font-medium text-muted-foreground sm:table-cell">
                        {t("leave_page.dates")}
                      </th>
                      <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">
                        {t("common.status")}
                      </th>
                      <th className="px-4 py-3 text-right text-sm font-medium text-muted-foreground">
                        {t("common.actions")}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {requests.map((req) => (
                      <tr
                        key={req.public_id}
                        className="border-b last:border-0 hover:bg-muted/30"
                      >
                        <td className="px-4 py-3 text-sm font-medium text-foreground">
                          {(req as { employee_name?: string }).employee_name ??
                            "—"}
                        </td>
                        <td className="px-4 py-3 text-sm text-muted-foreground">
                          {leaveTypeName(
                            req.leave_type,
                            t("leave_page.unknown"),
                          )}
                        </td>
                        <td className="hidden px-4 py-3 text-sm text-muted-foreground sm:table-cell">
                          {req.start_date} — {req.end_date}
                        </td>
                        <td className="px-4 py-3">
                          <StatusBadge status={req.status} />
                        </td>
                        <td className="px-4 py-3 text-right">
                          {req.status === "pending" && (
                            <div className="flex justify-end gap-1">
                              <Button
                                size="sm"
                                variant="ghost"
                                className="h-7 text-green-600"
                                onClick={() =>
                                  approveLeave.mutate(req.public_id)
                                }
                                disabled={approveLeave.isPending}
                                aria-label={t("common.approve")}
                              >
                                <Check className="h-3 w-3" />
                              </Button>
                              <Button
                                size="sm"
                                variant="ghost"
                                className="h-7 text-red-600"
                                onClick={() =>
                                  rejectLeave.mutate({
                                    publicId: req.public_id,
                                    reason: "Rejected",
                                  })
                                }
                                disabled={rejectLeave.isPending}
                                aria-label={t("common.reject")}
                              >
                                <X className="h-3 w-3" />
                              </Button>
                            </div>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  );
}

const WEEKDAYS = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];

const STATUS_COLORS: Record<string, string> = {
  approved:
    "bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300",
  pending:
    "bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300",
  rejected: "bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300",
};

function TeamLeaveCalendar({
  requests,
}: {
  requests: (LeaveRequest & { employee_name?: string })[];
}) {
  const { t } = useT();
  const [month, setMonth] = useState(() => {
    const now = new Date();
    return { year: now.getFullYear(), month: now.getMonth() };
  });

  const firstDay = new Date(month.year, month.month, 1);
  const lastDay = new Date(month.year, month.month + 1, 0);
  const startOffset = (firstDay.getDay() + 6) % 7; // Monday-based
  const daysInMonth = lastDay.getDate();
  const today = new Date();
  const todayStr = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, "0")}-${String(today.getDate()).padStart(2, "0")}`;

  function dateStr(day: number): string {
    return `${month.year}-${String(month.month + 1).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
  }

  function leavesOnDay(day: number) {
    const d = dateStr(day);
    return requests.filter((r) => {
      const status = r.status;
      if (status === "rejected") return false;
      return r.start_date <= d && r.end_date >= d;
    });
  }

  function prevMonth() {
    setMonth((m) =>
      m.month === 0
        ? { year: m.year - 1, month: 11 }
        : { year: m.year, month: m.month - 1 },
    );
  }

  function nextMonth() {
    setMonth((m) =>
      m.month === 11
        ? { year: m.year + 1, month: 0 }
        : { year: m.year, month: m.month + 1 },
    );
  }

  const monthLabel = firstDay.toLocaleDateString("en-US", {
    month: "long",
    year: "numeric",
  });

  const cells: (number | null)[] = [];
  for (let i = 0; i < startOffset; i++) cells.push(null);
  for (let d = 1; d <= daysInMonth; d++) cells.push(d);
  while (cells.length % 7 !== 0) cells.push(null);

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between space-y-0 pb-4">
        <CardTitle className="text-base">{monthLabel}</CardTitle>
        <div className="flex items-center gap-1">
          <Button
            variant="outline"
            size="icon"
            className="h-7 w-7"
            onClick={prevMonth}
          >
            <ChevronLeft className="h-4 w-4" />
          </Button>
          <Button
            variant="outline"
            size="icon"
            className="h-7 w-7"
            onClick={nextMonth}
          >
            <ChevronRight className="h-4 w-4" />
          </Button>
        </div>
      </CardHeader>
      <CardContent className="p-0 pb-4 px-4">
        <div className="grid grid-cols-7 gap-px rounded-lg border bg-muted/50 overflow-hidden">
          {WEEKDAYS.map((d) => (
            <div
              key={d}
              className="bg-muted px-1 py-2 text-center text-xs font-medium text-muted-foreground"
            >
              {d}
            </div>
          ))}
          {cells.map((day, i) => {
            if (day === null) {
              return (
                <div
                  key={`empty-${i}`}
                  className="min-h-[4.5rem] bg-background"
                />
              );
            }
            const leaves = leavesOnDay(day);
            const isToday = dateStr(day) === todayStr;
            const isWeekend = i % 7 >= 5;

            return (
              <div
                key={day}
                className={cn(
                  "min-h-[4.5rem] p-1 bg-background",
                  isWeekend && "bg-muted/30",
                )}
              >
                <span
                  className={cn(
                    "inline-flex h-5 w-5 items-center justify-center rounded-full text-xs",
                    isToday && "bg-primary text-primary-foreground font-bold",
                  )}
                >
                  {day}
                </span>
                <div className="mt-0.5 space-y-0.5">
                  {leaves.slice(0, 3).map((leave, j) => {
                    const name = leave.employee_name ?? "?";
                    const firstName = name.split(" ")[0];
                    return (
                      <Badge
                        key={j}
                        variant="outline"
                        className={cn(
                          "block truncate px-1 py-0 text-[10px] leading-4 border-0 font-normal",
                          STATUS_COLORS[leave.status] ?? STATUS_COLORS.pending,
                        )}
                        title={`${name} — ${leaveTypeName(leave.leave_type, t("leave_page.unknown"))} (${leave.status})`}
                      >
                        {firstName}
                      </Badge>
                    );
                  })}
                  {leaves.length > 3 && (
                    <span className="block text-[10px] text-muted-foreground px-1">
                      +{leaves.length - 3} {t("leave_page.more")}
                    </span>
                  )}
                </div>
              </div>
            );
          })}
        </div>
        <div className="mt-3 flex flex-wrap gap-3 text-xs">
          <span className="flex items-center gap-1">
            <span className="inline-block h-2.5 w-2.5 rounded-sm bg-green-200 dark:bg-green-900" />{" "}
            {t("leave_page.approved")}
          </span>
          <span className="flex items-center gap-1">
            <span className="inline-block h-2.5 w-2.5 rounded-sm bg-amber-200 dark:bg-amber-900" />{" "}
            {t("leave_page.pending")}
          </span>
        </div>
      </CardContent>
    </Card>
  );
}
