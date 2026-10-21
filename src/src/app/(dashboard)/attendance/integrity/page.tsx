"use client";

import { useState } from "react";
import {
  CheckCircle2,
  ExternalLink,
  Loader2,
  ShieldAlert,
  ShieldCheck,
  XCircle,
} from "lucide-react";
import { toast } from "sonner";
import { useT } from "@/lib/i18n/useT";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useCurrentUser } from "@/features/auth/api";
import { apiErrorDetail } from "@/lib/api/error-message";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader } from "@/components/shared/page-header";
import { PaginationControls } from "@/components/shared/pagination-controls";
import { StatCard } from "@/components/dashboard";
import {
  useIntegrityFlags,
  useIntegritySummary,
  useReviewIntegrityFlag,
  type IntegrityFlag,
  type IntegrityStatus,
} from "@/features/attendance/api";
import { ErrorState } from "@/components/shared/error-state";

type Severity = "all" | "high" | "medium" | "low";

const severityStyles: Record<string, string> = {
  high: "bg-destructive-soft text-destructive-on-soft",
  medium: "bg-warning-soft text-warning-on-soft",
  low: "bg-muted text-muted-foreground",
};

/**
 * The integrity review queue. Every punch is inspected as it is recorded;
 * what cannot be trusted on its face lands here with the evidence, and a
 * person decides. Rejecting voids the punch and tells the employee why;
 * accepting keeps it. Nobody reviews their own.
 */
export default function AttendanceIntegrityPage() {
  const { t } = useT();
  const { can } = usePermissions();
  const { data: me } = useCurrentUser();
  const [status, setStatus] = useState<IntegrityStatus>("open");
  const [severity, setSeverity] = useState<Severity>("all");
  const [page, setPage] = useState(1);
  const [reviewing, setReviewing] = useState<{
    flag: IntegrityFlag;
    decision: "accept" | "reject";
  } | null>(null);

  const { data, isLoading, isError, refetch } = useIntegrityFlags({
    status,
    severity,
    page,
  });
  const { data: summary, isLoading: summaryLoading } = useIntegritySummary();
  const flags = data?.data ?? [];

  const statuses: Array<{ value: IntegrityStatus; label: string }> = [
    { value: "open", label: t("integrity.status_open", "To review") },
    { value: "accepted", label: t("integrity.status_accepted", "Accepted") },
    { value: "rejected", label: t("integrity.status_rejected", "Rejected") },
  ];
  const severities: Array<{ value: Severity; label: string }> = [
    { value: "all", label: t("integrity.severity_all", "All") },
    { value: "high", label: t("integrity.severity_high", "High") },
    { value: "medium", label: t("integrity.severity_medium", "Medium") },
    { value: "low", label: t("integrity.severity_low", "Low") },
  ];

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("integrity.title", "Attendance integrity")}
        description={t(
          "integrity.description",
          "Punches that could not be trusted on their face. Accept to keep, reject to void - the employee is told why.",
        )}
      />

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          label={t("integrity.open", "To review")}
          value={summary?.open ?? 0}
          icon={ShieldAlert}
          tone={summary?.open ? "warning" : "success"}
          loading={summaryLoading}
        />
        <StatCard
          label={t("integrity.severity_high", "High")}
          value={summary?.by_severity.high ?? 0}
          icon={XCircle}
          tone="error"
          loading={summaryLoading}
        />
        <StatCard
          label={t("integrity.severity_medium", "Medium")}
          value={summary?.by_severity.medium ?? 0}
          icon={ShieldAlert}
          tone="warning"
          loading={summaryLoading}
        />
        <StatCard
          label={t("integrity.severity_low", "Low")}
          value={summary?.by_severity.low ?? 0}
          icon={ShieldCheck}
          tone="neutral"
          loading={summaryLoading}
        />
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <Segmented
          label={t("integrity.status_filter", "Status")}
          options={statuses}
          value={status}
          onChange={(v) => {
            setStatus(v);
            setPage(1);
          }}
        />
        <Segmented
          label={t("integrity.severity_filter", "Severity")}
          options={severities}
          value={severity}
          onChange={(v) => {
            setSeverity(v);
            setPage(1);
          }}
        />
      </div>

      {isLoading ? (
        <div className="space-y-3">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-28" />
          ))}
        </div>
      ) : isError ? (
        <ErrorState onRetry={() => refetch()} />
      ) : flags.length === 0 ? (
        <EmptyState
          icon={ShieldCheck}
          title={
            status === "open"
              ? t("integrity.empty_title", "Nothing to review")
              : t("integrity.empty_history", "No reviewed flags yet")
          }
          description={t(
            "integrity.empty_desc",
            "Every punch is checked as it is recorded. Anything questionable will appear here.",
          )}
        />
      ) : (
        <div className="space-y-3">
          {flags.map((flag) => (
            <FlagCard
              key={flag.public_id}
              flag={flag}
              canReview={
                can.resolveAttendanceConflicts &&
                flag.status === "open" &&
                flag.employee?.public_id !== me?.employee_public_id
              }
              onReview={(decision) => setReviewing({ flag, decision })}
            />
          ))}
          <PaginationControls meta={data?.meta} onPageChange={setPage} />
        </div>
      )}

      {reviewing && (
        <ReviewDialog
          flag={reviewing.flag}
          decision={reviewing.decision}
          onClose={() => setReviewing(null)}
        />
      )}
    </div>
  );
}

