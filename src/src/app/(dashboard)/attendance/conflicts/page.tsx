"use client";

import { useState } from "react";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import {
  DEFAULT_TIMEZONE,
  formatTime as sharedFormatTime,
} from "@/lib/utils/date";
import { AlertTriangle, GitMerge, Loader2, ShieldCheck } from "lucide-react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader } from "@/components/shared/page-header";

/**
 * Attendance conflict review.
 *
 * The whole subsystem — `AttendanceConflict`, `ConflictResolver`,
 * `GET /attendance/conflicts`, `PUT /attendance/conflicts/{id}/resolve` — was
 * built and tested with no way to reach it from the product, so a supervisor
 * could never actually see or resolve one. This is that surface.
 *
 * Resolving is a destructive-ish action (keeping one record voids the other),
 * so it is never optimistic and always goes through an explicit dialog, per the
 * project's optimistic-UI policy.
 */

interface AttendanceRecord {
  public_id: string;
  date: string | null;
  check_in: string | null;
  check_out: string | null;
  source_label?: string | null;
  status_label?: string | null;
  worked_minutes?: number | null;
  confidence_score?: number | null;
}

interface Conflict {
  public_id: string;
  conflict_type: string;
  resolution: string;
  resolution_notes: string | null;
  resolved_at: string | null;
  created_at: string | null;
  employee?: { name?: string; employee_code?: string } | null;
  record_a?: AttendanceRecord | null;
  record_b?: AttendanceRecord | null;
}

type ResolutionValue = "keep_a" | "keep_b" | "merged" | "dismissed";

