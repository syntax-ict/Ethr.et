"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { QRCodeSVG } from "qrcode.react";
import {
  QrCode,
  Printer,
  RefreshCw,
  Loader2,
  Timer,
  Clock,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { PageHeader } from "@/components/shared/page-header";
import { RoleGate } from "@/components/shared/role-gate";
import { useMutation, useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import { cn } from "@/lib/utils";

interface QrResult {
  token: string;
  branch_name?: string;
  shift_name?: string | null;
  expires_at: string;
  generated_at: string;
  expiry_minutes: number;
  auto_refresh?: boolean;
}

export default function QrGeneratorPage() {
  const { t } = useT();
  const { formatDateTime } = useDateFormatters();
  const [branchId, setBranchId] = useState("");
  const [shiftId, setShiftId] = useState("none");
  const [expiry, setExpiry] = useState(30);
  const [autoRefresh, setAutoRefresh] = useState(true);
  const [result, setResult] = useState<QrResult | null>(null);
  const [secondsLeft, setSecondsLeft] = useState(0);
  const countdownRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const { data: branches } = useQuery({
    queryKey: ["org", "branches"],
    queryFn: async () => (await apiClient.get("/organization/branches")).data,
  });

  const { data: shifts } = useQuery({
    queryKey: ["shifts"],
    queryFn: async () => (await apiClient.get("/shifts")).data,
  });

  const generate = useMutation({
    mutationFn: async () => {
      const payload: Record<string, unknown> = {
        branch_public_id: branchId,
        expiry_minutes: expiry,
      };
      if (shiftId !== "none") payload.shift_public_id = shiftId;
      const { data } = await apiClient.get("/attendance/qr/generate", {
        params: payload,
      });
      return data as QrResult;
    },
    onSuccess: (data) => {
      setResult(data);
      if (data.auto_refresh !== undefined) setAutoRefresh(data.auto_refresh);
      startCountdown(data.expires_at);
      toast.success(t("attendance.qr_page.generated"));
    },
    onError: (err: unknown) => {
      const axiosErr = err as { response?: { data?: { detail?: string } } };
      toast.error(
        axiosErr.response?.data?.detail ??
          t("attendance.qr_page.generate_failed"),
      );
    },
  });

  const startCountdown = useCallback((expiresAt: string) => {
    if (countdownRef.current) clearInterval(countdownRef.current);

    const update = () => {
      const diff = Math.max(
        0,
        Math.floor((new Date(expiresAt).getTime() - Date.now()) / 1000),
      );
      setSecondsLeft(diff);
      return diff;
    };

    update();
    countdownRef.current = setInterval(() => {
      const remaining = update();
      if (remaining <= 0 && countdownRef.current) {
        clearInterval(countdownRef.current);
      }
    }, 1000);
  }, []);

  // Auto-refresh when expired
  useEffect(() => {
    if (secondsLeft === 0 && result && autoRefresh && branchId) {
      const timeout = setTimeout(() => {
        generate.mutate();
      }, 1000);
      return () => clearTimeout(timeout);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [secondsLeft, autoRefresh, result, branchId]);

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      if (countdownRef.current) clearInterval(countdownRef.current);
    };
  }, []);

  function formatTime(secs: number): string {
    const m = Math.floor(secs / 60);
    const s = secs % 60;
    return `${m}:${s.toString().padStart(2, "0")}`;
  }

  const isExpired = result && secondsLeft <= 0;
  const isLow = secondsLeft > 0 && secondsLeft <= 60;

  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("attendance.qr_page.title")}
          description={t("attendance.qr_page.description")}
        />

        <div className="grid gap-6 lg:grid-cols-[1fr_2fr]">
          {/* Config panel */}
          <Card>
            <CardHeader>
              <CardTitle className="text-base">
                {t("attendance.qr_page.configuration")}
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div>
                <Label htmlFor="branch">
                  {t("attendance.kiosks_page.branch")}
                </Label>
                <Select value={branchId} onValueChange={setBranchId}>
                  <SelectTrigger id="branch" className="mt-1">
                    <SelectValue
                      placeholder={t("attendance.kiosks_page.select_branch")}
                    />
                  </SelectTrigger>
                  <SelectContent>
                    {branches?.data?.map(
                      (b: { public_id: string; name: string }) => (
                        <SelectItem key={b.public_id} value={b.public_id}>
                          {b.name}
                        </SelectItem>
                      ),
                    )}
                  </SelectContent>
                </Select>
              </div>
              <div>
                <Label htmlFor="shift-optional">
                  {t("attendance.qr_page.shift_optional")}
                </Label>
                <Select value={shiftId} onValueChange={setShiftId}>
                  <SelectTrigger id="shift-optional" className="mt-1">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">
                      {t("attendance.qr_page.any_shift")}
                    </SelectItem>
                    {shifts?.data?.map(
                      (s: { public_id: string; name: string }) => (
                        <SelectItem key={s.public_id} value={s.public_id}>
                          {s.name}
                        </SelectItem>
                      ),
                    )}
                  </SelectContent>
                </Select>
              </div>
              <div>
                <Label htmlFor="expiry-minutes">
                  {t("attendance.qr_page.expiry_minutes")}
                </Label>
                <Input
                  id="expiry-minutes"
                  type="number"
                  value={expiry}
                  onChange={(e) => setExpiry(parseInt(e.target.value) || 30)}
                  min={5}
                  max={480}
                  className="mt-1"
                />
                <p className="mt-1 text-xs text-muted-foreground">
                  {t("attendance.qr_page.expiry_range")}
                </p>
              </div>
              <div className="flex items-center justify-between rounded-lg border p-3">
                <div>
                  <p className="text-sm font-medium">
                    {t("attendance.settings_page.auto_refresh")}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {t("attendance.qr_page.regenerate_when_expired")}
                  </p>
                </div>
                <Switch
                  aria-label={t("attendance.settings_page.auto_refresh")}
                  checked={autoRefresh}
                  onCheckedChange={setAutoRefresh}
                />
              </div>
              <Button
                onClick={() => generate.mutate()}
                disabled={!branchId || generate.isPending}
                className="w-full"
              >
                {generate.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <QrCode className="mr-2 h-4 w-4" />
                )}
                {t("attendance.qr_page.generate_qr")}
              </Button>
            </CardContent>
          </Card>

          {/* QR display */}
          <Card className="print:shadow-none print:border-0">
            <CardHeader className="print:hidden">
              <div className="flex items-center justify-between">
                <CardTitle className="text-base">
                  {t("attendance.qr_page.qr_code")}
                </CardTitle>
                {result && (
                  <div className="flex gap-2">
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => generate.mutate()}
                      disabled={generate.isPending}
                    >
                      <RefreshCw
                        className={cn(
                          "mr-2 h-3 w-3",
                          generate.isPending && "animate-spin",
                        )}
                      />{" "}
                      {t("attendance.qr_page.refresh")}
                    </Button>
                    <Button size="sm" onClick={() => window.print()}>
                      <Printer className="mr-2 h-3 w-3" />{" "}
                      {t("attendance.qr_page.print")}
                    </Button>
                  </div>
                )}
              </div>
            </CardHeader>
            <CardContent>
              {!result ? (
                <div className="flex flex-col items-center justify-center py-16 text-center text-muted-foreground">
                  <QrCode className="h-12 w-12 opacity-30" />
                  <p className="mt-3 text-sm">
                    {t("attendance.qr_page.configure_prompt")}
                  </p>
                </div>
              ) : (
                <div className="text-center space-y-4 py-6">
                  {/* Countdown timer */}
                  <div
                    className={cn(
                      "inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-sm font-medium",
                      isExpired
                        ? "bg-destructive-soft text-destructive-on-soft"
                        : isLow
                          ? "bg-warning-soft text-warning-on-soft animate-pulse"
                          : "bg-success-soft text-success-on-soft",
                    )}
                  >
                    {isExpired ? (
                      <>
                        <Clock className="h-3.5 w-3.5" />
                        {autoRefresh
                          ? t("attendance.qr_page.refreshing")
                          : t("attendance.qr_page.expired_click_refresh")}
                      </>
                    ) : (
                      <>
                        <Timer className="h-3.5 w-3.5" />
                        {formatTime(secondsLeft)}{" "}
                        {t("attendance.qr_page.remaining")}
                      </>
                    )}
                  </div>

                  {/* QR Code */}
                  <div
                    className={cn(
                      "inline-block rounded-2xl border-4 p-6 bg-white transition-opacity",
                      isExpired && !autoRefresh
                        ? "opacity-30 border-destructive-edge"
                        : "border-primary",
                    )}
                  >
                    <QRCodeSVG value={result.token} size={280} level="H" />
                  </div>

                  <div>
                    <p className="text-2xl font-bold">
                      {result.branch_name ??
                        t("attendance.qr_page.attendance_checkin")}
                    </p>
                    {result.shift_name && (
                      <p className="text-sm text-muted-foreground mt-1">
                        {t("attendance.qr_page.shift_label")}:{" "}
                        {result.shift_name}
                      </p>
                    )}
                    <p className="mt-2 text-sm text-muted-foreground">
                      {t("attendance.qr_page.scan_hint")}
                      <br />
                      {t("attendance.qr_page.valid_until")}{" "}
                      <span className="font-medium text-foreground">
                        {formatDateTime(result.expires_at)}
                      </span>
                    </p>
                  </div>
                </div>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </RoleGate>
  );
}