function Segmented<T extends string>({
  label,
  options,
  value,
  onChange,
}: {
  label: string;
  options: Array<{ value: T; label: string }>;
  value: T;
  onChange: (value: T) => void;
}) {
  return (
    <div
      role="radiogroup"
      aria-label={label}
      className="inline-flex rounded-lg border bg-muted/40 p-0.5"
    >
      {options.map((o) => (
        <button
          key={o.value}
          type="button"
          role="radio"
          aria-checked={value === o.value}
          onClick={() => onChange(o.value)}
          className={cn(
            "rounded-md px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
            value === o.value
              ? "bg-background text-foreground shadow-xs"
              : "text-muted-foreground hover:text-foreground",
          )}
        >
          {o.label}
        </button>
      ))}
    </div>
  );
}

/** A finding in words, with the numbers that raised it. */
function useDescribeFlag() {
  const { t } = useT();

  return (flag: IntegrityFlag): { title: string; detail: string } => {
    const d = flag.details as Record<string, unknown>;
    const n = (key: string) => String(d[key] ?? "?");

    switch (flag.type) {
      case "outside_geofence":
        return {
          title: t(
            "integrity.type.outside_geofence",
            "Outside the branch area",
          ),
          detail: t(
            "integrity.detail.outside_geofence",
            ":distance m from the branch; the area is :radius m.",
            { distance: n("distance_meters"), radius: n("radius_meters") },
          ),
        };
      case "location_missing":
        return {
          title: t("integrity.type.location_missing", "No location"),
          detail: t(
            "integrity.detail.location_missing",
            "Your organisation requires a location and this punch had none.",
          ),
        };
      case "suspect_location":
        return {
          title: t(
            "integrity.type.suspect_location",
            "Location looks simulated",
          ),
          detail:
            d.reason === "identical_coordinates"
              ? t(
                  "integrity.detail.identical_coordinates",
                  "The same coordinates, to the last decimal, as a punch on another day. Real GPS varies slightly every time.",
                )
              : t(
                  "integrity.detail.zero_accuracy",
                  "The phone reported perfect accuracy, which real GPS never does; location simulators commonly do.",
                ),
        };
      case "impossible_travel":
        return {
          title: t("integrity.type.impossible_travel", "Impossible journey"),
          detail: t(
            "integrity.detail.impossible_travel",
            ":km km from the previous punch in :minutes min (:speed km/h).",
            {
              km: n("distance_km"),
              minutes: n("minutes"),
              speed: n("speed_kmh"),
            },
          ),
        };
      case "low_accuracy":
        return {
          title: t("integrity.type.low_accuracy", "Weak GPS"),
          detail: t(
            "integrity.detail.low_accuracy",
            "Accurate to :accuracy m; your threshold is :threshold m.",
            {
              accuracy: n("accuracy_meters"),
              threshold: n("threshold_meters"),
            },
          ),
        };
      case "branch_mismatch":
        return {
          title: t("integrity.type.branch_mismatch", "Another branch"),
          detail:
            d.via === "kiosk"
              ? t(
                  "integrity.detail.branch_kiosk",
                  "Punched at a kiosk that belongs to another branch.",
                )
              : t(
                  "integrity.detail.branch_qr",
                  "Scanned a QR code that belongs to another branch.",
                ),
        };
      case "unverified_offline":
        return {
          title: t(
            "integrity.type.unverified_offline",
            "Offline punch not vouched for",
          ),
          detail: t(
            "integrity.detail.unverified_offline",
            "Made offline without a valid grant from the server, so its time rests on the phone clock alone.",
          ),
        };
      case "clock_suspect":
        return {
          title: t("integrity.type.clock_suspect", "Phone clock out of range"),
          detail: t(
            "integrity.detail.clock_suspect",
            "The time claimed is outside the period the phone's offline grant covers - its clock may have been changed.",
          ),
        };
      case "device_clock_ahead":
        return {
          title: t("integrity.type.device_clock_ahead", "Device clock ahead"),
          detail: t(
            "integrity.detail.device_clock_ahead",
            "The terminal's clock was :minutes minutes ahead when this punch arrived, so its time may be late. Set the device's clock from its Commands tab.",
            { minutes: Math.round(Number(d.offset_seconds ?? 0) / 60) },
          ),
        };
      case "late_sync":
        return {
          title: t("integrity.type.late_sync", "Synced late"),
          detail: t(
            "integrity.detail.late_sync",
            "Reached the server :hours hours after it was made.",
            { hours: n("hours") },
          ),
        };
      case "photo_missing":
        return {
          title: t("integrity.type.photo_missing", "No selfie"),
          detail: t(
            "integrity.detail.photo_missing",
            "Your organisation requires a selfie and this offline punch had none.",
          ),
        };
      case "low_confidence":
        return {
          title: t("integrity.type.low_confidence", "Low confidence"),
          detail: t(
            "integrity.detail.low_confidence",
            "Scored :confidence, below your threshold of :threshold.",
            { confidence: n("confidence"), threshold: n("threshold") },
          ),
        };
      default:
        return { title: flag.type, detail: "" };
    }
  };
}

