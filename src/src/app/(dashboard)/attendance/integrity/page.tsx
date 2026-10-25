"use client";

import { useState } from "react";
import dynamic from "next/dynamic";
import {
  Camera,
  CheckCircle2,
  ExternalLink,
  MapPin,
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
import { PaginationControls } from "@/components/shared/pagination-controls";
import { StatCard } from "@/components/dashboard";
import {
  useAttendancePhotoImage,
  useIntegrityFlags,
  useIntegritySummary,
  useReviewIntegrityFlag,
  type IntegrityFlag,
  type IntegrityStatus,
} from "@/features/attendance/api";
import { ErrorState } from "@/components/shared/error-state";
import { FilterBar } from "@/components/shared/filter-bar";
import { useQueryFilters } from "@/lib/hooks/useQueryFilters";

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
  // In the address, so "open, high" can be sent as a link and survives a reload.
  const filters = useQueryFilters(
    { status: "open", severity: "all" },
    {
      status: ["open", "accepted", "rejected"],
      severity: ["all", "low", "medium", "high"],
    },
  );
  const status = filters.values.status as IntegrityStatus;
  const severity = filters.values.severity as Severity;
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

      <FilterBar
        activeCount={filters.activeCount}
        onClear={() => {
          filters.clear();
          setPage(1);
        }}
      >
        <Segmented
          label={t("integrity.status_filter", "Status")}
          options={statuses}
          value={status}
          onChange={(v) => {
            filters.set({ status: v });
            setPage(1);
          }}
        />
        <Segmented
          label={t("integrity.severity_filter", "Severity")}
          options={severities}
          value={severity}
          onChange={(v) => {
            filters.set({ severity: v });
            setPage(1);
          }}
        />
      </FilterBar>

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
      case "geofence_unconfigured":
        return {
          title: t(
            "integrity.type.geofence_unconfigured",
            "Branch not on the map",
          ),
          detail:
            d.reason === "no_branch"
              ? t(
                  "integrity.detail.geofence_no_branch",
                  "A location is required, but this person has no branch to check it against.",
                )
              : t(
                  "integrity.detail.geofence_branch_not_placed",
                  "A location is required, but this person's branch has no position on the map yet, so the punch could not be checked. Place the branch under Organisation.",
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
      case "high_risk":
        return {
          title: t("integrity.type.high_risk", "Risk score :score", {
            score: n("score"),
          }),
          detail: t(
            "integrity.detail.high_risk",
            "Several signals together reached your review threshold of :threshold. The reasons are listed below.",
            { threshold: n("threshold") },
          ),
        };
      case "buddy_pattern":
        return {
          title: t("integrity.type.buddy_pattern", "One phone, two people"),
          detail: t(
            "integrity.detail.buddy_pattern",
            "Punched in on the same phone as a colleague, within a minute, on :days days in two weeks.",
            { days: n("days") },
          ),
        };
      case "grace_edge":
        return {
          title: t("integrity.type.grace_edge", "Always on the grace edge"),
          detail: t(
            "integrity.detail.grace_edge",
            ":count of the last 10 arrivals came in the last three minutes before counting as late.",
            { count: n("at_edge") },
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

const LeafletMap = dynamic(
  () => import("@/components/shared/map-picker/leaflet-map"),
  { ssr: false, loading: () => <Skeleton className="h-full w-full" /> },
);

/**
 * Why this punch scored as it did, how often this person has been flagged,
 * and the evidence a reviewer needs to decide: the selfie (viewing it is
 * audited) and where the punch was.
 */
function RiskDetails({ flag }: { flag: IntegrityFlag }) {
  const { t } = useT();
  const { can } = usePermissions();
  const [photo, setPhoto] = useState(false);
  const [map, setMap] = useState(false);
  const record = flag.record;
  const which = flag.punch === "check_out" ? "check-out" : "check-in";
  const image = useAttendancePhotoImage(
    photo && record ? record.public_id : null,
    which,
  );

  const reason: Record<string, string> = {
    outside_geofence: t("risk.outside_geofence", "Outside every allowed place"),
    location_missing: t("risk.location_missing", "No location"),
    suspect_location: t("risk.suspect_location", "Location looks simulated"),
    low_accuracy: t("risk.low_accuracy", "Weak GPS"),
    impossible_travel: t("risk.impossible_travel", "Impossible journey"),
    branch_mismatch: t("risk.branch_mismatch", "Another branch"),
    unverified_offline: t(
      "risk.unverified_offline",
      "Offline punch not vouched for",
    ),
    clock_suspect: t("risk.clock_suspect", "Phone clock out of range"),
    late_sync: t("risk.late_sync", "Synced late"),
    photo_missing: t("risk.photo_missing", "No selfie"),
    device_clock_ahead: t("risk.device_clock_ahead", "Device clock ahead"),
    low_confidence: t("risk.low_confidence", "Low confidence"),
    shared_device: t("risk.shared_device", "Phone also used by someone else"),
    device_unverified: t(
      "risk.device_unverified",
      "Not from the employee's registered phone",
    ),
    device_install_changed: t(
      "risk.device_install_changed",
      "Registered phone, different app install",
    ),
    device_unverified_office_pc: t(
      "risk.device_unverified_office_pc",
      "Office computer, no phone",
    ),
    ip_burst: t(
      "risk.ip_burst",
      "Same network as another punch, not an office one",
    ),
    stale_fix: t("risk.stale_fix", "Location was minutes old"),
    automation: t("risk.automation", "Browser driven by automation"),
    missing_fix_details: t(
      "risk.missing_fix_details",
      "GPS details missing that this phone usually sends",
    ),
    clock_skew: t("risk.clock_skew", "Phone clock far from the server's"),
    photo_replayed: t("risk.photo_replayed", "Selfie repeats an earlier one"),
    photo_of_someone_else: t(
      "risk.photo_of_someone_else",
      "Selfie matches someone else's photo",
    ),
    unusual_place: t("risk.unusual_place", "Far from where they usually punch"),
    unusual_time: t("risk.unusual_time", "Far outside their usual time"),
    device_switch: t("risk.device_switch", "Checked out on a different phone"),
    no_face: t("risk.no_face", "No face in the selfie"),
    liveness_failed: t("risk.liveness_failed", "Liveness check failed"),
  };

  const reasons = (record?.risk_reasons ?? []) as string[];
  const hasPlace = record?.latitude != null && record?.longitude != null;

  return (
    <div className="space-y-2">
      {record?.risk_score != null && record.risk_score > 0 && (
        <div className="flex flex-wrap items-center gap-1.5">
          <Badge variant="secondary" className="tabular-nums">
            {t("risk.score", "Risk :score", { score: record.risk_score })}
          </Badge>
          {reasons.map((r) => (
            <Badge key={r} variant="outline" className="text-xs">
              {reason[r] ?? r}
            </Badge>
          ))}
        </div>
      )}
      {flag.employee_findings_90_days > 1 && (
        <p className="text-xs text-muted-foreground">
          {t("risk.history", ":count findings for this person in 90 days", {
            count: flag.employee_findings_90_days,
          })}
        </p>
      )}
      <div className="flex flex-wrap gap-2">
        {record?.has_photo && can.viewAttendancePhotos && (
          <Button size="sm" variant="outline" onClick={() => setPhoto(true)}>
            <Camera className="mr-1 h-3.5 w-3.5" aria-hidden="true" />
            {t("risk.view_selfie", "See the selfie")}
          </Button>
        )}
        {hasPlace && (
          <Button size="sm" variant="outline" onClick={() => setMap((v) => !v)}>
            <MapPin className="mr-1 h-3.5 w-3.5" aria-hidden="true" />
            {map
              ? t("risk.hide_map", "Hide map")
              : t("risk.show_map", "Show on map")}
          </Button>
        )}
      </div>
      {map && hasPlace && (
        <div className="h-48 w-full overflow-hidden rounded-md border border-border sm:w-96">
          <LeafletMap
            point={{ lat: record!.latitude!, lng: record!.longitude! }}
            radius={null}
          />
        </div>
      )}
      <Dialog open={photo} onOpenChange={setPhoto}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{flag.employee?.name}</DialogTitle>
            <DialogDescription>
              {t(
                "risk.selfie_audited",
                "Viewing this selfie is recorded in the audit log.",
              )}
            </DialogDescription>
          </DialogHeader>
          {image.isLoading ? (
            <Skeleton className="h-64 w-full" />
          ) : image.data ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={image.data}
              alt={t("risk.selfie_alt", "Attendance selfie")}
              className="mx-auto max-h-96 rounded-md"
            />
          ) : (
            <p className="text-sm text-muted-foreground">
              {t("risk.selfie_gone", "The selfie is no longer kept.")}
            </p>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
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
          <RiskDetails flag={flag} />
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
