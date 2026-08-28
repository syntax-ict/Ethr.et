"use client";

import { useState } from "react";
import { Loader2, Mail, X, CalendarClock, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import { EmptyState } from "@/components/shared/empty-state";
import { toast } from "sonner";
import { useT } from "@/lib/i18n/useT";
import {
  useDashboardDigests,
  useScheduleDashboardDigest,
  useDeleteDashboardDigest,
  type DigestFrequency,
} from "@/features/dashboard/executive-api";

/**
 * Phase 6.6 — schedule/list/cancel a recurring email KPI summary of the
 * dashboard the caller is currently viewing. `branchPublicId` is only a
 * *default* for an executive-view holder (they can still pick "whole
 * tenant"); a regional-view holder's branch is decided server-side
 * regardless of what's sent — see DashboardDigestController.
 */
export function DashboardDigestDialog({
  open,
  onClose,
  branchPublicId,
}: {
  open: boolean;
  onClose: () => void;
  branchPublicId?: string;
}) {
  const { t } = useT();
  const { data, isLoading } = useDashboardDigests();
  const schedule = useScheduleDashboardDigest();
  const deleteDigest = useDeleteDashboardDigest();

  const [frequency, setFrequency] = useState<DigestFrequency>("weekly");
  const [emailInput, setEmailInput] = useState("");
  const [recipients, setRecipients] = useState<string[]>([]);

  function addEmail() {
    const e = emailInput.trim().toLowerCase();
    if (!e || !e.includes("@")) {
      toast.error(t("digest_dialog.enter_valid_email", "Enter a valid email"));
      return;
    }
    if (recipients.includes(e)) return;
    setRecipients((p) => [...p, e]);
    setEmailInput("");
  }

  function handleSchedule() {
    if (recipients.length === 0) {
      toast.error(
        t("digest_dialog.add_recipient", "Add at least one recipient"),
      );
      return;
    }
    schedule.mutate(
      { frequency, recipients, branch_public_id: branchPublicId },
      {
        onSuccess: () => {
          toast.success(t("digest_dialog.scheduled", "Digest scheduled"));
          setRecipients([]);
        },
        onError: () =>
          toast.error(
            t("digest_dialog.schedule_failed", "Could not schedule digest"),
          ),
      },
    );
  }

  function handleDelete(publicId: string) {
    deleteDigest.mutate(publicId, {
      onSuccess: () =>
        toast.success(t("digest_dialog.cancelled", "Digest cancelled")),
    });
  }

  const digests = data?.digests ?? [];

  return (
    <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>
            {t("digest_dialog.title", "Email dashboard digest")}
          </DialogTitle>
          <DialogDescription>
            {t(
              "digest_dialog.description",
              "Get a recurring summary of headcount, attendance, payroll, and compliance flags by email.",
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div>
            <Label>{t("digest_dialog.frequency", "Frequency")}</Label>
            <Select
              value={frequency}
              onValueChange={(v) => setFrequency(v as DigestFrequency)}
            >
              <SelectTrigger className="mt-1">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="daily">
                  {t("digest_dialog.daily_6am", "Daily, 6am")}
                </SelectItem>
                <SelectItem value="weekly">
                  {t("digest_dialog.weekly_mon_6am", "Weekly, Monday 6am")}
                </SelectItem>
                <SelectItem value="monthly">
                  {t("digest_dialog.monthly_1st_6am", "Monthly, 1st, 6am")}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div>
            <Label>{t("digest_dialog.recipients", "Recipients")}</Label>
            <div className="mt-1 flex gap-2">
              <Input
                type="email"
                value={emailInput}
                onChange={(e) => setEmailInput(e.target.value)}
                onKeyDown={(e) =>
                  e.key === "Enter" && (e.preventDefault(), addEmail())
                }
                placeholder="ceo@example.com"
              />
              <Button type="button" variant="outline" onClick={addEmail}>
                {t("digest_dialog.add", "Add")}
              </Button>
            </div>
            {recipients.length > 0 && (
              <div className="mt-2 flex flex-wrap gap-1">
                {recipients.map((r) => (
                  <Badge
                    key={r}
                    variant="outline"
                    className="flex items-center gap-1 text-xs"
                  >
                    <Mail className="h-3 w-3" /> {r}
                    <button
                      type="button"
                      onClick={() =>
                        setRecipients((p) => p.filter((x) => x !== r))
                      }
                      className="ml-1 hover:text-destructive"
                    >
                      <X className="h-3 w-3" />
                    </button>
                  </Badge>
                ))}
              </div>
            )}
            <Button
              className="mt-3 w-full"
              onClick={handleSchedule}
              disabled={schedule.isPending || recipients.length === 0}
            >
              {schedule.isPending && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              {t("digest_dialog.schedule", "Schedule digest")}
            </Button>
          </div>

          <div className="border-t pt-3">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              {t("digest_dialog.active_digests", "Active digests")}
            </p>
            {isLoading ? (
              <Skeleton className="h-16 w-full" />
            ) : digests.length === 0 ? (
              <EmptyState
                icon={Mail}
                title={t("digest_dialog.no_digests", "No digests scheduled")}
                description={t(
                  "digest_dialog.no_digests_desc",
                  "Schedule one above to get regular summaries by email.",
                )}
              />
            ) : (
              <div className="space-y-2">
                {digests.map((d) => (
                  <Card key={d.public_id}>
                    <CardContent className="flex items-center justify-between gap-2 p-3">
                      <div className="min-w-0">
                        <div className="flex items-center gap-2">
                          <Badge
                            variant="outline"
                            className="text-[10px] capitalize"
                          >
                            {d.frequency}
                          </Badge>
                          {d.branch_name && (
                            <span className="text-xs text-muted-foreground">
                              {d.branch_name}
                            </span>
                          )}
                        </div>
                        <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                          <span className="flex items-center gap-1">
                            <Mail className="h-3 w-3" /> {d.recipients.length}
                          </span>
                          <span className="flex items-center gap-1">
                            <CalendarClock className="h-3 w-3" />{" "}
                            {new Date(d.next_run_at).toLocaleString()}
                          </span>
                        </div>
                      </div>
                      <Button
                        variant="ghost"
                        size="icon"
                        className="shrink-0 text-muted-foreground hover:text-destructive"
                        onClick={() => handleDelete(d.public_id)}
                        disabled={deleteDigest.isPending}
                        aria-label={t("digest_dialog.cancel", "Cancel")}
                      >
                        <Trash2 className="h-4 w-4" />
                      </Button>
                    </CardContent>
                  </Card>
                ))}
              </div>
            )}
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t("common.close", "Close")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
