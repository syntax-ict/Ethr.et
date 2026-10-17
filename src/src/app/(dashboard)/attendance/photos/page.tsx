"use client";

import { useEffect, useState, useSyncExternalStore } from "react";
import { Camera, Loader2, Lock, LockOpen } from "lucide-react";
import { toast } from "sonner";
import { useT } from "@/lib/i18n/useT";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { usePermissions } from "@/lib/hooks/usePermissions";
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
import {
  useAttendancePhotoImage,
  useAttendancePhotos,
  useHoldAttendancePhoto,
  useReleaseAttendancePhoto,
  type AttendancePhoto,
  type AttendancePhotoStatus,
} from "@/features/attendance/api";

type Which = "check-in" | "check-out";

function subscribeToNothing(): () => void {
  return () => {};
}

/**
 * The status the page opens on: `?status=review` from the deletion notice.
 * Read from `window.location`, not `useSearchParams`, which a statically
 * exported page cannot render without a Suspense boundary.
 */
function useInitialStatus(): AttendancePhotoStatus {
  return useSyncExternalStore(
    subscribeToNothing,
    () => {
      const s = new URLSearchParams(window.location.search).get("status");
      return s === "review" || s === "held" ? s : "all";
    },
    () => "all",
  );
}

/**
 * Attendance selfies under the retention policy. Each is kept for the
 * organisation's retention period, then HR and the department manager have
 * 48 hours to hold any an unresolved complaint needs before the photo is
 * deleted. The attendance record itself is always kept. Every view is
 * recorded in the audit log.
 */
export default function AttendancePhotosPage() {
  const { t } = useT();
  const { can } = usePermissions();
  const initialStatus = useInitialStatus();
  const [status, setStatus] = useState<AttendancePhotoStatus | null>(null);
  const [page, setPage] = useState(1);
  const [viewing, setViewing] = useState<{
    photo: AttendancePhoto;
    which: Which;
  } | null>(null);
  const [holding, setHolding] = useState<AttendancePhoto | null>(null);

  const current = status ?? initialStatus;
  const { data, isLoading, isError, refetch } = useAttendancePhotos({
    status: current,
    page,
  });
  const photos = data?.data ?? [];

  const statuses: Array<{ value: AttendancePhotoStatus; label: string }> = [
    { value: "all", label: t("attendance_photos.status_all", "All") },
    {
      value: "review",
      label: t("attendance_photos.status_review", "Due for deletion"),
    },
    { value: "held", label: t("attendance_photos.status_held", "On hold") },
  ];

  return (
    <div className="space-y-6">
      <p className="max-w-3xl text-sm text-muted-foreground">
        {t(
          "attendance_photos.description",
          "Selfies are kept for your organisation's retention period, then deleted after a 48-hour review. Hold a photo that an unresolved complaint still needs. Attendance times are always kept, and every view is recorded.",
        )}
      </p>

      <div
        role="radiogroup"
        aria-label={t("attendance_photos.status_filter", "Show")}
        className="inline-flex rounded-lg border bg-muted/40 p-0.5"
      >
        {statuses.map((o) => (
          <button
            key={o.value}
            type="button"
            role="radio"
            aria-checked={current === o.value}
            onClick={() => {
              setStatus(o.value);
              setPage(1);
            }}
            className={cn(
              "rounded-md px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
              current === o.value
                ? "bg-background text-foreground shadow-xs"
                : "text-muted-foreground hover:text-foreground",
            )}
          >
            {o.label}
          </button>
        ))}
      </div>

      {isLoading ? (
        <div className="space-y-3">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-24" />
          ))}
        </div>
      ) : isError ? (
        <EmptyState
          icon={Camera}
          title={t("attendance_photos.error_title", "Couldn't load the photos")}
          description={t(
            "attendance_photos.error_desc",
            "Check your connection and try again.",
          )}
          action={
            <Button variant="outline" onClick={() => void refetch()}>
              {t("common.retry", "Retry")}
            </Button>
          }
        />
      ) : photos.length === 0 ? (
        <EmptyState
          icon={Camera}
          title={
            current === "review"
              ? t(
                  "attendance_photos.empty_review",
                  "No photos due for deletion",
                )
              : current === "held"
                ? t("attendance_photos.empty_held", "No photos on hold")
                : t("attendance_photos.empty_all", "No attendance photos")
          }
          description={t(
            "attendance_photos.empty_desc",
            "Selfies taken at check-in on the phone appear here while they are kept.",
          )}
        />
      ) : (
        <div className="space-y-3">
          {photos.map((photo) => (
            <PhotoCard
              key={photo.public_id}
              photo={photo}
              canRelease={can.manageAttendance}
              onView={(which) => setViewing({ photo, which })}
              onHold={() => setHolding(photo)}
            />
          ))}
          <PaginationControls meta={data?.meta} onPageChange={setPage} />
        </div>
      )}

      {viewing && (
        <PhotoDialog
          photo={viewing.photo}
          which={viewing.which}
          onClose={() => setViewing(null)}
        />
      )}
      {holding && (
        <HoldDialog photo={holding} onClose={() => setHolding(null)} />
      )}
    </div>
  );
}

