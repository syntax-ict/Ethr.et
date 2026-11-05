"use client";

import { useRef, useState } from "react";
import { Download, Loader2, Upload, X } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { RoleGate } from "@/components/shared/role-gate";
import { SimpleTable } from "@/components/shared/simple-table";
import {
  fetchBalanceTemplate,
  useCommitBalances,
  usePreviewBalances,
  type BalanceKind,
  type BalancePreview,
} from "@/features/imports/balances-api";
import { apiErrorDetail } from "@/lib/api/error-message";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { saveCsv } from "@/lib/utils/csv-export";
import { toast } from "sonner";

/**
 * Opening balances from the system an organisation used before: approved
 * leave balances and outstanding loans. Template, preview with every row's
 * problems, then commit; nothing changes unless every row is valid.
 */
export default function OpeningBalancesPage() {
  const { hasPermission } = usePermissions();
  const canLeave = hasPermission("leave.adjustBalance");
  const canLoans = hasPermission("payroll.manageLoan");

  const content = (
    <div className="space-y-6">
      {canLeave && <BalanceCard kind="leave" />}
      {canLoans && <BalanceCard kind="loans" />}
    </div>
  );

  // `RoleGate` gates on `can.*` flags, and no flag maps `leave.adjustBalance`.
  // Someone who may adjust leave sees the page; anyone else is gated on the
  // loans flag, so a person with neither ability sees the denial screen
  // instead of an empty page.
  if (canLeave) return content;
  return <RoleGate anyPermission={["manageLoans"]}>{content}</RoleGate>;
}

const COLUMNS: Record<BalanceKind, string[]> = {
  leave: [
    "leave_type_code",
    "year",
    "entitled_days",
    "used_days",
    "carried_days",
  ],
  loans: [
    "principal_etb",
    "remaining_etb",
    "monthly_deduction_etb",
    "start_date",
  ],
};

function BalanceCard({ kind }: { kind: BalanceKind }) {
  const { t } = useT();
  const fileInput = useRef<HTMLInputElement>(null);
  const [preview, setPreview] = useState<BalancePreview | null>(null);
  const previewMut = usePreviewBalances(kind);
  const commitMut = useCommitBalances(kind);

  async function downloadTemplate() {
    try {
      saveCsv(`opening-${kind}-template.csv`, await fetchBalanceTemplate(kind));
    } catch (err) {
      toast.error(apiErrorDetail(err) || t("opening_balances.failed"));
    }
  }

  function onFile(file: File | undefined) {
    if (!file) return;
    previewMut.mutate(file, {
      onSuccess: setPreview,
      onError: (err: unknown) =>
        toast.error(apiErrorDetail(err) || t("opening_balances.failed")),
    });
  }

  function clearPreview() {
    setPreview(null);
    if (fileInput.current) fileInput.current.value = "";
  }

  function commit() {
    if (!preview) return;
    commitMut.mutate(preview.rows, {
      onSuccess: (result) => {
        toast.success(
          t("opening_balances.committed", undefined, {
            created: result.created,
            updated: result.updated,
            skipped: result.skipped,
          }),
        );
        clearPreview();
      },
      onError: (err: unknown) =>
        toast.error(apiErrorDetail(err) || t("opening_balances.failed")),
    });
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">
          {t(`opening_balances.${kind}.title`)}
        </CardTitle>
        <p className="text-sm text-muted-foreground">
          {t(`opening_balances.${kind}.description`)}
        </p>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="outline" size="sm" onClick={downloadTemplate}>
            <Download className="mr-1 h-4 w-4" />
            {t("opening_balances.template")}
          </Button>
          <Button
            variant="outline"
            size="sm"
            onClick={() => fileInput.current?.click()}
            disabled={previewMut.isPending}
          >
            {previewMut.isPending ? (
              <Loader2 className="mr-1 h-4 w-4 animate-spin" />
            ) : (
              <Upload className="mr-1 h-4 w-4" />
            )}
            {t("opening_balances.choose_file")}
          </Button>
          <input
            ref={fileInput}
            type="file"
            accept=".csv,text/csv"
            className="hidden"
            aria-label={t(`opening_balances.${kind}.file_label`)}
            onChange={(e) => {
              onFile(e.target.files?.[0]);
              // A file input only fires change when the chosen path changes,
              // so a corrected file saved under the same name could not be
              // picked again until the page was reloaded.
              e.target.value = "";
            }}
          />
          {preview && (
            <Button variant="ghost" size="sm" onClick={clearPreview}>
              <X className="mr-1 h-4 w-4" />
              {t("opening_balances.clear", "Clear")}
            </Button>
          )}
        </div>

        {preview && (
          <div className="space-y-3">
            {preview.errors.length > 0 && (
              <div
                role="alert"
                className="rounded-lg border border-destructive-edge bg-destructive-soft p-3 text-sm text-destructive-on-soft"
              >
                <ul className="space-y-1">
                  {preview.errors.map((error) => (
                    <li key={error}>{error}</li>
                  ))}
                </ul>
              </div>
            )}
            {preview.rows.length > 0 && (
              <>
                <p className="text-sm">
                  {t("opening_balances.summary", undefined, {
                    valid: preview.valid,
                    invalid: preview.invalid,
                  })}
                </p>
                <SimpleTable
                  mobileCards
                  caption={t(
                    "opening_balances.preview_caption",
                    "Preview of :kind",
                    {
                      kind: t(`opening_balances.${kind}.title`),
                    },
                  )}
                  headers={[
                    t("opening_balances.line"),
                    t("opening_balances.employee"),
                    ...COLUMNS[kind].map((column) => (
                      <span key={column} className="font-mono text-caption">
                        {column}
                      </span>
                    )),
                    t("opening_balances.problems"),
                  ]}
                  rows={preview.rows.map((row) => ({
                    key: String(row.line),
                    className: row.valid ? undefined : "bg-destructive-soft/40",
                    cells: [
                      <span key="line" className="tabular-nums">
                        {row.line}
                      </span>,
                      <span key="emp">
                        {row.employee_name ?? row.employee_code}
                      </span>,
                      ...COLUMNS[kind].map((column) => (
                        <span key={column} className="tabular-nums">
                          {String(row[column] ?? "")}
                        </span>
                      )),
                      row.valid ? (
                        <Badge key="ok" variant="success">
                          {t("opening_balances.ok")}
                        </Badge>
                      ) : (
                        <div
                          key="errors"
                          role="alert"
                          className="rounded-md border border-destructive-edge bg-destructive-soft p-2 text-destructive-on-soft"
                        >
                          <ul className="space-y-0.5">
                            {row.errors.map((error) => (
                              <li key={error}>{error}</li>
                            ))}
                          </ul>
                        </div>
                      ),
                    ],
                  }))}
                />
                <Button
                  onClick={commit}
                  disabled={preview.invalid > 0 || commitMut.isPending}
                >
                  {commitMut.isPending && (
                    <Loader2 className="mr-1 h-4 w-4 animate-spin" />
                  )}
                  {t("opening_balances.commit", undefined, {
                    count: preview.valid,
                  })}
                </Button>
              </>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