export default function AttendanceConflictsPage() {
  const { t } = useT();
  const { can } = usePermissions();
  const queryClient = useQueryClient();

  const [status, setStatus] = useState<"pending" | "all">("pending");
  const [resolving, setResolving] = useState<Conflict | null>(null);
  const [resolution, setResolution] = useState<ResolutionValue | "">("");
  const [notes, setNotes] = useState("");

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ["attendance", "conflicts", status],
    queryFn: async () => {
      const { data } = await apiClient.get("/attendance/conflicts", {
        params:
          status === "pending"
            ? { "filter[resolution]": "pending" }
            : undefined,
      });
      return data;
    },
  });

  const resolve = useMutation({
    mutationFn: async () => {
      if (!resolving || resolution === "") return null;
      const { data } = await apiClient.put(
        `/attendance/conflicts/${resolving.public_id}/resolve`,
        {
          resolution,
          resolution_notes: notes.trim() === "" ? null : notes.trim(),
        },
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["attendance"] });
      toast.success(t("attendance.conflicts.resolved", "Conflict resolved"));
      closeDialog();
    },
    onError: () =>
      toast.error(
        t("attendance.conflicts.resolve_failed", "Failed to resolve conflict"),
      ),
  });

  function closeDialog() {
    setResolving(null);
    setResolution("");
    setNotes("");
  }

  const conflicts: Conflict[] = data?.data ?? [];

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("attendance.conflicts.title", "Attendance Conflicts")}
        description={t(
          "attendance.conflicts.description",
          "Records that disagree about the same day — review and decide which one stands",
        )}
        actions={
          <Select
            value={status}
            onValueChange={(v) => setStatus(v as "pending" | "all")}
          >
            <SelectTrigger className="w-44" aria-label={t("common.status")}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="pending">
                {t("attendance.conflicts.filter_pending", "Pending only")}
              </SelectItem>
              <SelectItem value="all">
                {t("attendance.conflicts.filter_all", "All conflicts")}
              </SelectItem>
            </SelectContent>
          </Select>
        }
      />

      {isLoading ? (
        <div className="space-y-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-40 w-full" />
          ))}
        </div>
      ) : isError ? (
        <EmptyState
          icon={AlertTriangle}
          title={t(
            "attendance.conflicts.error_title",
            "Could not load conflicts",
          )}
          description={t(
            "attendance.conflicts.error_desc",
            "Something went wrong while loading attendance conflicts.",
          )}
          action={
            <Button variant="outline" onClick={() => refetch()}>
              {t("common.retry", "Retry")}
            </Button>
          }
        />
      ) : conflicts.length === 0 ? (
        <EmptyState
          icon={ShieldCheck}
          title={t(
            "attendance.conflicts.empty_title",
            "No conflicts to review",
          )}
          description={t(
            "attendance.conflicts.empty_desc",
            "Attendance records from devices, kiosk, and mobile all agree right now.",
          )}
        />
      ) : (
        <div className="space-y-4">
          {conflicts.map((conflict) => (
            <ConflictCard
              key={conflict.public_id}
              conflict={conflict}
              canResolve={can.resolveAttendanceConflicts}
              onResolve={() => {
                setResolving(conflict);
                setResolution("");
                setNotes("");
              }}
            />
          ))}
        </div>
      )}

      <Dialog
        open={!!resolving}
        onOpenChange={(open) => {
          if (!open) closeDialog();
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {t("attendance.conflicts.resolve_title", "Resolve conflict")}
            </DialogTitle>
            <DialogDescription>
              {t(
                "attendance.conflicts.resolve_desc",
                "Keeping one record voids the other. This is recorded in the audit log and cannot be undone from here.",
              )}
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4">
            <div>
              <Label htmlFor="conflict-resolution">
                {t("attendance.conflicts.decision", "Decision")}
              </Label>
              <Select
                value={resolution}
                onValueChange={(v) => setResolution(v as ResolutionValue)}
              >
                <SelectTrigger id="conflict-resolution" className="mt-1">
                  <SelectValue
                    placeholder={t(
                      "attendance.conflicts.choose_decision",
                      "Choose a decision",
                    )}
                  />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="keep_a">
                    {t("attendance.conflicts.keep_a", "Keep record A (void B)")}
                  </SelectItem>
                  <SelectItem value="keep_b">
                    {t("attendance.conflicts.keep_b", "Keep record B (void A)")}
                  </SelectItem>
                  <SelectItem value="merged">
                    {t("attendance.conflicts.merged", "Merged manually")}
                  </SelectItem>
                  <SelectItem value="dismissed">
                    {t(
                      "attendance.conflicts.dismissed",
                      "Dismiss — not a conflict",
                    )}
                  </SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div>
              <Label htmlFor="conflict-notes">
                {t("attendance.conflicts.notes", "Notes (optional)")}
              </Label>
              <Textarea
                id="conflict-notes"
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                rows={3}
                maxLength={1000}
                className="mt-1"
                placeholder={t(
                  "attendance.conflicts.notes_placeholder",
                  "Why this decision was made",
                )}
              />
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={closeDialog}>
              {t("common.cancel", "Cancel")}
            </Button>
            <Button
              onClick={() => resolve.mutate()}
              disabled={resolution === "" || resolve.isPending}
            >
              {resolve.isPending && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              {t("attendance.conflicts.confirm_resolve", "Resolve conflict")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

function ConflictCard({
  conflict,
  canResolve,
  onResolve,
}: {
  conflict: Conflict;
  canResolve: boolean;
  onResolve: () => void;
}) {
  const { t } = useT();
  const isPending = conflict.resolution === "pending";

  return (
    <Card>
      <CardContent className="p-4">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <p className="font-semibold text-foreground">
                {conflict.employee?.name ?? t("common.employee", "Employee")}
              </p>
              {conflict.employee?.employee_code && (
                <Badge variant="outline" className="font-mono text-[10px]">
                  {conflict.employee.employee_code}
                </Badge>
              )}
              <Badge variant="warning" className="text-[10px]">
                {conflictTypeLabel(conflict.conflict_type, t)}
              </Badge>
              <Badge
                variant={isPending ? "secondary" : "success"}
                className="text-[10px]"
              >
                {resolutionLabel(conflict.resolution, t)}
              </Badge>
            </div>

            <div className="mt-3 grid gap-2 sm:grid-cols-2">
              <RecordSummary
                label={t("attendance.conflicts.record_a", "Record A")}
                record={conflict.record_a}
              />
              <RecordSummary
                label={t("attendance.conflicts.record_b", "Record B")}
                record={conflict.record_b}
              />
            </div>

            {conflict.resolution_notes && (
              <p className="mt-2 rounded-lg bg-muted/30 p-2 text-xs text-foreground">
                {conflict.resolution_notes}
              </p>
            )}
          </div>

          {isPending && canResolve && (
            <div className="flex gap-2 sm:flex-col">
              <Button size="sm" variant="outline" onClick={onResolve}>
                <GitMerge className="mr-1 h-3 w-3" />
                {t("attendance.conflicts.review", "Review")}
              </Button>
            </div>
          )}
        </div>
      </CardContent>
    </Card>
  );
}

function RecordSummary({
  label,
  record,
}: {
  label: string;
  record?: AttendanceRecord | null;
}) {
  const { t } = useT();
  // Punch times render in the tenant's timezone, not the browser's — §12g.
  const { timeZone } = useDateFormatters();

  if (!record) {
    return (
      <div className="rounded-lg border border-dashed p-3 text-xs text-muted-foreground">
        <p className="font-medium">{label}</p>
        <p className="mt-1">
          {t("attendance.conflicts.no_record", "No record on this side")}
        </p>
      </div>
    );
  }

  return (
    <div className="rounded-lg border p-3 text-xs">
      <div className="flex items-center justify-between gap-2">
        <p className="font-medium text-foreground">{label}</p>
        {record.source_label && (
          <Badge variant="outline" className="text-[10px]">
            {record.source_label}
          </Badge>
        )}
      </div>
      <p className="mt-1 font-mono text-foreground">
        {formatTime(record.check_in, timeZone)} →{" "}
        {formatTime(record.check_out, timeZone)}
      </p>
      <p className="mt-1 text-muted-foreground">
        {record.date ?? "—"}
        {typeof record.worked_minutes === "number" && (
          <>
            {" · "}
            {t("attendance.conflicts.worked", ":n min", {
              n: record.worked_minutes,
            })}
          </>
        )}
      </p>
    </div>
  );
}

/**
 * A module-level helper, so it takes the zone rather than reading a hook.
 *
 * Punch times display in the tenant's timezone (BASELINE §12g). The null and
 * NaN guards are why this exists rather than calling the shared `formatTime`
 * directly — a conflict record can carry a missing or malformed punch.
 */
function formatTime(
  iso: string | null | undefined,
  timeZone: string = DEFAULT_TIMEZONE,
): string {
  if (!iso) return "—";
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? "—" : sharedFormatTime(iso, timeZone);
}

/**
 * Literal keys rather than `attendance.conflicts.type_${value}` — the i18n gate
 * only checks string-literal keys, so a template would never be verified.
 */
function conflictTypeLabel(
  value: string,
  t: (k: string, f: string) => string,
): string {
  switch (value) {
    case "time_overlap":
      return t("attendance.conflicts.type_time_overlap", "Overlapping times");
    case "missing_check_out":
      return t(
        "attendance.conflicts.type_missing_check_out",
        "Missing check-out",
      );
    case "missing_check_in":
      return t(
        "attendance.conflicts.type_missing_check_in",
        "Missing check-in",
      );
    case "duplicate_source":
      return t("attendance.conflicts.type_duplicate_source", "Duplicate entry");
    case "multi_source_far":
      return t("attendance.conflicts.type_multi_source_far", "Distant sources");
    case "retroactive":
      return t("attendance.conflicts.type_retroactive", "Retroactive change");
    default:
      return value;
  }
}

function resolutionLabel(
  value: string,
  t: (k: string, f: string) => string,
): string {
  switch (value) {
    case "pending":
      return t("attendance.conflicts.status_pending", "Pending");
    case "keep_a":
      return t("attendance.conflicts.status_keep_a", "Kept A");
    case "keep_b":
      return t("attendance.conflicts.status_keep_b", "Kept B");
    case "merged":
      return t("attendance.conflicts.status_merged", "Merged");
    case "dismissed":
      return t("attendance.conflicts.status_dismissed", "Dismissed");
    default:
      return value;
  }
}
