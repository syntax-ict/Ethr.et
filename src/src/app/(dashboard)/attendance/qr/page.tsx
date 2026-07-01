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
  const [branchId, setBranchId] = useState("");
  const [shiftId, setShiftId] = useState("none");
  const [expiry, setExpiry] = useState(30);
  const [autoRefresh, setAutoRefresh] = useState(true);
  const [result, setResult] = useState<QrResult | null>(null);
  const [secondsLeft, setSecondsLeft] = useState(0);
  const refreshTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);
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
      toast.success("QR code generated");
    },
    onError: (err: unknown) => {
      const axiosErr = err as { response?: { data?: { detail?: string } } };
      toast.error(
        axiosErr.response?.data?.detail ?? "Failed to generate QR code",
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
      if (refreshTimerRef.current) clearInterval(refreshTimerRef.current);
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
          title="QR Attendance Code"
          description="Generate a printable QR code for employees to scan from their phones"
        />

        <div className="grid gap-6 lg:grid-cols-[1fr_2fr]">
          {/* Config panel */}
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Configuration</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div>
                <Label>Branch *</Label>
                <Select value={branchId} onValueChange={setBranchId}>
                  <SelectTrigger className="mt-1">
                    <SelectValue placeholder="Select branch" />
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
                <Label>Shift (optional)</Label>
                <Select value={shiftId} onValueChange={setShiftId}>
                  <SelectTrigger className="mt-1">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">Any shift</SelectItem>
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
                <Label>Expiry (minutes)</Label>
                <Input
                  type="number"
                  value={expiry}
                  onChange={(e) => setExpiry(parseInt(e.target.value) || 30)}
                  min={5}
                  max={480}
                  className="mt-1"
                />
                <p className="mt-1 text-xs text-muted-foreground">5–480 min</p>
              </div>
              <div className="flex items-center justify-between rounded-lg border p-3">
                <div>
                  <p className="text-sm font-medium">Auto-refresh</p>
                  <p className="text-xs text-muted-foreground">
                    Regenerate when expired
                  </p>
                </div>
                <Switch
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
                Generate QR Code
              </Button>
            </CardContent>
          </Card>

          {/* QR display */}
          <Card className="print:shadow-none print:border-0">
            <CardHeader className="print:hidden">
              <div className="flex items-center justify-between">
                <CardTitle className="text-base">QR Code</CardTitle>
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
                      Refresh
                    </Button>
                    <Button size="sm" onClick={() => window.print()}>
                      <Printer className="mr-2 h-3 w-3" /> Print
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
                    Configure on the left and click Generate
                  </p>
                </div>
              ) : (
                <div className="text-center space-y-4 py-6">
                  {/* Countdown timer */}
                  <div
                    className={cn(
                      "inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-sm font-medium",
                      isExpired
                        ? "bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-400"
                        : isLow
                          ? "bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-400 animate-pulse"
                          : "bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-400",
                    )}
                  >
                    {isExpired ? (
                      <>
                        <Clock className="h-3.5 w-3.5" />
                        {autoRefresh
                          ? "Refreshing…"
                          : "Expired — click Refresh"}
                      </>
                    ) : (
                      <>
                        <Timer className="h-3.5 w-3.5" />
                        {formatTime(secondsLeft)} remaining
                      </>
                    )}
                  </div>

                  {/* QR Code */}
                  <div
                    className={cn(
                      "inline-block rounded-2xl border-4 p-6 bg-white transition-opacity",
                      isExpired && !autoRefresh
                        ? "opacity-30 border-red-300"
                        : "border-primary",
                    )}
                  >
                    <QRCodeSVG value={result.token} size={280} level="H" />
                  </div>

                  <div>
                    <p className="text-2xl font-bold">
                      {result.branch_name ?? "Attendance Check-in"}
                    </p>
                    {result.shift_name && (
                      <p className="text-sm text-muted-foreground mt-1">
                        Shift: {result.shift_name}
                      </p>
                    )}
                    <p className="mt-2 text-sm text-muted-foreground">
                      Scan this code with the ETHR app to check in.
                      <br />
                      Valid until{" "}
                      <span className="font-medium text-foreground">
                        {new Date(result.expires_at).toLocaleString()}
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
