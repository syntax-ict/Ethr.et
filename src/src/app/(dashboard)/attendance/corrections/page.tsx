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
import { PageHeader } from "@/components/shared/page-header";
import { StatusBadge } from "@/components/shared/status-badge";
import { SimpleTable } from "@/components/shared/simple-table";
import { EmptyState } from "@/components/shared/empty-state";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";

import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

interface Correction {
  public_id: string;
  date: string;
  original_check_in: string | null;
  original_check_out: string | null;
  corrected_check_in: string | null;
  corrected_check_out: string | null;
  reason: string;
  status: string;
  created_at: string;
  employee?: { name: string; employee_code?: string };
}

export default function CorrectionsPage() {
  const { t } = useT();
  const { isSupervisor } = usePermissions();
  const [requestOpen, setRequestOpen] = useState(false);

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

      <Tabs defaultValue="mine">
        <TabsList>
          <TabsTrigger value="mine">
            {t("attendance.corrections.my_requests")}
          </TabsTrigger>
          {isSupervisor && (
            <TabsTrigger value="pending">
              {t("attendance.corrections.pending_reviews")}
            </TabsTrigger>
          )}
        </TabsList>

        <TabsContent value="mine" className="mt-4">
          <MyRequestsTab />
        </TabsContent>
        {isSupervisor && (
          <TabsContent value="pending" className="mt-4">
            <PendingReviewsTab />
          </TabsContent>
        )}
      </Tabs>

      <RequestDialog open={requestOpen} onClose={() => setRequestOpen(false)} />
    </div>
  );
}

// ── MY REQUESTS ────────────────────────────────────────────────

function MyRequestsTab() {
  const { t } = useT();
  const { data, isLoading } = useQuery({
    queryKey: ["corrections", "mine"],
    queryFn: async () => (await apiClient.get("/attendance/corrections")).data,
  });

  const corrections: Correction[] = data?.data ?? [];

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
            t("common.date"),
            t("attendance.corrections.corrected_in"),
            t("attendance.corrections.corrected_out"),
            t("attendance.corrections.reason"),
            t("common.status"),
          ]}
          colClassName={[
            "",
            "hidden sm:table-cell",
            "hidden sm:table-cell",
            "hidden max-w-xs md:table-cell",
            "",
          ]}
          rows={corrections.map((c) => ({
            key: c.public_id,
            cells: [
              <span key="d" className="font-medium">
                {c.date}
              </span>,
              <span key="in" className="text-muted-foreground">
                {c.corrected_check_in ?? "—"}
              </span>,
              <span key="out" className="text-muted-foreground">
                {c.corrected_check_out ?? "—"}
              </span>,
              <span
                key="r"
                className="block max-w-[200px] truncate text-muted-foreground"
              >
                {c.reason}
              </span>,
              <StatusBadge key="s" status={c.status} />,
            ],
          }))}
        />
      </CardContent>
    </Card>
  );
}

// ── PAYROLL IMPACT BADGE ────────────────────────────────────────

type PayrollImpact = {
  original_hours: number;
  proposed_hours: number;
  difference_minutes: number;
  estimated_impact_cents: number;
  hourly_rate_cents: number;
};

