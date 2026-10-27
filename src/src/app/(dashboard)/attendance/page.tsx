"use client";

import { SetupStatusPanel } from "@/features/attendance/components/setup-status-panel";
import { punchSignals } from "@/lib/attendance/punch-signals";
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
  Camera,
  FilePenLine,
  UsersRound,
  FileSpreadsheet,
  Filter,
  X,
  Monitor,
  GitMerge,
  AlertTriangle,
  Route,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Input } from "@/components/ui/input";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
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
import { PageActions } from "@/components/shared/page-actions";
import { EmployeeSelect } from "@/components/shared/employee-select";
import { StatusBadge } from "@/components/shared/status-badge";
import { EmptyState } from "@/components/shared/empty-state";
import { SimpleTable } from "@/components/shared/simple-table";
import {
  usePunchSigner,
  useAttendanceDevice,
  useAttendanceList,
  useCheckIn,
  useCheckOut,
  useManualAttendance,
  useMyAttendance,
  type AttendanceFilters,
  type MyAttendanceFilters,
} from "@/features/attendance/api";
import { useCurrentPermissions } from "@/features/auth/api";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useCalendar } from "@/lib/calendar/calendar-context";
import { dateFromIso } from "@/lib/calendar/date-from-iso";
import { toast } from "sonner";
import { apiErrorDetail, apiErrorMessage } from "@/lib/api/error-message";
import { PaginationControls } from "@/components/shared/pagination-controls";
import { useQueryFilters } from "@/lib/hooks/useQueryFilters";
import { MyExceptionsCard } from "@/features/attendance/components/my-exceptions-card";

// The values the address may carry; anything else falls back to "all".
const SOURCES = [
  "all",
  "web",
  "mobile",
  "biometric",
  "qr",
  "kiosk",
  "manual",
  "csv",
  "offline_mobile",
] as const;
const STATUSES = [
  "all",
  "present",
  "late",
  "absent",
  "early_leave",
  "on_leave",
] as const;

