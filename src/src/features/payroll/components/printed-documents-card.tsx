"use client";

import { useState } from "react";
import { FileText, Loader2, Save } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { useSettings, useUpdateSettings } from "@/features/settings/api";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";
import { ErrorState } from "@/components/shared/error-state";

interface PrintedForm {
  letterhead_address: string;
  letterhead_phone: string;
  letterhead_email: string;
  letterhead_po_box: string;
}

/**
 * What the top of every printed document says about the organisation, under
 * its name and logo: payslips, employment letters, statutory schedules and
 * report exports. A blank field leaves its line out of the page.
 */
export function PrintedDocumentsCard() {
  const { t } = useT();
  const { data, isLoading, isError, refetch } = useSettings();
  const save = useUpdateSettings();

  const persisted: PrintedForm = {
    letterhead_address: data?.documents?.letterhead_address ?? "",
    letterhead_phone: data?.documents?.letterhead_phone ?? "",
    letterhead_email: data?.documents?.letterhead_email ?? "",
    letterhead_po_box: data?.documents?.letterhead_po_box ?? "",
  };
  const [edits, setEdits] = useState<PrintedForm | null>(null);
  const form = edits ?? persisted;
  const isDirty =
    edits !== null &&
    (Object.keys(form) as (keyof PrintedForm)[]).some(
      (k) => form[k] !== persisted[k],
    );

  function update(key: keyof PrintedForm, value: string) {
    setEdits({ ...form, [key]: value });
  }

  function submit() {
    save.mutate(
      {
        letterhead_address: form.letterhead_address.trim() || null,
        letterhead_phone: form.letterhead_phone.trim() || null,
        letterhead_email: form.letterhead_email.trim() || null,
        letterhead_po_box: form.letterhead_po_box.trim() || null,
      },
      {
        onSuccess: () => {
          setEdits(null);
          toast.success(t("printed_docs.saved"));
        },
        onError: (err) =>
          toast.error(apiErrorMessage(err, t("printed_docs.save_failed"))),
      },
    );
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle className="flex items-center gap-2 text-base">
            <FileText className="h-4 w-4" /> {t("printed_docs.title")}
          </CardTitle>
          <p className="mt-1 text-sm text-muted-foreground">
            {t("printed_docs.desc")}
          </p>
        </div>
        {isDirty && !isError && (
          <Button onClick={submit} disabled={save.isPending} size="sm">
            {save.isPending ? (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            ) : (
              <Save className="mr-2 h-4 w-4" />
            )}
            {t("common.save")}
          </Button>
        )}
      </CardHeader>
      <CardContent className="grid gap-4 sm:grid-cols-2">
        {isLoading ? (
          <>
            <Skeleton className="h-16" />
            <Skeleton className="h-16" />
          </>
        ) : isError ? (
          // Never show the form over a failed load: its empty fields would be
          // saved over the organisation's real details.
          <ErrorState
            compact
            className="sm:col-span-2"
            onRetry={() => refetch()}
          />
        ) : (
          <>
            <div className="sm:col-span-2">
              <Label htmlFor="letterhead_address">
                {t("printed_docs.address")}
              </Label>
              <Input
                id="letterhead_address"
                maxLength={200}
                value={form.letterhead_address}
                onChange={(e) => update("letterhead_address", e.target.value)}
                className="mt-1"
              />
            </div>
            <div>
              <Label htmlFor="letterhead_po_box">
                {t("printed_docs.po_box")}
              </Label>
              <Input
                id="letterhead_po_box"
                maxLength={40}
                value={form.letterhead_po_box}
                onChange={(e) => update("letterhead_po_box", e.target.value)}
                className="mt-1"
              />
            </div>
            <div>
              <Label htmlFor="letterhead_phone">
                {t("printed_docs.phone")}
              </Label>
              <Input
                id="letterhead_phone"
                type="tel"
                maxLength={40}
                value={form.letterhead_phone}
                onChange={(e) => update("letterhead_phone", e.target.value)}
                className="mt-1"
              />
            </div>
            <div className="sm:col-span-2">
              <Label htmlFor="letterhead_email">
                {t("printed_docs.email")}
              </Label>
              <Input
                id="letterhead_email"
                type="email"
                maxLength={120}
                value={form.letterhead_email}
                onChange={(e) => update("letterhead_email", e.target.value)}
                className="mt-1"
              />
            </div>
          </>
        )}
      </CardContent>
    </Card>
  );
}
