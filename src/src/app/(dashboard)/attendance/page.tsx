"use client";

import { useState } from "react";
import Link from "next/link";
import {
  Clock,
  LogIn,
  LogOut,
  Plus,
  Loader2,
  QrCode,
  Smartphone,
  Activity,
  TrendingUp,
  FilePenLine,
  UsersRound,
  FileSpreadsheet,
  Filter,
  X,
  Monitor,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { PageHeader } from "@/components/shared/page-header";
import { StatusBadge } from "@/components/shared/status-badge";
import { EmptyState } from "@/components/shared/empty-state";
import {
  useAttendanceList,
  useCheckIn,
  useCheckOut,
  type AttendanceFilters,
} from "@/features/attendance/api";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { toast } from "sonner";

export default function AttendancePage() {
  const [page, setPage] = useState(1);
  const [sourceFilter, setSourceFilter] = useState<string>("all");
  const [statusFilter, setStatusFilter] = useState<string>("all");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [showFilters, setShowFilters] = useState(false);
  const [manualOpen, setManualOpen] = useState(false);
  const checkIn = useCheckIn();
  const checkOut = useCheckOut();
  const { can, isSupervisor } = usePermissions();

  const queryParams: AttendanceFilters = { page, per_page: 25 };
  if (sourceFilter !== "all") queryParams["filter[source]"] = sourceFilter;
  if (statusFilter !== "all") queryParams["filter[status]"] = statusFilter;
  if (dateFrom) queryParams["filter[date_from]"] = dateFrom;
  if (dateTo) queryParams["filter[date_to]"] = dateTo;

  const { data, isLoading } = useAttendanceList(queryParams);
  const records = data?.data ?? [];

  const hasActiveFilters =
    sourceFilter !== "all" || statusFilter !== "all" || dateFrom || dateTo;

  function clearFilters() {
    setSourceFilter("all");
    setStatusFilter("all");
    setDateFrom("");
    setDateTo("");
    setPage(1);
  }

  function handleCheckIn() {
    checkIn.mutate(
      { idempotency_key: crypto.randomUUID(), source: "web" },
      {
        onSuccess: () => toast.success("Checked in successfully"),
        onError: () => toast.error("Failed to check in"),
      },
    );
  }

  function handleCheckOut() {
    checkOut.mutate(
      { idempotency_key: crypto.randomUUID() },
      {
        onSuccess: () => toast.success("Checked out successfully"),
        onError: () => toast.error("Failed to check out"),
      },
    );
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Attendance"
        description="Track and manage attendance records"
        actions={
          <div className="flex flex-wrap gap-2">
            <Button onClick={handleCheckIn} disabled={checkIn.isPending}>
              <LogIn className="mr-2 h-4 w-4" /> Check In
            </Button>
            <Button
              variant="outline"
              onClick={handleCheckOut}
              disabled={checkOut.isPending}
            >
              <LogOut className="mr-2 h-4 w-4" /> Check Out
            </Button>
            {can.manageEmployees && (
              <Button variant="outline" onClick={() => setManualOpen(true)}>
                <Plus className="mr-2 h-4 w-4" /> Manual Entry
              </Button>
            )}
          </div>
        }
      />

      {/* Sub-nav */}
      <div className="flex flex-wrap gap-2 border-b pb-3">
        <SubNav href="/attendance/scan" icon={QrCode}>
          Scan QR
        </SubNav>
        <SubNav href="/attendance/mobile" icon={Smartphone}>
          Mobile Check-in
        </SubNav>
        {isSupervisor && (
          <SubNav href="/attendance/team" icon={UsersRound}>
            Team
          </SubNav>
        )}
        <SubNav href="/attendance/corrections" icon={FilePenLine}>
          Corrections
        </SubNav>
        {can.manageEmployees && (
          <SubNav href="/kiosk" icon={Monitor}>
            Kiosk
          </SubNav>
        )}
        {can.manageEmployees && (
          <SubNav href="/attendance/qr" icon={QrCode}>
            QR Generator
          </SubNav>
        )}
        {can.manageEmployees && (
          <SubNav href="/attendance/import" icon={FileSpreadsheet}>
            Import CSV
          </SubNav>
        )}
        {can.manageEmployees && (
          <SubNav href="/attendance/intelligence" icon={Activity}>
            Intelligence
          </SubNav>
        )}
        {can.manageEmployees && (
          <SubNav href="/attendance/overtime" icon={TrendingUp}>
            Overtime
          </SubNav>
        )}
      </div>

      {/* Filters */}
      <div className="space-y-3">
        <div className="flex items-center gap-2">
          <Button
            variant={showFilters ? "secondary" : "outline"}
            size="sm"
            onClick={() => setShowFilters(!showFilters)}
          >
            <Filter className="mr-2 h-3 w-3" /> Filters
            {hasActiveFilters && (
              <span className="ml-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-primary text-[10px] text-primary-foreground">
                !
              </span>
            )}
          </Button>
          {hasActiveFilters && (
            <Button
              variant="ghost"
              size="sm"
              onClick={clearFilters}
              className="text-xs text-muted-foreground"
            >
              <X className="mr-1 h-3 w-3" /> Clear filters
            </Button>
          )}
        </div>

        {showFilters && (
          <Card>
            <CardContent className="p-4">
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                  <Label className="text-xs">Date From</Label>
                  <Input
                    type="date"
                    value={dateFrom}
                    onChange={(e) => {
                      setDateFrom(e.target.value);
                      setPage(1);
                    }}
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label className="text-xs">Date To</Label>
                  <Input
                    type="date"
                    value={dateTo}
                    onChange={(e) => {
                      setDateTo(e.target.value);
                      setPage(1);
                    }}
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label className="text-xs">Source</Label>
                  <Select
                    value={sourceFilter}
                    onValueChange={(v) => {
                      setSourceFilter(v);
                      setPage(1);
                    }}
                  >
                    <SelectTrigger className="mt-1">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">All sources</SelectItem>
                      <SelectItem value="web">Web</SelectItem>
                      <SelectItem value="mobile">Mobile</SelectItem>
                      <SelectItem value="biometric">Biometric</SelectItem>
                      <SelectItem value="qr">QR</SelectItem>
                      <SelectItem value="kiosk">Kiosk</SelectItem>
                      <SelectItem value="manual">Manual</SelectItem>
                      <SelectItem value="csv">CSV Import</SelectItem>
                      <SelectItem value="offline_mobile">Offline</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div>
                  <Label className="text-xs">Status</Label>
                  <Select
                    value={statusFilter}
                    onValueChange={(v) => {
                      setStatusFilter(v);
                      setPage(1);
                    }}
                  >
                    <SelectTrigger className="mt-1">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">All statuses</SelectItem>
                      <SelectItem value="present">Present</SelectItem>
                      <SelectItem value="late">Late</SelectItem>
                      <SelectItem value="absent">Absent</SelectItem>
                      <SelectItem value="early_leave">Early Leave</SelectItem>
                      <SelectItem value="on_leave">On Leave</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>
            </CardContent>
          </Card>
        )}
      </div>

      {isLoading ? (
        <div className="space-y-3">
          {Array.from({ length: 5 }).map((_, i) => (
            <Skeleton key={i} className="h-14 w-full" />
          ))}
        </div>
      ) : records.length === 0 ? (
        <EmptyState
          icon={Clock}
          title={
            hasActiveFilters
              ? "No records match filters"
              : "No attendance records"
          }
          description={
            hasActiveFilters
              ? "Try adjusting the filters or clearing them"
              : "Check in to start recording your attendance"
          }
        />
      ) : (
        <>
          <Card>
            <CardContent className="p-0">
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="border-b bg-muted/50">
                      {can.manageEmployees && (
                        <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">
                          Employee
                        </th>
                      )}
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">
                        Date
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">
                        In
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">
                        Out
                      </th>
                      <th className="hidden px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground sm:table-cell">
                        Source
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">
                        Status
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {records.map((record) => (
                      <tr
                        key={record.public_id}
                        className="border-b last:border-0 hover:bg-muted/30"
                      >
                        {can.manageEmployees && (
                          <td className="px-4 py-3 text-sm text-foreground">
                            {record.employee_name ?? "—"}
                          </td>
                        )}
                        <td className="px-4 py-3 text-sm font-medium text-foreground">
                          {record.date}
                        </td>
                        <td className="px-4 py-3 text-sm text-muted-foreground">
                          {record.check_in ?? "—"}
                        </td>
                        <td className="px-4 py-3 text-sm text-muted-foreground">
                          {record.check_out ?? "—"}
                        </td>
                        <td className="hidden px-4 py-3 text-sm capitalize text-muted-foreground sm:table-cell">
                          {record.source}
                        </td>
                        <td className="px-4 py-3">
                          <StatusBadge status={record.status} />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>

          {data?.meta && data.meta.last_page > 1 && (
            <div className="flex items-center justify-between">
              <p className="text-sm text-muted-foreground">
                Showing {data.meta.from}–{data.meta.to} of {data.meta.total}
              </p>
              <div className="flex gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={page <= 1}
                  onClick={() => setPage(page - 1)}
                >
                  Previous
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={page >= data.meta.last_page}
                  onClick={() => setPage(page + 1)}
                >
                  Next
                </Button>
              </div>
            </div>
          )}
        </>
      )}

      <ManualEntryDialog
        open={manualOpen}
        onClose={() => setManualOpen(false)}
      />
    </div>
  );
}

function SubNav({
  href,
  icon: Icon,
  children,
}: {
  href: string;
  icon: React.ComponentType<{ className?: string }>;
  children: React.ReactNode;
}) {
  return (
    <Button asChild variant="ghost" size="sm" className="h-8">
      <Link href={href}>
        <Icon className="mr-2 h-3 w-3" /> {children}
      </Link>
    </Button>
  );
}

function ManualEntryDialog({
  open,
  onClose,
}: {
  open: boolean;
  onClose: () => void;
}) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({
    employee_public_id: "",
    date: new Date().toISOString().split("T")[0],
    check_in: "09:00",
    check_out: "17:00",
    reason: "",
  });

  const { data: employees } = useQuery({
    queryKey: ["employees", "lookup"],
    queryFn: async () =>
      (await apiClient.get("/employees", { params: { per_page: 100 } })).data,
    enabled: open,
  });

  const submit = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post("/attendance/manual", {
        ...form,
        idempotency_key: `manual-${Date.now()}-${Math.random()}`,
      });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["attendance"] });
      toast.success("Manual entry recorded");
      onClose();
      setForm({
        employee_public_id: "",
        date: new Date().toISOString().split("T")[0],
        check_in: "09:00",
        check_out: "17:00",
        reason: "",
      });
    },
    onError: (err: unknown) => {
      const axiosErr = err as { response?: { data?: { detail?: string } } };
      toast.error(axiosErr.response?.data?.detail ?? "Manual entry failed");
    },
  });

  return (
    <Dialog open={open} onOpenChange={onClose}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Manual Attendance Entry</DialogTitle>
        </DialogHeader>
        <form
          onSubmit={(e) => {
            e.preventDefault();
            submit.mutate();
          }}
          className="space-y-3"
        >
          <div>
            <Label>Employee *</Label>
            <Select
              value={form.employee_public_id}
              onValueChange={(v) =>
                setForm((p) => ({ ...p, employee_public_id: v }))
              }
            >
              <SelectTrigger className="mt-1">
                <SelectValue placeholder="Select employee" />
              </SelectTrigger>
              <SelectContent>
                {employees?.data?.map(
                  (e: {
                    public_id: string;
                    name: string;
                    employee_code: string;
                  }) => (
                    <SelectItem key={e.public_id} value={e.public_id}>
                      {e.name}{" "}
                      {e.employee_code && (
                        <span className="text-muted-foreground">
                          ({e.employee_code})
                        </span>
                      )}
                    </SelectItem>
                  ),
                )}
              </SelectContent>
            </Select>
          </div>
          <div className="grid grid-cols-3 gap-3">
            <div>
              <Label>Date *</Label>
              <Input
                type="date"
                value={form.date}
                onChange={(e) =>
                  setForm((p) => ({ ...p, date: e.target.value }))
                }
                required
                className="mt-1"
              />
            </div>
            <div>
              <Label>Check In *</Label>
              <Input
                type="time"
                value={form.check_in}
                onChange={(e) =>
                  setForm((p) => ({ ...p, check_in: e.target.value }))
                }
                required
                className="mt-1"
              />
            </div>
            <div>
              <Label>Check Out</Label>
              <Input
                type="time"
                value={form.check_out}
                onChange={(e) =>
                  setForm((p) => ({ ...p, check_out: e.target.value }))
                }
                className="mt-1"
              />
            </div>
          </div>
          <div>
            <Label>Reason *</Label>
            <Textarea
              value={form.reason}
              onChange={(e) =>
                setForm((p) => ({ ...p, reason: e.target.value }))
              }
              placeholder="Why is this manual entry needed?"
              required
              rows={2}
              className="mt-1"
            />
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              Cancel
            </Button>
            <Button
              type="submit"
              disabled={submit.isPending || !form.employee_public_id}
            >
              {submit.isPending && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              Record Entry
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
