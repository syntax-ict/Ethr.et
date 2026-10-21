"use client";

import { useState } from "react";
import { FileSignature, Loader2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { RoleGate } from "@/components/shared/role-gate";
import {
  useIssueLetter,
  useLetterRequests,
  useRejectLetter,
  type LetterRequest,
} from "@/features/letters/api";
import { apiErrorDetail } from "@/lib/api/error-message";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

/**
 * Employment letters employees asked for. Issuing generates the letter with
 * a reference; declining needs a reason the employee sees.
 */
export default function LetterRequestsPage() {
  const { t } = useT();
  const [status, setStatus] = useState<LetterRequest["status"]>("pending");
  const query = useLetterRequests(status);

  return (
    <RoleGate anyPermission={["manageEmployees"]}>
      <div className="space-y-6">
        <PageHeader
          title={t("letters.hr_title")}
          description={t("letters.hr_description")}
        />
        <Tabs
          value={status}
          onValueChange={(v) => setStatus(v as LetterRequest["status"])}
        >
          <TabsList>
            <TabsTrigger value="pending">
              {t("letters.status_pending")}
            </TabsTrigger>
            <TabsTrigger value="issued">
              {t("letters.status_issued")}
            </TabsTrigger>
            <TabsTrigger value="rejected">
              {t("letters.status_rejected")}
            </TabsTrigger>
          </TabsList>
        </Tabs>
        <Card>
          <CardContent className="p-0">
            <QueryBoundary
              query={query}
              isEmpty={(rows) => rows.length === 0}
              empty={
                <EmptyState
                  icon={FileSignature}
                  title={t("letters.hr_empty")}
                />
              }
            >
              {(rows) => (
                <ul className="divide-y">
                  {rows.map((letter) => (
                    <LetterRow key={letter.public_id} letter={letter} />
                  ))}
                </ul>
              )}
            </QueryBoundary>
          </CardContent>
        </Card>
      </div>
    </RoleGate>
  );
}

function LetterRow({ letter }: { letter: LetterRequest }) {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  const issue = useIssueLetter();
  const reject = useRejectLetter();
  const [rejecting, setRejecting] = useState(false);
  const [reason, setReason] = useState("");

  const fail = (err: unknown) =>
    toast.error(apiErrorDetail(err) || t("letters.decide_failed"));

  return (
    <li className="flex flex-wrap items-center gap-3 p-4">
      <div className="min-w-0 flex-1">
        <p className="text-sm font-medium">{letter.employee?.name}</p>
        <p className="text-xs text-muted-foreground">
          {letter.purpose}
          {letter.addressed_to ? ` · ${letter.addressed_to}` : ""}
          {letter.created_at ? ` · ${formatDate(letter.created_at)}` : ""}
        </p>
      </div>
      {letter.include_salary && (
        <Badge variant="outline">{t("letters.with_salary")}</Badge>
      )}
      <Badge variant="outline">
        {letter.language === "am" ? "አማርኛ" : "English"}
      </Badge>
      {letter.reference && (
        <Badge variant="secondary">{letter.reference}</Badge>
      )}
      {letter.status === "pending" && (
        <div className="flex gap-2">
          <Button
            size="sm"
            disabled={issue.isPending}
            onClick={() =>
              issue.mutate(letter.public_id, {
                onSuccess: () => toast.success(t("letters.issued_toast")),
                onError: fail,
              })
            }
          >
            {issue.isPending && (
              <Loader2 className="mr-1 h-4 w-4 animate-spin" />
            )}
            {t("letters.issue")}
          </Button>
          <Button size="sm" variant="ghost" onClick={() => setRejecting(true)}>
            {t("letters.decline")}
          </Button>
        </div>
      )}
      <Dialog open={rejecting} onOpenChange={setRejecting}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {t("letters.decline_title", undefined, {
                name: letter.employee?.name ?? "",
              })}
            </DialogTitle>
          </DialogHeader>
          <Label htmlFor={`reason-${letter.public_id}`}>
            {t("letters.reason")}
          </Label>
          <Input
            id={`reason-${letter.public_id}`}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
          <DialogFooter>
            <Button
              variant="destructive"
              disabled={reason.trim().length < 3 || reject.isPending}
              onClick={() =>
                reject.mutate(
                  { publicId: letter.public_id, reason: reason.trim() },
                  { onSuccess: () => setRejecting(false), onError: fail },
                )
              }
            >
              {t("letters.decline")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </li>
  );
}
