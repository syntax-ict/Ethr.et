"use client";

import { Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Skeleton } from "@/components/ui/skeleton";
import { useT } from "@/lib/i18n/useT";
import { usePayDifferences } from "../api";
import {
  KindLabel,
  PayDifferenceFlags,
  SignedMoney,
  usePayDifferenceMonth,
} from "./pay-difference-parts";

/**
 * What the server asked before it would approve: acknowledge the open pay
 * differences by count, or wait while some are still being worked out.
 *
 * `unacknowledged` lists the open differences and sends the count back;
 * `pending` has nothing to confirm yet, only a message.
 */
export function PayDifferencesApprovalDialog({
  mode,
  count,
  loading,
  onConfirm,
  onClose,
}: {
  mode: "unacknowledged" | "pending" | null;
  count: number;
  loading: boolean;
  onConfirm: () => void;
  onClose: () => void;
}) {
  const { t } = useT();
  const month = usePayDifferenceMonth();
  const list = usePayDifferences({ status: "open" }, mode === "unacknowledged");
  const rows = list.data?.data ?? [];

  return (
    <Dialog open={mode !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-2xl">
        {mode === "pending" ? (
          <>
            <DialogHeader>
              <DialogTitle>{t("pay_differences.pending_title")}</DialogTitle>
              <DialogDescription>
                {t("pay_differences.pending_message")}
              </DialogDescription>
            </DialogHeader>
            <DialogFooter>
              <Button type="button" onClick={onClose}>
                {t("pay_differences.close")}
              </Button>
            </DialogFooter>
          </>
        ) : (
          <>
            <DialogHeader>
              <DialogTitle>
                {t("pay_differences.approve_title", undefined, { count })}
              </DialogTitle>
              <DialogDescription>
                {t("pay_differences.approve_hint")}
              </DialogDescription>
            </DialogHeader>
            <div className="max-h-80 overflow-y-auto rounded-md border border-border">
              {list.isLoading ? (
                <div className="space-y-2 p-3">
                  <Skeleton className="h-4 w-full" />
                  <Skeleton className="h-4 w-3/4" />
                </div>
              ) : (
                <ul className="divide-y divide-border">
                  {rows.map((d) => (
                    <li key={d.public_id} className="px-3 py-2 text-sm">
                      <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <span className="font-medium">{d.employee_name}</span>
                        <span className="text-muted-foreground">
                          {month(d)} &middot; <KindLabel kind={d.kind} />
                        </span>
                      </div>
                      <div className="mt-0.5 flex gap-4 tabular-nums text-muted-foreground">
                        <span>
                          {t("pay_differences.col_gross")}{" "}
                          <SignedMoney cents={d.gross_cents} />
                        </span>
                        <span>
                          {t("pay_differences.col_net")}{" "}
                          <SignedMoney cents={d.net_cents} />
                        </span>
                      </div>
                      <PayDifferenceFlags difference={d} />
                    </li>
                  ))}
                </ul>
              )}
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={onClose}>
                {t("common.cancel")}
              </Button>
              <Button type="button" onClick={onConfirm} disabled={loading}>
                {loading && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                {t("pay_differences.approve_confirm", undefined, { count })}
              </Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}
