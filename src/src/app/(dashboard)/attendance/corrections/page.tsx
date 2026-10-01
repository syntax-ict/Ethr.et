"use client";

import { useState } from "react";
import {
  FileEdit,
  Plus,
  Loader2,
  Check,
  X,
  AlertCircle,
  TrendingUp,
  TrendingDown,
  Minus,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { Textarea } from "@/components/ui/textarea";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Badge } from "@/components/ui/badge";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  DialogDescription,
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
import { SimpleTable } from "@/components/shared/simple-table";
import { EmptyState } from "@/components/shared/empty-state";
import { PaginationControls } from "@/components/shared/pagination-controls";
import {
  useApproveCorrection,
  useCorrectionPayrollImpact,
  useCorrections,
  useMyAttendance,
  usePendingCorrections,
  useRejectCorrection,
  useSubmitCorrection,
  type AttendanceCorrection,
} from "@/features/attendance/api";
import { zonedWallTimeToUtcIso } from "@/features/attendance/time";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

/**
 * Each tab is gated on the ability its endpoint checks.
 *
 * The first tab used to be "My Requests" and called `GET /attendance/corrections`
 * for everyone — an endpoint that requires correction.viewAll and returns every
 * correction in the tenant. An employee got a 403 rendered as "No correction
 * requests"; an HR admin got the whole tenant labelled as their own. There is
 * no endpoint for a caller's own corrections, so the list is offered only to
 * those who may read all of them.
 */
export default function CorrectionsPage() {
  const { t } = useT();
  const { can } = usePermissions();
  const [requestOpen, setRequestOpen] = useState(false);

  const showAll = can.viewAllCorrections;
  const showPending = can.reviewCorrections;

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("attendance.corrections.title")}
        description={t("attendance.corrections.description")}
        actions={
          <Button onClick={() => setRequestOpen(true)}>
            <Plus className="mr-2 h-4 w-4" />{" "}
            {t("attendance.corrections.request")}
          </Button>
        }
      />

      {(showAll || showPending) && (
        <Tabs defaultValue={showPending ? "pending" : "all"}>
          <TabsList>
            {showPending && (
              <TabsTrigger value="pending">
                {t("attendance.corrections.pending_reviews")}
              </TabsTrigger>
            )}
            {showAll && (
              <TabsTrigger value="all">
                {t("nav.corrections", "Corrections")}
              </TabsTrigger>
            )}
          </TabsList>

          {showPending && (
            <TabsContent value="pending" className="mt-4">
              <PendingReviewsTab canDecide={can.approveCorrections} />
            </TabsContent>
          )}
          {showAll && (
            <TabsContent value="all" className="mt-4">
              <AllCorrectionsTab />
            </TabsContent>
          )}
        </Tabs>
      )}

      <RequestDialog open={requestOpen} onClose={() => setRequestOpen(false)} />
    </div>
  );
}

/** Proposed punches fall back to the record's own when left unchanged. */
function punchPair(c: AttendanceCorrection) {
  return {
    date: c.attendance_record?.date ?? null,
    originalIn: c.attendance_record?.check_in ?? null,
    originalOut: c.attendance_record?.check_out ?? null,
    proposedIn: c.proposed_check_in,
    proposedOut: c.proposed_check_out,
  };
}

// ── ALL CORRECTIONS (correction.viewAll) ───────────────────────