function PhotoCard({
  photo,
  canRelease,
  onView,
  onHold,
}: {
  photo: AttendancePhoto;
  canRelease: boolean;
  onView: (which: Which) => void;
  onHold: () => void;
}) {
  const { t } = useT();
  const { formatDate, formatDateTime, formatTime } = useDateFormatters();
  const release = useReleaseAttendancePhoto();

  function releaseHold() {
    release.mutate(photo.public_id, {
      onSuccess: () =>
        toast.success(
          t(
            "attendance_photos.released",
            "Hold released. The photo will be announced again before it is deleted.",
          ),
        ),
      onError: (err) =>
        toast.error(
          apiErrorDetail(err) ??
            t(
              "attendance_photos.release_failed",
              "Could not release the hold.",
            ),
        ),
    });
  }

  return (
    <Card>
      <CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="min-w-0 space-y-1">
          <p className="font-medium">
            {photo.employee.name}
            {photo.employee.employee_code && (
              <span className="ml-2 font-mono text-xs text-muted-foreground">
                {photo.employee.employee_code}
              </span>
            )}
          </p>
          <p className="text-sm text-muted-foreground">
            {photo.date ? formatDate(photo.date) : null}
            {photo.check_in && ` · ${formatTime(photo.check_in)}`}
            {photo.check_out && ` to ${formatTime(photo.check_out)}`}
          </p>
          <div className="flex flex-wrap gap-2 pt-1">
            {photo.held ? (
              <Badge className="bg-info-soft text-info-on-soft">
                {t("attendance_photos.on_hold", "On hold")}
              </Badge>
            ) : photo.delete_after ? (
              <Badge className="bg-warning-soft text-warning-on-soft">
                {t("attendance_photos.deleted_after", "Deleted after :when", {
                  when: formatDateTime(photo.delete_after),
                })}
              </Badge>
            ) : (
              <Badge className="bg-muted text-muted-foreground">
                {t("attendance_photos.kept_until", "Kept until :when", {
                  when: formatDate(photo.keep_until),
                })}
              </Badge>
            )}
          </div>
          {photo.held && photo.hold_reason && (
            <p className="text-sm text-muted-foreground">
              {t("attendance_photos.hold_reason", "Held by :who: :reason", {
                who: photo.held_by ?? "",
                reason: photo.hold_reason,
              })}
            </p>
          )}
        </div>
        <div className="flex flex-wrap gap-2">
          {photo.has_check_in_photo && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => onView("check-in")}
            >
              <Camera className="mr-1.5 h-4 w-4" aria-hidden />
              {t("attendance_photos.view_check_in", "Check-in photo")}
            </Button>
          )}
          {photo.has_check_out_photo && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => onView("check-out")}
            >
              <Camera className="mr-1.5 h-4 w-4" aria-hidden />
              {t("attendance_photos.view_check_out", "Check-out photo")}
            </Button>
          )}
          {photo.held ? (
            canRelease && (
              <Button
                variant="outline"
                size="sm"
                onClick={releaseHold}
                disabled={release.isPending}
              >
                <LockOpen className="mr-1.5 h-4 w-4" aria-hidden />
                {t("attendance_photos.release", "Release hold")}
              </Button>
            )
          ) : (
            <Button variant="outline" size="sm" onClick={onHold}>
              <Lock className="mr-1.5 h-4 w-4" aria-hidden />
              {t("attendance_photos.hold", "Hold for complaint")}
            </Button>
          )}
        </div>
      </CardContent>
    </Card>
  );
}

