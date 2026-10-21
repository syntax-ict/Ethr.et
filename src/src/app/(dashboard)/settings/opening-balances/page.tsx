"use client";

import { useRef, useState } from "react";
import { Download, Loader2, Upload } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { PageHeader } from "@/components/shared/page-header";
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
  const { t } = useT();
  const { hasPermission } = usePermissions();

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("opening_balances.title")}
        description={t("opening_balances.description")}
      />
      {hasPermission("leave.adjustBalance") && <BalanceCard kind="leave" />}
      {hasPermission("payroll.manageLoan") && <BalanceCard kind="loans" />}
    </div>
  );
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
        setPreview(null);
        if (fileInput.current) fileInput.current.value = "";
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
            onChange={(e) => onFile(e.target.files?.[0])}
          />
        </div>

        {preview && (
          <div className="space-y-3">
            {preview.errors.map((error) => (
              <p key={error} className="text-sm text-status-error">
                {error}
              </p>
            ))}
            {preview.rows.length > 0 && (
              <>
                <p className="text-sm">
                  {t("opening_balances.summary", undefined, {
                    valid: preview.valid,
                    invalid: preview.invalid,
                  })}
                </p>
                <div className="overflow-x-auto rounded border">
                  <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                      <tr>
                        <th className="p-2">{t("opening_balances.line")}</th>
                        <th className="p-2">
                          {t("opening_balances.employee")}
                        </th>
                        {COLUMNS[kind].map((column) => (
                          <th key={column} className="p-2 font-mono text-xs">
                            {column}
                          </th>
                        ))}
                        <th className="p-2">
                          {t("opening_balances.problems")}
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      {preview.rows.map((row) => (
                        <tr key={row.line} className="border-t align-top">
                          <td className="p-2">{row.line}</td>
                          <td className="p-2">
                            {row.employee_name ?? row.employee_code}
                          </td>
                          {COLUMNS[kind].map((column) => (
                            <td key={column} className="p-2">
                              {String(row[column] ?? "")}
                            </td>
                          ))}
                          <td className="p-2">
                            {row.valid ? (
                              <Badge variant="secondary">
                                {t("opening_balances.ok")}
                              </Badge>
                            ) : (
                              <ul className="text-status-error">
                                {row.errors.map((error) => (
                                  <li key={error}>{error}</li>
                                ))}
                              </ul>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
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
