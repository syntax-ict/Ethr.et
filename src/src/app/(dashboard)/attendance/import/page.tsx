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
import { EmptyState } from "@/components/shared/empty-state";
import { apiClient } from "@/api/client";
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

  const handleFile = useCallback(async (file: File) => {
    if (!file.name.endsWith(".csv") && !file.name.endsWith(".txt")) {
      toast.error("Please upload a .csv or .txt file");
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      toast.error("File must be under 5 MB");
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
      toast.error(e.response?.data?.detail ?? "Failed to parse file");
    } finally {
      setUploading(false);
    }
  }, []);

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
      toast.error("Failed to download template");
    }
  }

  async function commitImport() {
    if (!preview) return;

    const validRows = preview.rows.filter((r) => r.valid);
    if (validRows.length === 0) {
      toast.error("No valid rows to import");
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
      toast.success(`Imported ${data.created} records`);
    } catch (err: unknown) {
      const e = err as { response?: { data?: { detail?: string } } };
      toast.error(e.response?.data?.detail ?? "Import failed");
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
        title="Import Attendance"
        description="Bulk import attendance records from CSV files (biometric device exports)"
        actions={
          <div className="flex gap-2">
            <Button variant="outline" size="sm" onClick={downloadTemplate}>
              <Download className="mr-2 h-4 w-4" /> Download Template
            </Button>
            <Button asChild variant="ghost" size="sm">
              <Link href="/attendance">
                <ArrowLeft className="mr-2 h-4 w-4" /> Back
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
                    Parsing {fileName}…
                  </p>
                </>
              ) : (
                <>
                  <Upload className="h-12 w-12 text-muted-foreground" />
                  <p className="mt-4 text-sm font-medium text-foreground">
                    Drop your CSV file here, or click to browse
                  </p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    Supports .csv and .txt files up to 5 MB
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
                Expected CSV format
              </h3>
              <code className="mt-2 block rounded bg-background p-3 text-xs font-mono text-muted-foreground">
                employee_code,date,check_in_time,check_out_time{"\n"}
                EMP001,2026-07-01,08:30,17:00{"\n"}
                EMP002,2026-07-01,09:00,17:30
              </code>
              <ul className="mt-3 space-y-1 text-xs text-muted-foreground">
                <li>
                  - <strong>employee_code</strong>: Employee ID/code in your
                  system (required)
                </li>
                <li>
                  - <strong>date</strong>: YYYY-MM-DD format (required)
                </li>
                <li>
                  - <strong>check_in_time</strong>: HH:MM 24-hour format
                  (required)
                </li>
                <li>
                  - <strong>check_out_time</strong>: HH:MM 24-hour format
                  (optional)
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
              label="Total Rows"
              value={preview.rows.length}
              color="text-foreground"
            />
            <SummaryCard
              icon={CheckCircle2}
              label="Valid"
              value={preview.valid}
              color="text-green-600 dark:text-green-400"
            />
            <SummaryCard
              icon={XCircle}
              label="Invalid"
              value={preview.invalid}
              color="text-red-600 dark:text-red-400"
            />
          </div>

          <Card>
            <CardHeader className="flex-row items-center justify-between space-y-0 pb-4">
              <CardTitle className="text-base">Preview: {fileName}</CardTitle>
              <div className="flex gap-2">
                <Button variant="ghost" size="sm" onClick={reset}>
                  <Trash2 className="mr-2 h-4 w-4" /> Discard
                </Button>
                <Button
                  size="sm"
                  onClick={commitImport}
                  disabled={preview.valid === 0}
                >
                  <Upload className="mr-2 h-4 w-4" />
                  Import {preview.valid} records
                </Button>
              </div>
            </CardHeader>
            <CardContent className="p-0">
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="border-b bg-muted/50">
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground w-12">
                        Line
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground w-12">
                        Status
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">
                        Employee
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">
                        Date
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">
                        In
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">
                        Out
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">
                        Issues
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {preview.rows.map((row, i) => (
                      <tr
                        key={i}
                        className={cn(
                          "border-b last:border-0",
                          !row.valid && "bg-red-50/50 dark:bg-red-950/20",
                        )}
                      >
                        <td className="px-4 py-2 text-xs text-muted-foreground">
                          {row.line}
                        </td>
                        <td className="px-4 py-2">
                          {row.valid ? (
                            <CheckCircle2 className="h-4 w-4 text-green-600 dark:text-green-400" />
                          ) : (
                            <XCircle className="h-4 w-4 text-red-600 dark:text-red-400" />
                          )}
                        </td>
                        <td className="px-4 py-2 text-sm font-mono">
                          {row.employee_code}
                        </td>
                        <td className="px-4 py-2 text-sm">{row.date}</td>
                        <td className="px-4 py-2 text-sm">
                          {row.check_in_time}
                        </td>
                        <td className="px-4 py-2 text-sm text-muted-foreground">
                          {row.check_out_time || "—"}
                        </td>
                        <td className="px-4 py-2">
                          {row.errors.length > 0 && (
                            <div className="space-y-0.5">
                              {row.errors.map((err, j) => (
                                <p
                                  key={j}
                                  className="text-xs text-red-600 dark:text-red-400"
                                >
                                  {err}
                                </p>
                              ))}
                            </div>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>

          {preview.invalid > 0 && (
            <div className="flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30">
              <AlertTriangle className="mt-0.5 h-5 w-5 text-amber-600 dark:text-amber-400" />
              <div>
                <p className="text-sm font-medium text-amber-900 dark:text-amber-300">
                  {preview.invalid} row{preview.invalid > 1 ? "s" : ""} will be
                  skipped
                </p>
                <p className="mt-1 text-xs text-amber-800 dark:text-amber-300/70">
                  Only valid rows will be imported. Fix the CSV and re-upload to
                  include all rows.
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
            <p className="mt-4 text-sm font-medium">Importing records…</p>
            <p className="mt-1 text-xs text-muted-foreground">
              This may take a moment for large files
            </p>
          </CardContent>
        </Card>
      )}

      {step === "done" && importResult && (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-16">
            <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-green-100 dark:bg-green-950">
              <CheckCircle2 className="h-8 w-8 text-green-600 dark:text-green-400" />
            </div>
            <h2 className="mt-4 text-lg font-semibold text-foreground">
              Import Complete
            </h2>
            <div className="mt-4 flex gap-4">
              <Badge
                variant="outline"
                className="px-3 py-1.5 text-sm bg-green-50 dark:bg-green-950/30"
              >
                {importResult.created} created
              </Badge>
              {importResult.skipped > 0 && (
                <Badge
                  variant="outline"
                  className="px-3 py-1.5 text-sm bg-amber-50 dark:bg-amber-950/30"
                >
                  {importResult.skipped} skipped (duplicates)
                </Badge>
              )}
            </div>
            {importResult.errors.length > 0 && (
              <div className="mt-4 w-full max-w-md rounded-lg bg-red-50 p-3 dark:bg-red-950/30">
                {importResult.errors.map((err, i) => (
                  <p key={i} className="text-xs text-red-600 dark:text-red-400">
                    {err}
                  </p>
                ))}
              </div>
            )}
            <div className="mt-6 flex gap-3">
              <Button variant="outline" onClick={reset}>
                <Upload className="mr-2 h-4 w-4" /> Import Another
              </Button>
              <Button asChild>
                <Link href="/attendance">View Attendance</Link>
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