function AllCorrectionsTab() {
  const { t } = useT();
  const { formatTime } = useDateFormatters();
  const [page, setPage] = useState(1);
  const { data, isLoading } = useCorrections({ page });

  const corrections = data?.data ?? [];
  const time = (iso: string | null) => (iso ? formatTime(iso) : "—");

  if (isLoading) {
    return (
      <div className="space-y-3">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-16 w-full" />
        ))}
      </div>
    );
  }

  if (corrections.length === 0) {
    return (
      <EmptyState
        icon={FileEdit}
        title={t("attendance.corrections.empty_title")}
        description={t("attendance.corrections.empty_desc")}
      />
    );
  }

  return (
    <Card>
      <CardContent className="p-0">
        <SimpleTable
          caption={t("attendance.corrections.title", "Corrections")}
          headers={[
            t("attendance.employee"),
            t("common.date"),
            t("attendance.corrections.corrected_in"),
            t("attendance.corrections.corrected_out"),
            t("attendance.corrections.reason"),
            t("common.status"),
          ]}
          colClassName={[
            "",
            "",
            "hidden sm:table-cell",
            "hidden sm:table-cell",
            "hidden max-w-xs md:table-cell",
            "",
          ]}
          rows={corrections.map((c) => {
            const p = punchPair(c);
            return {
              key: c.public_id,
              cells: [
                <span key="e" className="font-medium">
                  {c.employee?.name ?? "—"}
                </span>,
                <span key="d" className="text-muted-foreground">
                  {p.date ?? "—"}
                </span>,
                <span key="in" className="tabular-nums text-muted-foreground">
                  {time(p.proposedIn)}
                </span>,
                <span key="out" className="tabular-nums text-muted-foreground">
                  {time(p.proposedOut)}
                </span>,
                <span
                  key="r"
                  className="block max-w-[200px] truncate text-muted-foreground"
                >
                  {c.reason}
                </span>,
                <StatusBadge key="s" status={c.status} />,
              ],
            };
          })}
        />
        <PaginationControls meta={data?.meta} onPageChange={setPage} />
      </CardContent>
    </Card>
  );
}

// ── PAYROLL IMPACT BADGE ────────────────────────────────────────

function PayrollImpactBadge({ correctionId }: { correctionId: string }) {
  const { data, isLoading } = useCorrectionPayrollImpact(correctionId);

  if (isLoading || !data) return null;

  const diffMin = data.difference_minutes;
  const impactEtb = Math.abs(data.estimated_impact_cents) / 100;
  const isNeutral = diffMin === 0;
  const isPositive = diffMin > 0;

  const Icon = isNeutral ? Minus : isPositive ? TrendingUp : TrendingDown;
  const colorClass = isNeutral
    ? "text-muted-foreground"
    : isPositive
      ? "text-status-success"
      : "text-status-error";
  const label = isNeutral
    ? "No payroll impact"
    : `${isPositive ? "+" : "−"} ${impactEtb.toLocaleString("en-ET", { minimumFractionDigits: 2 })} ETB`;

  return (
    <div
      className={`flex items-center gap-1 text-[10px] font-medium ${colorClass}`}
    >
      <Icon className="h-3 w-3" />
      <span>{label}</span>
      {!isNeutral && (
        <span className="text-muted-foreground font-normal">
          ({Math.abs(diffMin)} min)
        </span>
      )}
    </div>
  );
}

// ── PENDING REVIEWS (correction.viewPending) ───────────────────

