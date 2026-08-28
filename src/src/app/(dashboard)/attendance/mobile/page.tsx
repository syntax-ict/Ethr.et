"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import {
  Smartphone,
  MapPin,
  Camera,
  CheckCircle2,
  XCircle,
  LogIn,
  LogOut,
  AlertCircle,
  Loader2,
  RefreshCw,
  WifiOff,
  CloudUpload,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { PageHeader } from "@/components/shared/page-header";
import { apiClient } from "@/api/client";
import { toast } from "sonner";
import { cn } from "@/lib/utils";
import { useOfflineSync } from "@/lib/hooks/useOfflineSync";
import { enqueueOfflineRecord } from "@/lib/offline-queue";
import { useCurrentUser } from "@/features/auth/api";
import { useT } from "@/lib/i18n/useT";

type Status = "idle" | "submitting" | "success" | "error";

export default function MobileCheckInPage() {
  const { t } = useT();
  const router = useRouter();
  const { data: user } = useCurrentUser();
  const { isOnline, pendingCount, syncing, syncNow } = useOfflineSync();
  const [type, setType] = useState<"check_in" | "check_out">("check_in");
  const [status, setStatus] = useState<Status>("idle");
  const [message, setMessage] = useState("");
  const [coords, setCoords] = useState<{
    lat: number;
    lng: number;
    accuracy: number;
  } | null>(null);
  const [coordsErr, setCoordsErr] = useState("");
  const [photoDataUrl, setPhotoDataUrl] = useState<string | null>(null);
  const [cameraOn, setCameraOn] = useState(false);
  const videoRef = useRef<HTMLVideoElement | null>(null);
  const streamRef = useRef<MediaStream | null>(null);

  // Auto-request geolocation on load
  useEffect(() => {
    requestLocation();
    return () => stopCamera();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function requestLocation() {
    setCoordsErr("");
    if (!navigator.geolocation) {
      setCoordsErr(t("attendance.mobile_page.geolocation_unsupported"));
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (pos) =>
        setCoords({
          lat: pos.coords.latitude,
          lng: pos.coords.longitude,
          accuracy: pos.coords.accuracy,
        }),
      (err) => setCoordsErr(err.message),
      { enableHighAccuracy: true, timeout: 15000 },
    );
  }

  async function startCamera() {
    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: "user" },
      });
      streamRef.current = stream;
      if (videoRef.current) {
        videoRef.current.srcObject = stream;
        await videoRef.current.play();
      }
      setCameraOn(true);
    } catch {
      toast.error(t("attendance.mobile_page.camera_denied"));
    }
  }

  function stopCamera() {
    if (streamRef.current) {
      streamRef.current.getTracks().forEach((t) => t.stop());
      streamRef.current = null;
    }
    setCameraOn(false);
  }

  function snapPhoto() {
    if (!videoRef.current) return;
    const canvas = document.createElement("canvas");
    canvas.width = videoRef.current.videoWidth;
    canvas.height = videoRef.current.videoHeight;
    const ctx = canvas.getContext("2d");
    if (!ctx) return;
    ctx.drawImage(videoRef.current, 0, 0);
    setPhotoDataUrl(canvas.toDataURL("image/jpeg", 0.7));
    stopCamera();
  }

  async function submit() {
    if (!coords) {
      toast.error(t("attendance.mobile_page.location_required"));
      return;
    }
    setStatus("submitting");

    const idempotencyKey = `mobile-${Date.now()}-${Math.random().toString(36).slice(2)}`;

    if (!isOnline) {
      try {
        const employeePublicId = (user as { employee?: { public_id?: string } })
          ?.employee?.public_id;
        if (!employeePublicId) {
          setMessage(t("attendance.mobile_page.cannot_determine_identity"));
          setStatus("error");
          return;
        }
        await enqueueOfflineRecord({
          employee_public_id: employeePublicId,
          type,
          idempotency_key: idempotencyKey,
          offline_token: `offline-${Date.now()}`,
          latitude: coords.lat,
          longitude: coords.lng,
          captured_at: new Date().toISOString(),
        });
        setMessage(
          `${type === "check_in" ? t("common.check_in") : t("common.check_out")} ${t("attendance.mobile_page.saved_offline")}`,
        );
        setStatus("success");
        toast.success(t("attendance.mobile_page.saved_offline_toast"));
        setTimeout(() => router.push("/attendance"), 3000);
        return;
      } catch {
        setMessage(t("attendance.mobile_page.offline_save_failed"));
        setStatus("error");
        return;
      }
    }

    try {
      const path =
        type === "check_in"
          ? "/attendance/mobile/check-in"
          : "/attendance/mobile/check-out";
      const payload: Record<string, unknown> = {
        idempotency_key: idempotencyKey,
        latitude: coords.lat,
        longitude: coords.lng,
      };
      // The server stores the selfie and derives the object key; sending the
      // data URL as `photo_path` would exceed that field's length entirely.
      if (photoDataUrl) payload.photo = photoDataUrl;
      const { data } = await apiClient.post(path, payload);
      setMessage(
        `${type === "check_in" ? t("attendance.checked_in_label") : t("attendance.checked_out_label")} · ${t("attendance.mobile_page.confidence")} ${data.confidence_score ?? "—"}`,
      );
      setStatus("success");
      setTimeout(() => router.push("/attendance"), 3000);
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { detail?: string } } };
      setMessage(
        axiosErr.response?.data?.detail ??
          t("attendance.mobile_page.submit_failed"),
      );
      setStatus("error");
    }
  }

  if (status === "success") {
    return (
      <div className="mx-auto max-w-md py-10 text-center">
        <CheckCircle2 className="mx-auto h-20 w-20 text-success animate-in zoom-in" />
        <p className="mt-4 text-xl font-bold">{message}</p>
        <p className="mt-1 text-sm text-muted-foreground">
          {t("attendance.mobile_page.redirecting")}
        </p>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-md space-y-4">
      <PageHeader
        title={t("attendance.mobile_page.title")}
        description={t("attendance.mobile_page.description")}
      />

      {/* Offline / sync banner */}
      {!isOnline && (
        <div className="flex items-center gap-2 rounded-lg border border-warning-edge bg-warning-soft px-4 py-3">
          <WifiOff className="h-4 w-4 text-warning-on-soft shrink-0" />
          <p className="text-sm text-warning-on-soft">
            {t("attendance.mobile_page.offline_banner")}
          </p>
        </div>
      )}
      {pendingCount > 0 && (
        <div className="flex items-center justify-between rounded-lg border border-info-edge bg-info-soft px-4 py-3">
          <div className="flex items-center gap-2">
            <CloudUpload className="h-4 w-4 text-info-on-soft shrink-0" />
            <p className="text-sm text-info-on-soft">
              {pendingCount}{" "}
              {pendingCount > 1
                ? t("attendance.mobile_page.records_pending")
                : t("attendance.mobile_page.record_pending")}
            </p>
          </div>
          {isOnline && (
            <Button
              size="sm"
              variant="outline"
              onClick={() =>
                syncNow().then((r) => {
                  if (r.synced > 0)
                    toast.success(
                      `${t("attendance.mobile_page.synced")} ${r.synced} ${t("attendance.mobile_page.records_suffix")}`,
                    );
                  if (r.errors > 0)
                    toast.error(
                      `${r.errors} ${t("attendance.mobile_page.records_failed_suffix")}`,
                    );
                })
              }
              disabled={syncing}
            >
              {syncing ? (
                <Loader2 className="mr-1 h-3 w-3 animate-spin" />
              ) : null}
              {t("attendance.mobile_page.sync_now")}
            </Button>
          )}
        </div>
      )}

      {/* Type toggle */}
      <div className="flex rounded-xl border-2 p-1">
        <button
          onClick={() => setType("check_in")}
          className={cn(
            "flex-1 rounded-lg py-3 font-semibold flex items-center justify-center gap-2",
            type === "check_in"
              ? "bg-success text-success-foreground shadow-md"
              : "text-muted-foreground",
          )}
        >
          <LogIn className="h-4 w-4" /> {t("common.check_in")}
        </button>
        <button
          onClick={() => setType("check_out")}
          className={cn(
            "flex-1 rounded-lg py-3 font-semibold flex items-center justify-center gap-2",
            type === "check_out"
              ? "bg-warning text-warning-foreground shadow-md"
              : "text-muted-foreground",
          )}
        >
          <LogOut className="h-4 w-4" /> {t("common.check_out")}
        </button>
      </div>

      {/* Location */}
      <Card>
        <CardContent className="p-4">
          <div className="flex items-start gap-3">
            <div
              className={cn(
                "flex h-10 w-10 items-center justify-center rounded-xl",
                coords ? "bg-success-soft" : "bg-warning-soft",
              )}
            >
              <MapPin
                className={cn(
                  "h-5 w-5",
                  coords ? "text-success-on-soft" : "text-warning-on-soft",
                )}
              />
            </div>
            <div className="flex-1 min-w-0">
              <p className="font-semibold">
                {t("attendance.mobile_page.your_location")}
              </p>
              {coords ? (
                <>
                  <p className="mt-1 text-xs font-mono text-muted-foreground">
                    {coords.lat.toFixed(6)}, {coords.lng.toFixed(6)}
                  </p>
                  <Badge variant="outline" className="mt-1 text-[10px]">
                    ±{Math.round(coords.accuracy)}m
                  </Badge>
                </>
              ) : coordsErr ? (
                <p className="mt-1 text-xs text-destructive">{coordsErr}</p>
              ) : (
                <p className="mt-1 text-xs text-muted-foreground">
                  {t("attendance.mobile_page.getting_location")}
                </p>
              )}
            </div>
            <Button
              size="sm"
              variant="ghost"
              onClick={requestLocation}
              aria-label={t(
                "attendance.mobile_page.refresh_location",
                "Refresh location",
              )}
            >
              <RefreshCw className="h-3 w-3" aria-hidden="true" />
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Selfie */}
      <Card>
        <CardContent className="p-4">
          <div className="flex items-center gap-3">
            <div
              className={cn(
                "flex h-10 w-10 items-center justify-center rounded-xl",
                photoDataUrl ? "bg-success-soft" : "bg-muted",
              )}
            >
              <Camera
                className={cn(
                  "h-5 w-5",
                  photoDataUrl
                    ? "text-success-on-soft"
                    : "text-muted-foreground",
                )}
              />
            </div>
            <div className="flex-1">
              <p className="font-semibold">
                {t("attendance.mobile_page.selfie")}{" "}
                <span className="text-xs font-normal text-muted-foreground">
                  ({t("attendance.mobile_page.optional")})
                </span>
              </p>
              <p className="text-xs text-muted-foreground">
                {photoDataUrl
                  ? t("attendance.mobile_page.captured")
                  : cameraOn
                    ? t("attendance.mobile_page.camera_active")
                    : t("attendance.mobile_page.boosts_confidence")}
              </p>
            </div>
            {!cameraOn && !photoDataUrl && (
              <Button size="sm" variant="outline" onClick={startCamera}>
                {t("attendance.mobile_page.open")}
              </Button>
            )}
            {photoDataUrl && (
              <Button
                size="sm"
                variant="ghost"
                onClick={() => {
                  setPhotoDataUrl(null);
                }}
              >
                {t("attendance.mobile_page.retake")}
              </Button>
            )}
          </div>

          {cameraOn && (
            <div className="mt-3 space-y-2">
              <video
                ref={videoRef}
                autoPlay
                playsInline
                muted
                className="w-full rounded-lg bg-black"
              />
              <div className="flex gap-2">
                <Button onClick={snapPhoto} className="flex-1">
                  <Camera className="mr-2 h-4 w-4" />{" "}
                  {t("attendance.mobile_page.capture")}
                </Button>
                <Button variant="outline" onClick={stopCamera}>
                  {t("common.cancel")}
                </Button>
              </div>
            </div>
          )}

          {photoDataUrl && (
            /* eslint-disable-next-line @next/next/no-img-element */
            <img
              src={photoDataUrl}
              alt="Selfie preview"
              className="mt-3 max-h-48 w-full rounded-lg object-contain bg-muted"
            />
          )}
        </CardContent>
      </Card>

      {status === "error" && message && (
        <div className="flex items-start gap-2 rounded-lg border border-destructive-edge bg-destructive-soft p-3">
          <XCircle className="mt-0.5 h-4 w-4 shrink-0 text-destructive-on-soft" />
          <p className="text-sm text-foreground">{message}</p>
        </div>
      )}

      {!coords && (
        <div className="flex items-start gap-2 rounded-lg border border-warning-edge bg-warning-soft p-3">
          <AlertCircle className="mt-0.5 h-4 w-4 shrink-0 text-warning-on-soft" />
          <p className="text-sm text-foreground">
            {t("attendance.mobile_page.location_required_notice")}
          </p>
        </div>
      )}

      <Button
        className="w-full h-14 text-lg"
        onClick={submit}
        disabled={!coords || status === "submitting"}
      >
        {status === "submitting" ? (
          <>
            <Loader2 className="mr-2 h-5 w-5 animate-spin" />{" "}
            {t("attendance.mobile_page.submitting")}
          </>
        ) : (
          <>
            <Smartphone className="mr-2 h-5 w-5" />{" "}
            {t("attendance.mobile_page.submit")}{" "}
            {type === "check_in" ? t("common.check_in") : t("common.check_out")}
          </>
        )}
      </Button>
    </div>
  );
}