export default function AttendancePage() {
  const { formatTime } = useDateFormatters();
  const { t, locale } = useT();
  const { formatDate } = useCalendar();
  const [page, setPage] = useState(1);
  // In the address, so "who is absent today" can be opened from the
  // command palette or sent as a link, and survives a reload.
  const filters = useQueryFilters(
    { source: "all", status: "all", from: "", to: "" },
    { source: SOURCES, status: STATUSES },
  );
  const {
    source: sourceFilter,
    status: statusFilter,
    from: dateFrom,
    to: dateTo,
  } = filters.values;
  const [showFilters, setShowFilters] = useState(false);
  const [manualOpen, setManualOpen] = useState(false);
  const checkIn = useCheckIn();
  const checkOut = useCheckOut();
  // The registered phone signs a web punch too: on a phone directly, on a
  // computer through the phone's prompt.
  const { data: deviceState } = useAttendanceDevice();
  const signPunch = usePunchSigner(deviceState);
  const { can } = usePermissions();
  const { data: granted } = useCurrentPermissions();

  // `GET /attendance` requires attendance.viewAll. Everyone else — every
  // employee, every supervisor — was sent there anyway, got a 403, and saw
  // "No attendance records — check in to start" directly under the Check In
  // button they had just pressed. They read their own records instead. Wait for
  // the permission list so an HR admin does not fire the wrong request first.
  const viewAll = can.viewAllAttendance;
  const permissionsKnown = granted !== undefined;

  const queryParams: AttendanceFilters = { page, per_page: 25 };
  if (sourceFilter !== "all") queryParams["filter[source]"] = sourceFilter;
  if (statusFilter !== "all") queryParams["filter[status]"] = statusFilter;
  if (dateFrom) queryParams["filter[date_from]"] = dateFrom;
  if (dateTo) queryParams["filter[date_to]"] = dateTo;

  const myParams: MyAttendanceFilters = { page, per_page: 25 };
  if (dateFrom) myParams.date_from = dateFrom;
  if (dateTo) myParams.date_to = dateTo;

  const allQuery = useAttendanceList(queryParams, {
    enabled: permissionsKnown && viewAll,
  });
  const myQuery = useMyAttendance(myParams, {
    enabled: permissionsKnown && !viewAll,
  });
  const query = viewAll ? allQuery : myQuery;
  const { data, isPending, isError, refetch } = query;
  const records = data?.data ?? [];

  // `/attendance/my` reads only the date bounds; source and status would be
  // silently ignored there, so they are offered only on the full list.
  const hasActiveFilters =
    (viewAll && (sourceFilter !== "all" || statusFilter !== "all")) ||
    dateFrom ||
    dateTo;

  function clearFilters() {
    filters.clear();
    setPage(1);
  }

  async function signedOrStop() {
    const signed = await signPunch();
    if (signed.stop) {
      toast.error(
        t(
          "device.sign_cancelled",
          "Unlock your phone when it asks, to confirm it is you.",
        ),
      );
    }
    return signed;
  }

  async function handleCheckIn() {
    const signed = await signedOrStop();
    if (signed.stop) return;
    checkIn.mutate(
      {
        idempotency_key: crypto.randomUUID(),
        source: "web",
        ...punchSignals(null),
        ...(signed.proof ? { device_proof: signed.proof } : {}),
      },
      {
        onSuccess: () =>
          toast.success(t("attendance.checked_in", "Checked in successfully")),
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t("attendance.check_in_failed", "Failed to check in"),
            ),
          ),
      },
    );
  }

  async function handleCheckOut() {
    const signed = await signedOrStop();
    if (signed.stop) return;
    checkOut.mutate(
      {
        idempotency_key: crypto.randomUUID(),
        ...punchSignals(null),
        ...(signed.proof ? { device_proof: signed.proof } : {}),
      },
      {
        onSuccess: () =>
          toast.success(
            t("attendance.checked_out", "Checked out successfully"),
          ),
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t("attendance.check_out_failed", "Failed to check out"),
            ),
          ),
      },
    );
  }

  return (
    <div className="space-y-6">
      <PageActions
        actions={
          <div className="flex flex-wrap gap-2">
            <Button onClick={handleCheckIn} disabled={checkIn.isPending}>
              <LogIn className="mr-2 h-4 w-4" />{" "}
              {t("common.check_in", "Check In")}
            </Button>
            <Button
              variant="outline"
              onClick={handleCheckOut}
              disabled={checkOut.isPending}
            >
              <LogOut className="mr-2 h-4 w-4" />{" "}
              {t("common.check_out", "Check Out")}
            </Button>
            {/* ManualAttendanceController checks attendance.manage, not
                employee.create; the two only coincide on the built-in roles. */}
            {can.manageAttendance && (
              <Button variant="outline" onClick={() => setManualOpen(true)}>
                <Plus className="mr-2 h-4 w-4" />{" "}
                {t("attendance.manual_entry", "Manual Entry")}
              </Button>
            )}
          </div>
        }
      />

      <MyExceptionsCard />

      {/* Sub-nav — each link gated on the ability its page's API checks.
          Grouped by what the person is there to do: twelve links in one
          wrapping row read as a scatter of buttons, not as navigation. */}
      <nav
        aria-label={t("attendance.sub_nav", "Attendance pages")}
        className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-4 gap-y-1 rounded-xl border border-border bg-card px-4 py-3 shadow-xs"
      >
        <SubNavGroup label={t("attendance.group_check_in", "Check in")}>
          <SubNav href="/attendance/scan" icon={QrCode}>
            {t("attendance.scan_qr", "Scan QR")}
          </SubNav>
          <SubNav href="/attendance/mobile" icon={Smartphone}>
            {t("attendance.mobile_checkin", "Mobile Check-in")}
          </SubNav>
        </SubNavGroup>
        <SubNavGroup label={t("attendance.group_review", "Review")}>
          {can.viewTeam && (
            <SubNav href="/attendance/team" icon={UsersRound}>
              {t("attendance.team", "Team")}
            </SubNav>
          )}
          <SubNav href="/attendance/corrections" icon={FilePenLine}>
            {t("nav.corrections", "Corrections")}
          </SubNav>
          {can.viewAttendanceConflicts && (
            <SubNav href="/attendance/conflicts" icon={GitMerge}>
              {t("nav.conflicts", "Conflicts")}
            </SubNav>
          )}
          {can.viewAllAttendance && (
            <SubNav href="/attendance/intelligence" icon={Activity}>
              {t("attendance.intelligence", "Intelligence")}
            </SubNav>
          )}
          {can.viewAllAttendance && (
            <SubNav href="/attendance/overtime" icon={TrendingUp}>
              {t("attendance.overtime", "Overtime")}
            </SubNav>
          )}
          {can.viewAllAttendance && (
            <SubNav href="/attendance/visits" icon={Route}>
              {t("visits.title", "Visits")}
            </SubNav>
          )}
          {can.viewAttendancePhotos && (
            <SubNav href="/attendance/photos" icon={Camera}>
              {t("nav.attendance_photos", "Photos")}
            </SubNav>
          )}
        </SubNavGroup>
        {/* The kiosk management page — where a kiosk is registered and its
            token issued. This linked to /kiosk, the terminal itself, whose
            setup screen asks for that token; nothing linked here at all. */}
        {can.manageAttendance && (
          <SubNavGroup label={t("attendance.group_manage", "Manage")}>
            <SubNav href="/attendance/kiosks" icon={Monitor}>
              {t("nav.kiosks", "Kiosks")}
            </SubNav>
            <SubNav href="/attendance/qr" icon={QrCode}>
              {t("attendance.qr_generator", "QR Generator")}
            </SubNav>
            <SubNav href="/attendance/import" icon={FileSpreadsheet}>
              {t("attendance.import_csv", "Import CSV")}
            </SubNav>
          </SubNavGroup>
        )}
      </nav>

      {can.manageAttendance && <SetupStatusPanel compact />}

      {/* Filters */}
      <div className="space-y-3">
        <div className="flex items-center gap-2">
          <Button
            variant={
              showFilters || filters.activeCount > 0 ? "secondary" : "outline"
            }
            size="sm"
            onClick={() => setShowFilters(!showFilters)}
          >
            <Filter className="mr-2 h-3 w-3" />{" "}
            {t("attendance.filters", "Filters")}
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
              <X className="mr-1 h-3 w-3" />{" "}
              {t("attendance.clear_filters", "Clear filters")}
            </Button>
          )}
        </div>

        {(showFilters || filters.activeCount > 0) && (
          <Card>
            <CardContent className="p-4">
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                  <Label className="text-xs">
                    {t("attendance.date_from", "Date From")}
                  </Label>
                  <DualCalendarDateInput
                    value={dateFrom}
                    onChange={(v) => {
                      filters.set({ from: v });
                      setPage(1);
                    }}
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label className="text-xs">
                    {t("attendance.date_to", "Date To")}
                  </Label>
                  <DualCalendarDateInput
                    value={dateTo}
                    onChange={(v) => {
                      filters.set({ to: v });
                      setPage(1);
                    }}
                    className="mt-1"
                  />
                </div>
                {viewAll && (
                  <div>
                    <Label className="text-xs">
                      {t("attendance.source", "Source")}
                    </Label>
                    <Select
                      value={sourceFilter}
                      onValueChange={(v) => {
                        filters.set({ source: v });
                        setPage(1);
                      }}
                    >
                      <SelectTrigger className="mt-1">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="all">
                          {t("attendance.all_sources", "All sources")}
                        </SelectItem>
                        <SelectItem value="web">
                          {t("attendance.source_web", "Web")}
                        </SelectItem>
                        <SelectItem value="mobile">
                          {t("attendance.source_mobile", "Mobile")}
                        </SelectItem>
                        <SelectItem value="biometric">
                          {t("attendance.source_biometric", "Biometric")}
                        </SelectItem>
                        <SelectItem value="qr">
                          {t("attendance.source_qr", "QR")}
                        </SelectItem>
                        <SelectItem value="kiosk">
                          {t("attendance.source_kiosk", "Kiosk")}
                        </SelectItem>
                        <SelectItem value="manual">
                          {t("attendance.source_manual", "Manual")}
                        </SelectItem>
                        <SelectItem value="csv">
                          {t("attendance.source_csv", "CSV Import")}
                        </SelectItem>
                        <SelectItem value="offline_mobile">
                          {t("attendance.source_offline", "Offline")}
                        </SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                )}
                {viewAll && (
                  <div>
                    <Label className="text-xs">
                      {t("common.status", "Status")}
                    </Label>
                    <Select
                      value={statusFilter}
                      onValueChange={(v) => {
                        filters.set({ status: v });
                        setPage(1);
                      }}
                    >
                      <SelectTrigger className="mt-1">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="all">
                          {t("attendance.all_statuses", "All statuses")}
                        </SelectItem>
                        <SelectItem value="present">
                          {t("attendance.status_present", "Present")}
                        </SelectItem>
                        <SelectItem value="late">
                          {t("attendance.status_late", "Late")}
                        </SelectItem>
                        <SelectItem value="absent">
                          {t("attendance.status_absent", "Absent")}
                        </SelectItem>
                        <SelectItem value="early_leave">
                          {t("attendance.status_early_leave", "Early Leave")}
                        </SelectItem>
                        <SelectItem value="on_leave">
                          {t("attendance.status_on_leave", "On Leave")}
                        </SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                )}
              </div>
            </CardContent>
          </Card>
        )}
      </div>

      {isPending ? (
        <div className="space-y-3">
          {Array.from({ length: 5 }).map((_, i) => (
            <Skeleton key={i} className="h-14 w-full" />
          ))}
        </div>
      ) : isError ? (
        <EmptyState
          icon={AlertTriangle}
          title={t("common.load_failed", "Couldn't load this")}
          description={t(
            "common.load_failed_hint",
            "The data may be out of date. Try again.",
          )}
          action={
            <Button variant="outline" onClick={() => refetch()}>
              {t("common.retry", "Try again")}
            </Button>
          }
        />
      ) : records.length === 0 ? (
        <EmptyState
          icon={Clock}
          title={
            hasActiveFilters
              ? t("attendance.no_match", "No records match filters")
              : t("attendance.empty_title", "No attendance records")
          }
          description={
            hasActiveFilters
              ? t(
                  "attendance.no_match_desc",
                  "Try adjusting the filters or clearing them",
                )
              : t(
                  "attendance.empty_desc",
                  "Check in to start recording your attendance",
                )
          }
        />
      ) : (
        <>
          <Card className="overflow-hidden rounded-xl border-border/60">
            <CardContent className="p-0">
              <SimpleTable
                mobileCards
                caption={t("attendance.title", "Attendance")}
                headers={[
                  ...(viewAll ? [t("attendance.employee", "Employee")] : []),
                  t("common.date", "Date"),
                  t("attendance.in", "In"),
                  t("attendance.out", "Out"),
                  t("attendance.source", "Source"),
                  t("common.status", "Status"),
                ]}
                colClassName={[
                  ...(viewAll ? [""] : []),
                  "",
                  "",
                  "",
                  "hidden sm:table-cell",
                  "",
                ]}
                rows={records.map((record) => ({
                  key: record.public_id,
                  cells: [
                    ...(viewAll ? [record.employee?.name ?? "—"] : []),
                    <span key="date" className="font-medium">
                      {record.date
                        ? formatDate(dateFromIso(record.date), locale)
                        : "—"}
                    </span>,
                    <span
                      key="in"
                      className="tabular-nums text-muted-foreground"
                    >
                      {record.check_in ? formatTime(record.check_in) : "—"}
                    </span>,
                    <span
                      key="out"
                      className="tabular-nums text-muted-foreground"
                    >
                      {record.check_out ? formatTime(record.check_out) : "—"}
                    </span>,
                    // The filter dropdown above already translates these; the
                    // rows were printing the raw enum, so the same value read
                    // "Biometric" here and "ባዮሜትሪክ" three inches away.
                    <span
                      key="src"
                      className="capitalize text-muted-foreground"
                    >
                      {t(`attendance.source_${record.source}`, record.source)}
                    </span>,
                    <StatusBadge key="status" status={record.status} />,
                  ],
                }))}
              />
            </CardContent>
          </Card>

          <PaginationControls meta={data?.meta} onPageChange={setPage} />
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
    <Button
      asChild
      variant="ghost"
      size="sm"
      className="h-8 px-2.5 text-muted-foreground hover:text-foreground"
    >
      <Link href={href}>
        <Icon className="mr-1.5 h-3.5 w-3.5" aria-hidden="true" />
        {children}
      </Link>
    </Button>
  );
}