function FlagCard({
  flag,
  canReview,
  onReview,
}: {
  flag: IntegrityFlag;
  canReview: boolean;
  onReview: (decision: "accept" | "reject") => void;
}) {
  const { t } = useT();
  const { formatDate, formatTime } = useDateFormatters();
  const describe = useDescribeFlag();
  const { title, detail } = describe(flag);
  const record = flag.record;
  const punchAt =
    flag.punch === "check_out" ? record?.check_out : record?.check_in;

  return (
    <Card>
      <CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0 space-y-1.5">
          <div className="flex flex-wrap items-center gap-2">
            <Badge
              variant="outline"
              className={`border-0 text-[10px] uppercase ${severityStyles[flag.severity] ?? ""}`}
            >
              {t(`integrity.severity_${flag.severity}`, flag.severity)}
            </Badge>
            <h3 className="text-sm font-semibold text-foreground">{title}</h3>
          </div>
          <p className="text-sm text-muted-foreground">{detail}</p>
          <p className="text-xs text-muted-foreground">
            <span className="font-medium text-foreground">
              {flag.employee?.name}
            </span>
            {flag.employee?.employee_code &&
              ` · ${flag.employee.employee_code}`}
            {" · "}
            {flag.punch === "check_out"
              ? t("integrity.check_out", "Check-out")
              : t("integrity.check_in", "Check-in")}
            {punchAt && ` · ${formatDate(punchAt)} ${formatTime(punchAt)}`}
            {record?.source &&
              ` · ${t(`attendance.source_${record.source === "offline_mobile" ? "offline" : record.source}`, record.source)}`}
          </p>
          {record?.latitude != null && record.longitude != null && (
            <a
              href={`https://www.openstreetmap.org/?mlat=${record.latitude}&mlon=${record.longitude}#map=17/${record.latitude}/${record.longitude}`}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
            >
              <ExternalLink className="h-3 w-3" aria-hidden="true" />
              {t("integrity.view_map", "See where on a map")}
            </a>
          )}
          {flag.status !== "open" && (
            <p className="text-xs text-muted-foreground">
              {flag.status === "accepted"
                ? t("integrity.accepted_by", "Accepted by :name", {
                    name: flag.reviewer_name ?? "?",
                  })
                : t("integrity.rejected_by", "Rejected by :name", {
                    name: flag.reviewer_name ?? "?",
                  })}
              {flag.review_note && ` - "${flag.review_note}"`}
            </p>
          )}
        </div>
        {canReview && (
          <div className="flex shrink-0 gap-2">
            <Button
              size="sm"
              variant="outline"
              onClick={() => onReview("accept")}
            >
              <CheckCircle2 className="mr-1.5 h-4 w-4" />
              {t("integrity.accept", "Accept")}
            </Button>
            <Button
              size="sm"
              variant="outline"
              onClick={() => onReview("reject")}
            >
              <XCircle className="mr-1.5 h-4 w-4 text-destructive" />
              {t("integrity.reject", "Reject")}
            </Button>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function ReviewDialog({
  flag,
  decision,
  onClose,
}: {
  flag: IntegrityFlag;
  decision: "accept" | "reject";
  onClose: () => void;
}) {
  const { t } = useT();
  const review = useReviewIntegrityFlag();
  const [note, setNote] = useState("");
  const reject = decision === "reject";

  function submit() {
    review.mutate(
      { publicId: flag.public_id, decision, note: note.trim() || null },
      {
        onSuccess: () => {
          toast.success(
            reject
              ? t(
                  "integrity.rejected_toast",
                  "Punch rejected; the employee has been told",
                )
              : t("integrity.accepted_toast", "Punch accepted"),
          );
          onClose();
        },
        onError: (err) =>
          toast.error(
            apiErrorDetail(err) ??
              t("integrity.review_failed", "Could not save the review"),
          ),
      },
    );
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {reject
              ? t("integrity.reject_title", "Reject this punch?")
              : t("integrity.accept_title", "Accept this punch?")}
          </DialogTitle>
          <DialogDescription>
            {reject
              ? t(
                  "integrity.reject_desc",
                  "The punch is voided and will not count toward hours or pay. :name is told, with your reason, and can file a correction.",
                  { name: flag.employee?.name ?? "" },
                )
              : t(
                  "integrity.accept_desc",
                  "The punch counts as recorded. Add a note so the decision can be understood later.",
                )}
          </DialogDescription>
        </DialogHeader>
        <div>
          <Label htmlFor="integrity-note">
            {reject
              ? t("integrity.reason", "Reason (sent to the employee)")
              : t("integrity.note", "Note (optional)")}
          </Label>
          <Textarea
            id="integrity-note"
            value={note}
            maxLength={500}
            rows={3}
            onChange={(e) => setNote(e.target.value)}
            className="mt-1"
          />
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t("common.cancel", "Cancel")}
          </Button>
          <Button
            variant={reject ? "destructive" : "default"}
            onClick={submit}
            disabled={review.isPending || (reject && note.trim() === "")}
          >
            {review.isPending && (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            )}
            {reject
              ? t("integrity.reject_confirm", "Reject and void")
              : t("integrity.accept_confirm", "Accept")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