function PhotoDialog({
  photo,
  which,
  onClose,
}: {
  photo: AttendancePhoto;
  which: Which;
  onClose: () => void;
}) {
  const { t } = useT();
  const image = useAttendancePhotoImage(photo.public_id, which);

  // The object URL lives only while the dialog is open.
  useEffect(() => {
    const url = image.data;
    return () => {
      if (url) URL.revokeObjectURL(url);
    };
  }, [image.data]);

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {which === "check-in"
              ? t("attendance_photos.view_check_in", "Check-in photo")
              : t("attendance_photos.view_check_out", "Check-out photo")}
          </DialogTitle>
          <DialogDescription>
            {t(
              "attendance_photos.view_recorded",
              ":name. This view is recorded in the audit log.",
              { name: photo.employee.name },
            )}
          </DialogDescription>
        </DialogHeader>
        {image.isLoading ? (
          <Skeleton className="aspect-square w-full" />
        ) : image.data ? (
          // An object URL from an authenticated fetch; next/image cannot load it.
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={image.data}
            alt={t(
              "attendance_photos.photo_alt",
              "Attendance selfie of :name",
              {
                name: photo.employee.name,
              },
            )}
            className="w-full rounded-md"
          />
        ) : (
          <p className="text-sm text-muted-foreground">
            {t(
              "attendance_photos.image_failed",
              "This photo could not be loaded.",
            )}
          </p>
        )}
      </DialogContent>
    </Dialog>
  );
}

function HoldDialog({
  photo,
  onClose,
}: {
  photo: AttendancePhoto;
  onClose: () => void;
}) {
  const { t } = useT();
  const hold = useHoldAttendancePhoto();
  const [reason, setReason] = useState("");
  const valid = reason.trim().length >= 3;

  function submit() {
    hold.mutate(
      { publicId: photo.public_id, reason: reason.trim() },
      {
        onSuccess: () => {
          toast.success(
            t(
              "attendance_photos.held_toast",
              "Photo held. It will not be deleted until HR releases the hold.",
            ),
          );
          onClose();
        },
        onError: (err) =>
          toast.error(
            apiErrorDetail(err) ??
              t("attendance_photos.hold_failed", "Could not hold the photo."),
          ),
      },
    );
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {t("attendance_photos.hold_title", "Hold for a complaint")}
          </DialogTitle>
          <DialogDescription>
            {t(
              "attendance_photos.hold_desc",
              "The photos on :name's record will be kept until HR releases the hold. Say which complaint needs them.",
              { name: photo.employee.name },
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-2">
          <Label htmlFor="hold-reason">
            {t("attendance_photos.reason", "Reason")}
          </Label>
          <Textarea
            id="hold-reason"
            value={reason}
            maxLength={500}
            onChange={(e) => setReason(e.target.value)}
          />
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t("common.cancel", "Cancel")}
          </Button>
          <Button onClick={submit} disabled={!valid || hold.isPending}>
            {hold.isPending && (
              <Loader2 className="mr-1.5 h-4 w-4 animate-spin" aria-hidden />
            )}
            {t("attendance_photos.hold_confirm", "Hold photo")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