function SubNavGroup({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) {
  // Two grid cells — the label, then its links — so the three labels line
  // up in one column.
  return (
    <>
      <span className="text-xs font-medium text-muted-foreground">{label}</span>
      <div className="flex min-w-0 flex-wrap items-center gap-1">
        {children}
      </div>
    </>
  );
}

function ManualEntryDialog({
  open,
  onClose,
}: {
  open: boolean;
  onClose: () => void;
}) {
  const { t } = useT();
  const [form, setForm] = useState({
    employee_public_id: "",
    date: new Date().toISOString().split("T")[0],
    check_in: "09:00",
    check_out: "17:00",
    reason: "",
  });

  const submit = useManualAttendance();

  function handleSubmit() {
    submit.mutate(
      {
        ...form,
        check_out: form.check_out || null,
        idempotency_key: crypto.randomUUID(),
      },
      {
        onSuccess: () => {
          toast.success(
            t("attendance.manual_recorded", "Manual entry recorded"),
          );
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
          toast.error(
            apiErrorDetail(err) ??
              t("attendance.manual_failed", "Manual entry failed"),
          );
        },
      },
    );
  }

  return (
    <Dialog open={open} onOpenChange={onClose}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {t("attendance.manual_title", "Manual Attendance Entry")}
          </DialogTitle>
        </DialogHeader>
        <form
          onSubmit={(e) => {
            e.preventDefault();
            handleSubmit();
          }}
          className="space-y-3"
        >
          <div>
            <Label htmlFor="employee-required">
              {t("attendance.employee_required", "Employee *")}
            </Label>
            <EmployeeSelect
              id="employee-required"
              value={form.employee_public_id}
              onChange={(v) =>
                setForm((p) => ({ ...p, employee_public_id: v }))
              }
              enabled={open}
            />
          </div>
          <div className="grid grid-cols-3 gap-3">
            <div>
              <Label>{t("attendance.date_required", "Date *")}</Label>
              <DualCalendarDateInput
                value={form.date}
                onChange={(v) => setForm((p) => ({ ...p, date: v }))}
                required
                className="mt-1"
              />
            </div>
            <div>
              <Label htmlFor="check-in-required">
                {t("attendance.check_in_required", "Check In *")}
              </Label>
              <Input
                id="check-in-required"
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
              <Label htmlFor="check-out">
                {t("common.check_out", "Check Out")}
              </Label>
              <Input
                id="check-out"
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
            <Label htmlFor="reason-required">
              {t("attendance.reason_required", "Reason *")}
            </Label>
            <Textarea
              id="reason-required"
              value={form.reason}
              onChange={(e) =>
                setForm((p) => ({ ...p, reason: e.target.value }))
              }
              placeholder={t(
                "attendance.reason_placeholder",
                "Why is this manual entry needed?",
              )}
              required
              rows={2}
              className="mt-1"
            />
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              {t("common.cancel", "Cancel")}
            </Button>
            <Button
              type="submit"
              disabled={submit.isPending || !form.employee_public_id}
            >
              {submit.isPending && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              {t("attendance.record_entry", "Record Entry")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