function PendingReviewsTab({ canDecide }: { canDecide: boolean }) {
  const { t } = useT();
  const { formatTime } = useDateFormatters();
  const [page, setPage] = useState(1);
  const [rejectFor, setRejectFor] = useState<AttendanceCorrection | null>(null);
  const [rejectReason, setRejectReason] = useState("");

  const { data, isLoading } = usePendingCorrections({ page });
  const approveMut = useApproveCorrection();
  const rejectMut = useRejectCorrection();

  const time = (iso: string | null) => (iso ? formatTime(iso) : "—");

  function approve(publicId: string) {
    approveMut.mutate(publicId, {
      onSuccess: () => toast.success(t("attendance.corrections.approved")),
      onError: () => toast.error(t("attendance.corrections.approve_failed")),
    });
  }

  function reject() {
    if (!rejectFor) return;
    rejectMut.mutate(
      { publicId: rejectFor.public_id, reason: rejectReason },
      {
        onSuccess: () => {
          toast.success(t("attendance.corrections.rejected"));
          setRejectFor(null);
          setRejectReason("");
        },
        onError: () => toast.error(t("attendance.corrections.reject_failed")),
      },
    );
  }

  const items = data?.data ?? [];

  if (isLoading) {
    return (
      <div className="space-y-3">
        {Array.from({ length: 3 }).map((_, i) => (
          <Skeleton key={i} className="h-20" />
        ))}
      </div>
    );
  }

  if (items.length === 0) {
    return (
      <EmptyState
        icon={Check}
        title={t("attendance.corrections.caught_up")}
        description={t("attendance.corrections.no_pending")}
      />
    );
  }

  return (
    <>
      <div className="space-y-3">
        {items.map((c) => {
          const p = punchPair(c);
          const isProcessing =
            (approveMut.isPending && approveMut.variables === c.public_id) ||
            (rejectMut.isPending &&
              rejectMut.variables?.publicId === c.public_id);
          return (
            <Card key={c.public_id}>
              <CardContent className="p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                  <div className="flex-1 min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <p className="font-semibold text-foreground">
                        {c.employee?.name ?? t("common.employee", "Employee")}
                      </p>
                      {c.employee?.employee_code && (
                        <Badge
                          variant="outline"
                          className="text-[10px] font-mono"
                        >
                          {c.employee.employee_code}
                        </Badge>
                      )}
                      {p.date && (
                        <Badge variant="outline" className="text-[10px]">
                          {p.date}
                        </Badge>
                      )}
                    </div>
                    <div className="mt-2 grid gap-2 text-xs sm:grid-cols-2">
                      <div className="flex items-baseline gap-2">
                        <span className="text-muted-foreground">
                          {t("attendance.corrections.original")}:
                        </span>
                        <span className="font-mono">
                          {time(p.originalIn)} → {time(p.originalOut)}
                        </span>
                      </div>
                      <div className="flex items-baseline gap-2">
                        <span className="text-muted-foreground">
                          {t("attendance.corrections.requested")}:
                        </span>
                        <span className="font-mono font-medium text-foreground">
                          {time(p.proposedIn)} → {time(p.proposedOut)}
                        </span>
                      </div>
                    </div>
                    <div className="mt-2 flex items-start gap-2 rounded-lg bg-muted/30 p-2">
                      <AlertCircle className="mt-0.5 h-3 w-3 shrink-0 text-muted-foreground" />
                      <p className="text-xs text-foreground">{c.reason}</p>
                    </div>
                    <div className="mt-2">
                      <PayrollImpactBadge correctionId={c.public_id} />
                    </div>
                  </div>
                  {canDecide && (
                    <div className="flex gap-2 sm:flex-col sm:items-stretch">
                      <Button
                        size="sm"
                        variant="outline"
                        className="text-success-on-soft hover:bg-success-soft"
                        onClick={() => approve(c.public_id)}
                        disabled={isProcessing}
                      >
                        {approveMut.isPending &&
                        approveMut.variables === c.public_id ? (
                          <Loader2 className="h-3 w-3 animate-spin" />
                        ) : (
                          <>
                            <Check className="mr-1 h-3 w-3" />{" "}
                            {t("common.approve")}
                          </>
                        )}
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        className="text-destructive-on-soft hover:bg-destructive-soft"
                        onClick={() => setRejectFor(c)}
                        disabled={isProcessing}
                      >
                        <X className="mr-1 h-3 w-3" /> {t("common.reject")}
                      </Button>
                    </div>
                  )}
                </div>
              </CardContent>
            </Card>
          );
        })}
      </div>
      <PaginationControls meta={data?.meta} onPageChange={setPage} />

      <Dialog
        open={!!rejectFor}
        onOpenChange={(open) => {
          if (!open) {
            setRejectFor(null);
            setRejectReason("");
          }
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {t("attendance.corrections.reject_title")}
            </DialogTitle>
            <DialogDescription>
              {t("attendance.corrections.reject_desc_prefix")}{" "}
              {rejectFor?.employee?.name ??
                t("attendance.corrections.the_employee")}{" "}
              {t("attendance.corrections.reject_desc_suffix")}
            </DialogDescription>
          </DialogHeader>
          <Textarea
            value={rejectReason}
            onChange={(e) => setRejectReason(e.target.value)}
            placeholder={t("attendance.corrections.reject_reason_placeholder")}
            aria-label={t("attendance.corrections.reason")}
            rows={3}
            required
          />
          <DialogFooter>
            <Button variant="outline" onClick={() => setRejectFor(null)}>
              {t("common.cancel")}
            </Button>
            <Button
              variant="destructive"
              onClick={reject}
              disabled={rejectMut.isPending || !rejectReason.trim()}
            >
              {rejectMut.isPending && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              {t("attendance.corrections.reject_correction")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

// ── REQUEST DIALOG ─────────────────────────────────────────────

const EMPTY_FORM = {
  date: "",
  check_in: "",
  check_out: "",
  reason: "",
  record_public_id: "",
};

/**
 * A correction targets one of the caller's own attendance records. The dialog
 * posted `{date, corrected_check_in, corrected_check_out, reason}`, but
 * StoreCorrectionRequest requires `attendance_record_public_id` and reads
 * `proposed_check_in`/`proposed_check_out` — so every request was a 422 and no
 * employee could ever submit one. The date now looks up the record, and the
 * times are sent as the UTC instants they mean in the tenant's timezone.
 */
function RequestDialog({
  open,
  onClose,
}: {
  open: boolean;
  onClose: () => void;
}) {
  const { t } = useT();
  const { timeZone, formatTime } = useDateFormatters();
  const [form, setForm] = useState(EMPTY_FORM);

  const dayRecords = useMyAttendance(
    { date_from: form.date, date_to: form.date },
    { enabled: open && !!form.date },
  );
  const records = dayRecords.data?.data ?? [];
  const recordId = form.record_public_id || records[0]?.public_id || "";
  const noRecord = !!form.date && dayRecords.isSuccess && records.length === 0;

  const submit = useSubmitCorrection();

  function handleSubmit() {
    if (!recordId) return;

    const proposedIn = form.check_in
      ? zonedWallTimeToUtcIso(form.date, form.check_in, timeZone)
      : null;
    // A check-out earlier than the check-in belongs to the next day — a night
    // shift — rather than to an impossible negative shift.
    const outDate =
      form.check_in && form.check_out && form.check_out < form.check_in
        ? nextDay(form.date)
        : form.date;
    const proposedOut = form.check_out
      ? zonedWallTimeToUtcIso(outDate, form.check_out, timeZone)
      : null;

    submit.mutate(
      {
        attendance_record_public_id: recordId,
        proposed_check_in: proposedIn,
        proposed_check_out: proposedOut,
        reason: form.reason,
      },
      {
        onSuccess: () => {
          toast.success(t("attendance.corrections.submitted"));
          onClose();
          setForm(EMPTY_FORM);
        },
        onError: () => toast.error(t("attendance.corrections.submit_failed")),
      },
    );
  }

  return (
    <Dialog open={open} onOpenChange={onClose}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t("attendance.corrections.request_title")}</DialogTitle>
        </DialogHeader>
        <form
          onSubmit={(e) => {
            e.preventDefault();
            handleSubmit();
          }}
          className="space-y-4"
        >
          <div>
            <Label>{t("attendance.date_required")}</Label>
            <DualCalendarDateInput
              value={form.date}
              onChange={(v) =>
                setForm((p) => ({ ...p, date: v, record_public_id: "" }))
              }
              required
              className="mt-1"
            />
            {noRecord && (
              <p role="alert" className="mt-1 text-xs text-destructive">
                {t("attendance.empty_title", "No attendance records")}
              </p>
            )}
          </div>
          {records.length > 1 && (
            <Select
              value={recordId}
              onValueChange={(v) =>
                setForm((p) => ({ ...p, record_public_id: v }))
              }
            >
              <SelectTrigger aria-label={t("attendance.title", "Attendance")}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {records.map((r) => (
                  <SelectItem key={r.public_id} value={r.public_id}>
                    {r.check_in ? formatTime(r.check_in) : "—"} →{" "}
                    {r.check_out ? formatTime(r.check_out) : "—"}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
          <div className="grid grid-cols-2 gap-4">
            <div>
              <Label htmlFor="correct-check-in">
                {t("attendance.corrections.correct_check_in")}
              </Label>
              <Input
                id="correct-check-in"
                type="time"
                value={form.check_in}
                onChange={(e) =>
                  setForm((p) => ({ ...p, check_in: e.target.value }))
                }
                className="mt-1"
              />
            </div>
            <div>
              <Label htmlFor="correct-check-out">
                {t("attendance.corrections.correct_check_out")}
              </Label>
              <Input
                id="correct-check-out"
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
              {t("attendance.reason_required")}
            </Label>
            <Textarea
              id="reason-required"
              value={form.reason}
              onChange={(e) =>
                setForm((p) => ({ ...p, reason: e.target.value }))
              }
              required
              placeholder={t("attendance.corrections.reason_placeholder")}
              className="mt-1"
            />
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              {t("common.cancel")}
            </Button>
            <Button type="submit" disabled={submit.isPending || !recordId}>
              {submit.isPending && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              {t("attendance.corrections.submit_request")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function nextDay(date: string): string {
  const d = new Date(`${date}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + 1);
  return d.toISOString().slice(0, 10);
}
