"use client";

import { useState, useRef } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import {
  ArrowLeft,
  Upload,
  FileSpreadsheet,
  Download,
  AlertTriangle,
  CheckCircle2,
  Loader2,
  Eye,
  Upload as UploadIcon,
  ChevronRight,
  X,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { PageHeader } from "@/components/shared/page-header";
import { SimpleTable } from "@/components/shared/simple-table";
import { RoleGate } from "@/components/shared/role-gate";
import {
  fetchEmployeeImportTemplate,
  useCommitEmployeeImport,
  usePreviewEmployeeImport,
  type EmployeeImportPreview,
  type EmployeeImportResult,
} from "@/features/employees/api";
import { saveCsv } from "@/lib/utils/csv-export";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import { cn } from "@/lib/utils";

type Step = "upload" | "preview" | "result";

/** `errors[0]` is about the file itself — a missing column, no data rows. */
const FILE_LEVEL = 0;

/** The CSV line a preview row came from: line 1 is the header. */
function lineOf(rowIndex: number): number {
  return rowIndex + 2;
}

/** Unique per file; the random tail keeps two files in one millisecond apart. */
function newImportKey(): string {
  return `import_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
}

export default function EmployeeImportPage() {
  const { t } = useT();
  const router = useRouter();
  const [step, setStep] = useState<Step>("upload");
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<EmployeeImportPreview | null>(null);
  const [result, setResult] = useState<EmployeeImportResult | null>(null);
  /**
   * One key per previewed file, kept across retries of that file's commit so
   * a retry is idempotent. It used to be fixed for the life of the page, and
   * the importer keys a row with no employee_code by its index — so after
   * "Import another file", every such row at an index the first file had
   * already used was skipped as "already imported".
   */
  const [importKey, setImportKey] = useState(newImportKey);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const dragRef = useRef<HTMLDivElement>(null);
  const [dragOver, setDragOver] = useState(false);
  const [templatePending, setTemplatePending] = useState(false);

  const previewMutation = usePreviewEmployeeImport();
  const commitMutation = useCommitEmployeeImport();

  async function downloadTemplate() {
    setTemplatePending(true);
    try {
      const data = await fetchEmployeeImportTemplate();
      saveCsv(
        "employees-import-template.csv",
        data.template || data.headers.join(",") + "\n",
      );
      toast.success(t("employees_import_page.template_downloaded"));
    } catch {
      toast.error(t("employees_import_page.template_download_failed"));
    } finally {
      setTemplatePending(false);
    }
  }

  /**
   * Only the rows the preview passed. The commit endpoint validates every row
   * it is sent (`rows.*.name` required, `rows.*.email` an email, …), so sending
   * the rows the preview had already flagged failed the whole batch with a
   * 422 — the page promised "rows with errors will be skipped" and imported
   * nothing.
   */
  function commit() {
    commitMutation.mutate(
      { importKey, rows: validRows },
      {
        onSuccess: (data) => {
          setResult(data);
          setStep("result");
          if (data.created > 0)
            toast.success(
              `${t("employees_import_page.imported")} ${data.created} ${t("employees_import_page.employees")}`,
            );
        },
        onError: () => toast.error(t("employees_import_page.import_failed")),
      },
    );
  }

  function handleFileSelect(f: File | null) {
    if (!f) return;
    if (
      !f.name.endsWith(".csv") &&
      !f.name.endsWith(".txt") &&
      f.type !== "text/csv"
    ) {
      toast.error(t("employees_import_page.select_csv"));
      return;
    }
    setFile(f);
    previewMutation.mutate(f, {
      onSuccess: (data) => {
        setPreview(data);
        setImportKey(newImportKey());
        setStep("preview");
      },
      onError: () => toast.error(t("employees_import_page.parse_failed")),
    });
  }

  function handleDrop(e: React.DragEvent) {
    e.preventDefault();
    setDragOver(false);
    const f = e.dataTransfer.files?.[0];
    if (f) handleFileSelect(f);
  }

  function reset() {
    setStep("upload");
    setFile(null);
    setPreview(null);
    setResult(null);
  }

  // Counted from the rows, not from `Object.keys(errors)`: that also counted
  // the file-level entry, so a file missing a required column (no rows, one
  // file error) showed "-1 valid rows" beside an enabled Import button.
  const fileErrors = preview?.errors[FILE_LEVEL] ?? [];
  const validRows = preview
    ? preview.rows.filter((_, i) => !preview.errors[lineOf(i)]?.length)
    : [];
  const totalRows = preview?.rows.length ?? 0;
  const errorRows = totalRows - validRows.length;
  const validCount = validRows.length;

  return (
    <RoleGate anyPermission={["manageEmployees"]}>
      <div className="space-y-6">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="sm" asChild>
            <Link href="/employees">
              <ArrowLeft className="mr-2 h-4 w-4" />
              {t("employees_import_page.back_to_employees")}
            </Link>
          </Button>
        </div>

        <PageHeader
          title={t("employees_import_page.title")}
          description={t("employees_import_page.description")}
        />

        <StepIndicator current={step} />

        {step === "upload" && (
          <div className="grid gap-6 lg:grid-cols-3">
            <Card className="lg:col-span-2">
              <CardHeader>
                <CardTitle className="text-base">
                  {t("employees_import_page.upload_csv")}
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div
                  ref={dragRef}
                  onDragOver={(e) => {
                    e.preventDefault();
                    setDragOver(true);
                  }}
                  onDragLeave={() => setDragOver(false)}
                  onDrop={handleDrop}
                  className={cn(
                    "rounded-xl border-2 border-dashed p-8 text-center transition-colors",
                    dragOver ? "border-primary bg-primary/5" : "border-border",
                  )}
                >
                  {previewMutation.isPending ? (
                    <div className="flex flex-col items-center gap-3">
                      <Loader2 className="h-10 w-10 animate-spin text-primary" />
                      <p className="text-sm text-muted-foreground">
                        {t("employees_import_page.parsing_csv")}
                      </p>
                    </div>
                  ) : (
                    <>
                      <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10">
                        <FileSpreadsheet className="h-6 w-6 text-primary" />
                      </div>
                      <p className="mt-4 text-sm font-medium">
                        {t("employees_import_page.drag_drop_hint")}
                      </p>
                      <input
                        ref={fileInputRef}
                        type="file"
                        id="csv-input"
                        accept=".csv,text/csv"
                        className="hidden"
                        tabIndex={-1}
                        onChange={(e) =>
                          handleFileSelect(e.target.files?.[0] ?? null)
                        }
                      />
                      {/* A real button. It was a <span> inside a <label> for a
                          display:none input, which no keyboard could reach. */}
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="mt-3"
                        onClick={() => fileInputRef.current?.click()}
                      >
                        {t("employees_import_page.browse_files")}
                      </Button>
                      <p className="mt-4 text-xs text-muted-foreground">
                        {t("employees_import_page.format_hint")}
                      </p>
                    </>
                  )}
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="text-base">
                  {t("employees_import_page.download_template_title")}
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                <p className="text-sm text-muted-foreground">
                  {t("employees_import_page.template_hint")}
                </p>
                <Button
                  variant="outline"
                  className="w-full"
                  onClick={downloadTemplate}
                  disabled={templatePending}
                >
                  {templatePending ? (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  ) : (
                    <Download className="mr-2 h-4 w-4" />
                  )}
                  {t("employees_import_page.download_csv_template")}
                </Button>
                <div className="rounded-lg border bg-muted/30 p-3">
                  <p className="text-xs font-semibold text-foreground">
                    {t("employees_import_page.required_columns")}
                  </p>
                  <ul className="mt-1 space-y-0.5 text-xs text-muted-foreground">
                    <li>
                      • <code className="font-mono">name</code> (
                      {t("employees_import_page.required")})
                    </li>
                    <li>
                      • <code className="font-mono">hire_date</code> (
                      {t("employees_import_page.required")})
                    </li>
                    <li>
                      • <code className="font-mono">email</code>,{" "}
                      <code className="font-mono">phone</code>
                    </li>
                    <li>
                      • <code className="font-mono">employee_code</code>
                    </li>
                    <li>
                      • <code className="font-mono">gender</code>
                    </li>
                    <li>
                      • <code className="font-mono">department_code</code>
                    </li>
                    <li>
                      • <code className="font-mono">branch_code</code>
                    </li>
                    <li>
                      • <code className="font-mono">position_code</code>
                    </li>
                    <li>
                      • <code className="font-mono">salary_cents</code>
                    </li>
                  </ul>
                </div>
              </CardContent>
            </Card>
          </div>
        )}

        {step === "preview" && preview && (
          <>
            <div className="grid gap-4 sm:grid-cols-3">
              <StatCard
                label={t("employees_import_page.total_rows")}
                value={totalRows}
                color="blue"
              />
              <StatCard
                label={t("employees_import_page.valid_rows")}
                value={validCount}
                color="green"
              />
              <StatCard
                label={t("employees_import_page.rows_with_errors")}
                value={errorRows}
                color="red"
              />
            </div>

            {/* The server's own message — the file could not be read as an
                import at all, and until now nothing on the page said why. */}
            {fileErrors.length > 0 && (
              <Card className="border-destructive-edge bg-destructive-soft">
                <CardContent className="p-4" role="alert">
                  <div className="flex items-start gap-3">
                    <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-destructive" />
                    <ul className="space-y-1 text-sm font-medium text-foreground">
                      {fileErrors.map((message) => (
                        <li key={message}>{message}</li>
                      ))}
                    </ul>
                  </div>
                </CardContent>
              </Card>
            )}

            {errorRows > 0 && (
              <Card className="border-warning-edge bg-warning-soft">
                <CardContent className="p-4">
                  <div className="flex items-start gap-3">
                    <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-warning" />
                    <div>
                      <p className="text-sm font-semibold text-foreground">
                        {errorRows}{" "}
                        {errorRows > 1
                          ? t("employees_import_page.rows_contain_errors")
                          : t("employees_import_page.row_contains_errors")}
                      </p>
                      <p className="mt-1 text-sm text-muted-foreground">
                        {t("employees_import_page.error_rows_skipped_hint")}
                      </p>
                    </div>
                  </div>
                </CardContent>
              </Card>
            )}

            <Card>
              <CardHeader>
                <div className="flex items-center justify-between">
                  <CardTitle className="text-base">
                    {t("employees_import_page.preview")}{" "}
                    {file && (
                      <span className="text-xs font-normal text-muted-foreground">
                        ({file.name})
                      </span>
                    )}
                  </CardTitle>
                  <Button variant="ghost" size="sm" onClick={reset}>
                    <X className="mr-1 h-3 w-3" />{" "}
                    {t("employees_import_page.choose_different_file")}
                  </Button>
                </div>
              </CardHeader>
              <CardContent className="p-0">
                <SimpleTable
                  caption={t("employees_import_page.preview", "Import preview")}
                  headers={[
                    t("employees_import_page.row"),
                    ...preview.headers,
                    t("employees_import_page.validation"),
                  ]}
                  rows={preview.rows.slice(0, 100).map((row, i) => {
                    const lineNumber = i + 2;
                    const rowErrors = preview.errors[lineNumber] ?? [];
                    return {
                      key: String(i),
                      className:
                        rowErrors.length > 0
                          ? "bg-destructive-soft"
                          : undefined,
                      cells: [
                        <span key="ln" className="text-muted-foreground">
                          {lineNumber}
                        </span>,
                        ...preview.headers.map((h) => (
                          <span key={h} className="whitespace-nowrap">
                            {row[h] ?? "—"}
                          </span>
                        )),
                        rowErrors.length > 0 ? (
                          <Badge
                            key="v"
                            variant="outline"
                            className="border-0 bg-destructive-soft text-destructive-on-soft text-[10px]"
                            title={rowErrors.join("; ")}
                          >
                            {rowErrors.length}{" "}
                            {rowErrors.length > 1
                              ? t("employees_import_page.errors")
                              : t("employees_import_page.error")}
                          </Badge>
                        ) : (
                          <Badge
                            key="v"
                            variant="outline"
                            className="border-0 bg-success-soft text-success-on-soft text-[10px]"
                          >
                            {t("employees_import_page.valid")}
                          </Badge>
                        ),
                      ],
                    };
                  })}
                />
                {totalRows > 100 && (
                  <div className="border-t bg-muted/30 px-4 py-2 text-xs text-muted-foreground">
                    {t("employees_import_page.showing_first_100_prefix")}{" "}
                    {totalRows}{" "}
                    {t("employees_import_page.showing_first_100_suffix")}
                  </div>
                )}
              </CardContent>
            </Card>

            <div className="flex justify-end gap-3">
              <Button variant="outline" onClick={reset}>
                {t("common.cancel")}
              </Button>
              <Button
                onClick={commit}
                disabled={commitMutation.isPending || validCount === 0}
              >
                {commitMutation.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <UploadIcon className="mr-2 h-4 w-4" />
                )}
                {t("employees_import_page.import_prefix")} {validCount}{" "}
                {validCount !== 1
                  ? t("employees_import_page.valid_rows_lc")
                  : t("employees_import_page.valid_row_lc")}
              </Button>
            </div>
          </>
        )}

        {step === "result" && result && (
          <Card>
            <CardContent className="p-8 text-center">
              <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-success-soft">
                <CheckCircle2 className="h-8 w-8 text-success" />
              </div>
              <h2 className="mt-4 text-xl font-bold text-foreground">
                {t("employees_import_page.import_complete")}
              </h2>
              <div className="mt-6 grid gap-4 sm:grid-cols-3 max-w-xl mx-auto">
                <StatCard
                  label={t("attendance.import_page.created")}
                  value={result.created}
                  color="green"
                />
                {/* `matched` is a person who already exists, and was in no
                    count at all — 10 rows with 4 matches read as 6 created
                    and nothing else. Both it and `skipped` mean "not created
                    because already there". */}
                <StatCard
                  label={t("employees_import_page.skipped")}
                  value={result.skipped + (result.matched ?? 0)}
                  color="amber"
                />
                <StatCard
                  label={t("employees_import_page.errors_label")}
                  value={Object.keys(result.errors).length}
                  color="red"
                />
              </div>
              <div className="mt-8 flex justify-center gap-3">
                <Button variant="outline" onClick={reset}>
                  {t("employees_import_page.import_another_file")}
                </Button>
                <Button onClick={() => router.push("/employees")}>
                  {t("employees_import_page.view_employees")}
                </Button>
              </div>
            </CardContent>
          </Card>
        )}
      </div>
    </RoleGate>
  );
}

function StepIndicator({ current }: { current: Step }) {
  const { t } = useT();
  const steps: Array<{
    key: Step;
    label: string;
    icon: React.ComponentType<{ className?: string }>;
  }> = [
    {
      key: "upload",
      label: t("employees_import_page.step_upload"),
      icon: Upload,
    },
    {
      key: "preview",
      label: t("employees_import_page.step_preview"),
      icon: Eye,
    },
    {
      key: "result",
      label: t("employees_import_page.step_import"),
      icon: CheckCircle2,
    },
  ];
  const currentIndex = steps.findIndex((s) => s.key === current);

  return (
    <div className="flex items-center justify-center gap-2 sm:gap-4">
      {steps.map((s, i) => {
        const isActive = i === currentIndex;
        const isComplete = i < currentIndex;
        const Icon = s.icon;
        return (
          <div key={s.key} className="flex items-center gap-2 sm:gap-4">
            <div className="flex flex-col items-center gap-1">
              <div
                className={cn(
                  "flex h-9 w-9 items-center justify-center rounded-full border-2 transition-colors",
                  isComplete
                    ? "border-primary bg-primary text-primary-foreground"
                    : isActive
                      ? "border-primary text-primary"
                      : "border-muted-foreground/30 text-muted-foreground",
                )}
              >
                {isComplete ? (
                  <CheckCircle2 className="h-4 w-4" />
                ) : (
                  <Icon className="h-4 w-4" />
                )}
              </div>
              <span
                className={cn(
                  "text-xs font-medium",
                  isActive || isComplete
                    ? "text-foreground"
                    : "text-muted-foreground",
                )}
              >
                {s.label}
              </span>
            </div>
            {i < steps.length - 1 && (
              <ChevronRight
                className={cn(
                  "h-4 w-4 mb-5",
                  isComplete ? "text-primary" : "text-muted-foreground/30",
                )}
              />
            )}
          </div>
        );
      })}
    </div>
  );
}

const statColors: Record<string, string> = {
  blue: "bg-info-soft text-info-on-soft border-info-edge",
  green: "bg-success-soft text-success-on-soft border-success-edge",
  red: "bg-destructive-soft text-destructive-on-soft border-destructive-edge",
  amber: "bg-warning-soft text-warning-on-soft border-warning-edge",
};

function StatCard({
  label,
  value,
  color,
}: {
  label: string;
  value: number;
  color: string;
}) {
  return (
    <div
      className={cn("rounded-lg border-2 p-4 text-center", statColors[color])}
    >
      <p className="text-3xl font-bold">{value}</p>
      <p className="mt-1 text-xs font-medium uppercase tracking-wider">
        {label}
      </p>
    </div>
  );
}
