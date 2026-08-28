"use client";

import { useCallback, useRef, useState } from "react";
import Link from "next/link";
import {
  Upload,
  FileSpreadsheet,
  CheckCircle2,
  XCircle,
  AlertTriangle,
  Loader2,
  ArrowLeft,
  Download,
  Trash2,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { PageHeader } from "@/components/shared/page-header";
import { SimpleTable } from "@/components/shared/simple-table";

import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import { cn } from "@/lib/utils";

interface PreviewRow {
  employee_code: string;
  date: string;
  check_in_time: string;
  check_out_time?: string;
  line: number;
  valid: boolean;
  errors: string[];
}

interface PreviewResult {
  rows: PreviewRow[];
  valid: number;
  invalid: number;
  errors: string[];
}

type Step = "upload" | "preview" | "importing" | "done";

export default function AttendanceImportPage() {
  const { t } = useT();
  const fileRef = useRef<HTMLInputElement>(null);
  const [step, setStep] = useState<Step>("upload");
  const [dragOver, setDragOver] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [preview, setPreview] = useState<PreviewResult | null>(null);
  const [fileName, setFileName] = useState("");
  const [importResult, setImportResult] = useState<{
    created: number;
    skipped: number;
    errors: string[];
  } | null>(null);

  const handleFile = useCallback(
    async (file: File) => {
      if (!file.name.endsWith(".csv") && !file.name.endsWith(".txt")) {
        toast.error(t("attendance.import_page.upload_csv_or_txt"));
        return;
      }
      if (file.size > 5 * 1024 * 1024) {
        toast.error(t("attendance.import_page.file_too_large"));
        return;
      }

      setFileName(file.name);
      setUploading(true);

      try {
        const formData = new FormData();
        formData.append("file", file);

        const { data } = await apiClient.post(
          "/attendance/import/preview",
          formData,
          {
            headers: { "Content-Type": "multipart/form-data" },
          },
        );

        if (data.errors?.length) {
          toast.error(data.errors.join(", "));
          setUploading(false);
          return;
        }

        setPreview(data as PreviewResult);
        setStep("preview");
      } catch (err: unknown) {
        const e = err as { response?: { data?: { detail?: string } } };
        toast.error(
          e.response?.data?.detail ?? t("attendance.import_page.parse_failed"),
        );
      } finally {
        setUploading(false);
      }
    },
    [t],
  );

  function handleDrop(e: React.DragEvent) {
    e.preventDefault();
    setDragOver(false);
    const file = e.dataTransfer.files[0];
    if (file) handleFile(file);
  }

  function handleInputChange(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (file) handleFile(file);
    e.target.value = "";
  }

  async function downloadTemplate() {
    try {
      const { data } = await apiClient.post("/attendance/import/template");
      const blob = new Blob([data.template], { type: "text/csv" });
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = "attendance_import_template.csv";
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      toast.error(t("attendance.import_page.template_download_failed"));
    }
  }

  async function commitImport() {
    if (!preview) return;

    const validRows = preview.rows.filter((r) => r.valid);
    if (validRows.length === 0) {
      toast.error(t("attendance.import_page.no_valid_rows"));
      return;
    }

    setStep("importing");
    const importKey = `import-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;

    try {
      const { data } = await apiClient.post("/attendance/import/commit", {
        import_key: importKey,
        rows: validRows.map((r) => ({
          employee_code: r.employee_code,
          date: r.date,
          check_in: r.check_in_time,
          check_out: r.check_out_time || null,
        })),
      });

      setImportResult(data);
      setStep("done");
      toast.success(
        `${t("attendance.import_page.imported")} ${data.created} ${t("attendance.import_page.records")}`,
      );
    } catch (err: unknown) {
      const e = err as { response?: { data?: { detail?: string } } };
      toast.error(
        e.response?.data?.detail ?? t("attendance.import_page.import_failed"),
      );
      setStep("preview");
    }
  }

  function reset() {
    setStep("upload");
    setPreview(null);
    setFileName("");
    setImportResult(null);
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("attendance.import_page.title")}
        description={t("attendance.import_page.description")}
        actions={
          <div className="flex gap-2">
            <Button variant="outline" size="sm" onClick={downloadTemplate}>
              <Download className="mr-2 h-4 w-4" />{" "}
              {t("attendance.import_page.download_template")}
            </Button>
            <Button asChild variant="ghost" size="sm">
              <Link href="/attendance">
                <ArrowLeft className="mr-2 h-4 w-4" /> {t("common.back")}
              </Link>
            </Button>
          </div>
        }
      />

      {step === "upload" && (
        <Card>
          <CardContent className="p-8">
            <div
              onDragOver={(e) => {
                e.preventDefault();
                setDragOver(true);
              }}
              onDragLeave={() => setDragOver(false)}
              onDrop={handleDrop}
              onClick={() => fileRef.current?.click()}
              className={cn(
                "flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed p-12 transition-colors",
                dragOver
                  ? "border-primary bg-primary/5"
                  : "border-muted-foreground/25 hover:border-primary/50 hover:bg-muted/50",
              )}
            >
              {uploading ? (
                <>
                  <Loader2 className="h-12 w-12 animate-spin text-primary" />
                  <p className="mt-4 text-sm font-medium">
                    {t("attendance.import_page.parsing")} {fileName}…
                  </p>
                </>
              ) : (
                <>
                  <Upload className="h-12 w-12 text-muted-foreground" />
                  <p className="mt-4 text-sm font-medium text-foreground">
                    {t("attendance.import_page.drop_hint")}
                  </p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {t("attendance.import_page.supports_hint")}
                  </p>
                </>
              )}
              <input
                ref={fileRef}
                type="file"
                accept=".csv,.txt"
                onChange={handleInputChange}
                className="hidden"
              />
            </div>

            <div className="mt-6 rounded-lg bg-muted/50 p-4">
              <h3 className="text-sm font-semibold text-foreground">
                {t("attendance.import_page.expected_format")}
              </h3>
              <code className="mt-2 block rounded bg-background p-3 text-xs font-mono text-muted-foreground">
                employee_code,date,check_in_time,check_out_time{"\n"}
                EMP001,2026-07-01,08:30,17:00{"\n"}
                EMP002,2026-07-01,09:00,17:30
              </code>
              <ul className="mt-3 space-y-1 text-xs text-muted-foreground">
                <li>
                  - <strong>employee_code</strong>:{" "}
                  {t("attendance.import_page.field_employee_code")}
                </li>
                <li>
                  - <strong>date</strong>:{" "}
                  {t("attendance.import_page.field_date")}
                </li>
                <li>
                  - <strong>check_in_time</strong>:{" "}
                  {t("attendance.import_page.field_check_in")}
                </li>
                <li>
                  - <strong>check_out_time</strong>:{" "}
                  {t("attendance.import_page.field_check_out")}
                </li>
              </ul>
            </div>
          </CardContent>
        </Card>
      )}

      {step === "preview" && preview && (
        <>
          <div className="grid gap-4 sm:grid-cols-3">
            <SummaryCard
              icon={FileSpreadsheet}
              label={t("attendance.import_page.total_rows")}
              value={preview.rows.length}
              color="text-foreground"
            />
            <SummaryCard
              icon={CheckCircle2}
              label={t("attendance.import_page.valid")}
              value={preview.valid}
              color="text-success"
            />
            <SummaryCard
              icon={XCircle}
              label={t("attendance.import_page.invalid")}
              value={preview.invalid}
              color="text-destructive"
            />
          </div>

          <Card>
            <CardHeader className="flex-row items-center justify-between space-y-0 pb-4">
              <CardTitle className="text-base">
                {t("attendance.import_page.preview_label")}: {fileName}
              </CardTitle>
              <div className="flex gap-2">
                <Button variant="ghost" size="sm" onClick={reset}>
                  <Trash2 className="mr-2 h-4 w-4" />{" "}
                  {t("attendance.import_page.discard")}
                </Button>
                <Button
                  size="sm"
                  onClick={commitImport}
                  disabled={preview.valid === 0}
                >
                  <Upload className="mr-2 h-4 w-4" />
                  {t("attendance.import_page.import_n_records_prefix")}{" "}
                  {preview.valid} {t("attendance.import_page.records")}
                </Button>
              </div>
            </CardHeader>
            <CardContent className="p-0">
              <SimpleTable
                caption={t("attendance.import_page.title", "Import preview")}
                headers={[
                  t("attendance.import_page.line"),
                  t("common.status"),
                  t("attendance.employee"),
                  t("common.date"),
                  t("attendance.in"),
                  t("attendance.out"),
                  t("attendance.import_page.issues"),
                ]}
                rows={preview.rows.map((row, i) => ({
                  key: String(i),
                  className: !row.valid ? "bg-destructive-soft" : undefined,
                  cells: [
                    <span key="l" className="text-xs text-muted-foreground">
                      {row.line}
                    </span>,
                    row.valid ? (
                      <CheckCircle2 key="v" className="h-4 w-4 text-success" />
                    ) : (
                      <XCircle key="v" className="h-4 w-4 text-destructive" />
                    ),
                    <span key="e" className="font-mono">
                      {row.employee_code}
                    </span>,
                    row.date,
                    row.check_in_time,
                    <span key="o" className="text-muted-foreground">
                      {row.check_out_time || "—"}
                    </span>,
                    row.errors.length > 0 ? (
                      <div key="err" className="space-y-0.5">
                        {row.errors.map((err, j) => (
                          <p key={j} className="text-xs text-destructive">
                            {err}
                          </p>
                        ))}
                      </div>
                    ) : null,
                  ],
                }))}
              />
            </CardContent>
          </Card>

          {preview.invalid > 0 && (
            <div className="flex items-start gap-3 rounded-lg border border-warning-edge bg-warning-soft p-4">
              <AlertTriangle className="mt-0.5 h-5 w-5 text-warning" />
              <div>
                <p className="text-sm font-medium text-warning-on-soft">
                  {preview.invalid}{" "}
                  {preview.invalid > 1
                    ? t("attendance.import_page.rows_will_be_skipped")
                    : t("attendance.import_page.row_will_be_skipped")}
                </p>
                <p className="mt-1 text-xs text-warning-on-soft">
                  {t("attendance.import_page.skip_hint")}
                </p>
              </div>
            </div>
          )}
        </>
      )}

      {step === "importing" && (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-16">
            <Loader2 className="h-12 w-12 animate-spin text-primary" />
            <p className="mt-4 text-sm font-medium">
              {t("attendance.import_page.importing")}
            </p>
            <p className="mt-1 text-xs text-muted-foreground">
              {t("attendance.import_page.large_file_hint")}
            </p>
          </CardContent>
        </Card>
      )}

      {step === "done" && importResult && (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-16">
            <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-success-soft">
              <CheckCircle2 className="h-8 w-8 text-success" />
            </div>
            <h2 className="mt-4 text-lg font-semibold text-foreground">
              {t("attendance.import_page.complete")}
            </h2>
            <div className="mt-4 flex gap-4">
              <Badge
                variant="outline"
                className="px-3 py-1.5 text-sm bg-success-soft"
              >
                {importResult.created} {t("attendance.import_page.created")}
              </Badge>
              {importResult.skipped > 0 && (
                <Badge
                  variant="outline"
                  className="px-3 py-1.5 text-sm bg-warning-soft"
                >
                  {importResult.skipped}{" "}
                  {t("attendance.import_page.skipped_duplicates")}
                </Badge>
              )}
            </div>
            {importResult.errors.length > 0 && (
              <div className="mt-4 w-full max-w-md rounded-lg bg-destructive-soft p-3">
                {importResult.errors.map((err, i) => (
                  <p key={i} className="text-xs text-destructive">
                    {err}
                  </p>
                ))}
              </div>
            )}
            <div className="mt-6 flex gap-3">
              <Button variant="outline" onClick={reset}>
                <Upload className="mr-2 h-4 w-4" />{" "}
                {t("attendance.import_page.import_another")}
              </Button>
              <Button asChild>
                <Link href="/attendance">
                  {t("attendance.import_page.view_attendance")}
                </Link>
              </Button>
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
}

function SummaryCard({
  icon: Icon,
  label,
  value,
  color,
}: {
  icon: React.ComponentType<{ className?: string }>;
  label: string;
  value: number;
  color: string;
}) {
  return (
    <Card>
      <CardContent className="flex items-center gap-3 p-4">
        <Icon className={cn("h-8 w-8", color)} />
        <div>
          <p className="text-xs text-muted-foreground">{label}</p>
          <p className={cn("text-2xl font-bold", color)}>{value}</p>
        </div>
      </CardContent>
    </Card>
  );
}