function PayrollImpactBadge({ correctionId }: { correctionId: string }) {
  const { data, isLoading } = useQuery<PayrollImpact>({
    queryKey: ["correction-impact", correctionId],
    queryFn: async () => {
      const { data } = await apiClient.get(
        `/attendance/corrections/${correctionId}/payroll-impact`,
      );
      return data as PayrollImpact;
    },
    staleTime: 60_000,
  });

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

// ── PENDING REVIEWS (supervisor) ───────────────────────────────

function PendingReviewsTab() {
  const { t } = useT();
  const queryClient = useQueryClient();
  const [rejectFor, setRejectFor] = useState<Correction | null>(null);
  const [rejectReason, setRejectReason] = useState("");

  const { data, isLoading } = useQuery({
    queryKey: ["corrections", "pending"],
    queryFn: async () =>
      (await apiClient.get("/attendance/corrections/pending")).data,
  });

  const approveMut = useMutation({
    mutationFn: async (publicId: string) => {
      const { data } = await apiClient.put(
        `/attendance/corrections/${publicId}/approve`,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["corrections"] });
      queryClient.invalidateQueries({ queryKey: ["attendance"] });
      toast.success(t("attendance.corrections.approved"));
    },
    onError: () => toast.error(t("attendance.corrections.approve_failed")),
  });

  const rejectMut = useMutation({
    mutationFn: async ({
      publicId,
      reason,
    }: {
      publicId: string;
      reason: string;
    }) => {
      const { data } = await apiClient.put(
        `/attendance/corrections/${publicId}/reject`,
        { reason },
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["corrections"] });
      toast.success(t("attendance.corrections.rejected"));
      setRejectFor(null);
      setRejectReason("");
    },
    onError: () => toast.error(t("attendance.corrections.reject_failed")),
  });

  const items: Correction[] = data?.data ?? [];

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
                        {c.employee?.name ?? "Employee"}
                      </p>
                      {c.employee?.employee_code && (
                        <Badge
                          variant="outline"
                          className="text-[10px] font-mono"
                        >
                          {c.employee.employee_code}
                        </Badge>
                      )}
                      <Badge variant="outline" className="text-[10px]">
                        {c.date}
                      </Badge>
                    </div>
                    <div className="mt-2 grid gap-2 text-xs sm:grid-cols-2">
                      <div className="flex items-baseline gap-2">
                        <span className="text-muted-foreground">
                          {t("attendance.corrections.original")}:
                        </span>
                        <span className="font-mono">
                          {c.original_check_in ?? "—"} →{" "}
                          {c.original_check_out ?? "—"}
                        </span>
                      </div>
                      <div className="flex items-baseline gap-2">
                        <span className="text-muted-foreground">
                          {t("attendance.corrections.requested")}:
                        </span>
                        <span className="font-mono font-medium text-foreground">
                          {c.corrected_check_in ?? "—"} →{" "}
                          {c.corrected_check_out ?? "—"}
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
                  <div className="flex gap-2 sm:flex-col sm:items-stretch">
                    <Button
                      size="sm"
                      variant="outline"
                      className="text-success-on-soft hover:bg-success-soft"
                      onClick={() => approveMut.mutate(c.public_id)}
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
                </div>
              </CardContent>
            </Card>
          );
        })}
      </div>

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
            rows={3}
            required
          />
          <DialogFooter>
            <Button variant="outline" onClick={() => setRejectFor(null)}>
              {t("common.cancel")}
            </Button>
            <Button
              variant="destructive"
              onClick={() =>
                rejectFor &&
                rejectMut.mutate({
                  publicId: rejectFor.public_id,
                  reason: rejectReason,
                })
              }
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

function RequestDialog({
  open,
  onClose,
}: {
  open: boolean;
  onClose: () => void;
}) {
  const { t } = useT();
  const queryClient = useQueryClient();
  const [form, setForm] = useState({
    date: "",
    corrected_check_in: "",
    corrected_check_out: "",
    reason: "",
  });

  const submit = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post("/attendance/corrections", form);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["corrections"] });
      toast.success(t("attendance.corrections.submitted"));
      onClose();
      setForm({
        date: "",
        corrected_check_in: "",
        corrected_check_out: "",
        reason: "",
      });
    },
    onError: () => toast.error(t("attendance.corrections.submit_failed")),
  });

  return (
    <Dialog open={open} onOpenChange={onClose}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t("attendance.corrections.request_title")}</DialogTitle>
        </DialogHeader>
        <form
          onSubmit={(e) => {
            e.preventDefault();
            submit.mutate();
          }}
          className="space-y-4"
        >
          <div>
            <Label>{t("attendance.date_required")}</Label>
            <DualCalendarDateInput
              value={form.date}
              onChange={(v) => setForm((p) => ({ ...p, date: v }))}
              required
              className="mt-1"
            />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <Label htmlFor="correct-check-in">
                {t("attendance.corrections.correct_check_in")}
              </Label>
              <Input
                id="correct-check-in"
                type="time"
                value={form.corrected_check_in}
                onChange={(e) =>
                  setForm((p) => ({ ...p, corrected_check_in: e.target.value }))
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
                value={form.corrected_check_out}
                onChange={(e) =>
                  setForm((p) => ({
                    ...p,
                    corrected_check_out: e.target.value,
                  }))
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
            <Button type="submit" disabled={submit.isPending}>
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
