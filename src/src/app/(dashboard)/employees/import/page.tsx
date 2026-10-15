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
  Hash,
  X,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { PageHeader } from "@/components/shared/page-header";
import { SimpleTable } from "@/components/shared/simple-table";
import { RoleGate } from "@/components/shared/role-gate";
import {
  fetchEmployeeImportTemplate,
  useCommitEmployeeImport,
  useNextEmployeeCode,
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

/**
 * A server message without its "Row 5:" lead. The page puts the file's line
 * beside each message itself, and the commit's lead counts rows sent, not
 * lines, so it would name a different number from the label next to it.
 */
function withoutRowPrefix(message: string): string {
  const rest = message.replace(/^\S+ \d+[:፦]\s*/u, "");
  return rest.charAt(0).toUpperCase() + rest.slice(1);
}

/** The import's columns, in template order, each with whether it is required. */
const COLUMNS: Array<{ key: string; required?: boolean }> = [
  { key: "name", required: true },
  { key: "hire_date", required: true },
  { key: "employee_code" },
  { key: "name_am" },
  { key: "email" },
  { key: "phone" },
  { key: "gender" },
  { key: "date_of_birth" },
  { key: "department" },
  { key: "branch" },
  { key: "position" },
  { key: "salary" },
  { key: "national_id" },
  { key: "tin" },
  { key: "badge_number" },
  { key: "nationality_status" },
  { key: "marital_status" },
];

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

  const nextCode = useNextEmployeeCode();
  const [createLogins, setCreateLogins] = useState(false);
  const [problemsOnly, setProblemsOnly] = useState(false);

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
      { importKey, rows: validRows, createLogins },
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
    setProblemsOnly(false);
  }

  // Counted from the rows, not from `Object.keys(errors)`: that also counted
  // the file-level entry, so a file missing a required column (no rows, one
  // file error) showed "-1 valid rows" beside an enabled Import button.
  const fileErrors = preview?.errors[FILE_LEVEL] ?? [];
  const validRows = preview
    ? preview.rows.filter((_, i) => !preview.errors[lineOf(i)]?.length)
    : [];
  // The CSV line of each row sent, so the commit's per-row errors (keyed by
  // position in what was sent) can name the line in the file.
  const validLines = preview
    ? preview.rows
        .map((_, i) => lineOf(i))
        .filter((line) => !preview.errors[line]?.length)
    : [];
  const totalRows = preview?.rows.length ?? 0;
  const errorRows = totalRows - validRows.length;
  const validCount = validRows.length;
  const unnumbered = validRows.filter((row) => !row.employee_code).length;

  // Every row problem, in file order; the table shows 100 rows and its badge
  // held the reasons only in a hover title.
  const problems: Array<{ line: number; message: string }> = preview
    ? Object.entries(preview.errors)
        .filter(([line]) => Number(line) !== FILE_LEVEL)
        .sort(([a], [b]) => Number(a) - Number(b))
        .flatMap(([line, messages]) =>
          messages.map((message) => ({
            line: Number(line),
            message: withoutRowPrefix(message),
          })),
        )
    : [];

  const shownRows = preview
    ? preview.rows
        .map((row, i) => ({ row, line: lineOf(i) }))
        .filter(({ line }) => !problemsOnly || preview.errors[line]?.length)
    : [];

  function downloadProblems() {
    const escape = (value: string) => `"${value.replace(/"/g, '""')}"`;
    saveCsv(
      `${(file?.name ?? "employees").replace(/\.[^.]+$/, "")}-problems.csv`,
      [
        "line,problem",
        ...problems.map((p) => `${p.line},${escape(p.message)}`),
      ].join("\n") + "\n",
    );
  }

  const commitProblems: Array<{ line: number; message: string }> = result
    ? Object.entries(result.errors).flatMap(([index, messages]) =>
        messages.map((message) => ({
          line: validLines[Number(index)] ?? Number(index),
          message: withoutRowPrefix(message),
        })),
      )
    : [];

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
          <div className="grid gap-6 xl:grid-cols-3">
            <Card className="xl:col-span-2">
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
                <ColumnGuide />
                <p className="text-xs text-muted-foreground">
                  {t(
                    "employees_import_page.loose_headers_hint",
                    "Headers such as Full Name, Start Date or Department are recognised, and a file exported from the employee list imports as it is.",
                  )}
                </p>
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

            {problems.length > 0 && (
              <Card>
                <CardHeader className="flex flex-row items-center justify-between gap-3 space-y-0">
                  <CardTitle className="text-base">
                    {t("employees_import_page.problems_title", "What to fix")}
                  </CardTitle>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={downloadProblems}
                  >
                    <Download className="mr-2 h-4 w-4" />
                    {t(
                      "employees_import_page.download_problems",
                      "Download the list",
                    )}
                  </Button>
                </CardHeader>
                <CardContent>
                  <ul className="max-h-64 space-y-1 overflow-y-auto text-sm">
                    {problems.map((p, i) => (
                      <li key={`${p.line}-${i}`} className="flex gap-3">
                        <span className="w-14 shrink-0 font-mono text-xs text-muted-foreground">
                          {t("employees_import_page.line", "Line :line", {
                            line: p.line,
                          })}
                        </span>
                        <span className="text-foreground">{p.message}</span>
                      </li>
                    ))}
                  </ul>
                </CardContent>
              </Card>
            )}

            {unnumbered > 0 && (
              <div className="flex items-start gap-3 rounded-lg border border-info-edge bg-info-soft p-3 text-sm text-info-on-soft">
                <Hash className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                <p>
                  {unnumbered === 1
                    ? t(
                        "employees_import_page.auto_numbered_one",
                        "1 row has no employee code. It gets the next number.",
                      )
                    : nextCode.data?.employee_code
                      ? t(
                          "employees_import_page.auto_numbered",
                          ":count rows have no employee code. They get the next numbers, from :code.",
                          {
                            count: unnumbered,
                            code: nextCode.data.employee_code,
                          },
                        )
                      : t(
                          "employees_import_page.auto_numbered_generic",
                          ":count rows have no employee code. They get the next numbers.",
                          { count: unnumbered },
                        )}
                </p>
              </div>
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
                  <div className="flex flex-wrap items-center gap-2">
                    {errorRows > 0 && (
                      <Button
                        variant="ghost"
                        size="sm"
                        aria-pressed={problemsOnly}
                        onClick={() => setProblemsOnly((v) => !v)}
                      >
                        {problemsOnly
                          ? t(
                              "employees_import_page.show_all_rows",
                              "Show all rows",
                            )
                          : t(
                              "employees_import_page.show_problem_rows",
                              "Show only rows to fix",
                            )}
                      </Button>
                    )}
                    <Button variant="ghost" size="sm" onClick={reset}>
                      <X className="mr-1 h-3 w-3" />{" "}
                      {t("employees_import_page.choose_different_file")}
                    </Button>
                  </div>
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
                  rows={shownRows.slice(0, 100).map(({ row, line }) => {
                    const lineNumber = line;
                    const rowErrors = preview.errors[lineNumber] ?? [];
                    return {
                      key: String(line),
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
                {shownRows.length > 100 && (
                  <div className="border-t bg-muted/30 px-4 py-2 text-xs text-muted-foreground">
                    {t("employees_import_page.showing_first_100_prefix")}{" "}
                    {shownRows.length}{" "}
                    {t("employees_import_page.showing_first_100_suffix")}
                  </div>
                )}
              </CardContent>
            </Card>

            {/* Off by default, as on the new-employee form: on emails every
                person in the file. The API always took `create_logins`, and
                this page never sent it. */}
            <div className="flex items-start gap-3 rounded-md border border-border-default p-4">
              <Checkbox
                id="create_logins"
                checked={createLogins}
                onCheckedChange={(v) => setCreateLogins(v === true)}
                aria-describedby="create_logins-hint"
              />
              <div className="space-y-1">
                <Label htmlFor="create_logins" className="text-sm font-medium">
                  {t(
                    "employees_import_page.create_logins",
                    "Send a login invitation to each new employee with an email",
                  )}
                </Label>
                <p
                  id="create_logins-hint"
                  className="text-xs text-muted-foreground"
                >
                  {t(
                    "employees_import_page.create_logins_hint",
                    "Each one sets a password from the email. People already in ETHR are not invited again.",
                  )}
                </p>
              </div>
            </div>

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
              {result.users_created > 0 && (
                <p className="mt-4 text-sm text-muted-foreground">
                  {result.users_created === 1
                    ? t(
                        "employees_import_page.invitations_sent_one",
                        "1 login invitation sent.",
                      )
                    : t(
                        "employees_import_page.invitations_sent",
                        ":count login invitations sent.",
                        { count: result.users_created },
                      )}
                </p>
              )}
              {commitProblems.length > 0 && (
                <ul className="mx-auto mt-6 max-w-xl space-y-1 text-left text-sm">
                  {commitProblems.map((p, i) => (
                    <li key={`${p.line}-${i}`} className="flex gap-3">
                      <span className="w-14 shrink-0 font-mono text-xs text-muted-foreground">
                        {t("employees_import_page.line", "Line :line", {
                          line: p.line,
                        })}
                      </span>
                      <span className="text-foreground">{p.message}</span>
                    </li>
                  ))}
                </ul>
              )}
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

/** What each column takes, in the words someone filling a spreadsheet uses. */
function ColumnGuide() {
  const { t } = useT();
  const help: Record<string, string> = {
    name: t("employees_import_page.col_name", "Full name"),
    hire_date: t(
      "employees_import_page.col_hire_date",
      "2024-03-15 or 15/03/2024, day first",
    ),
    employee_code: t(
      "employees_import_page.col_employee_code",
      "Leave blank for the next number",
    ),
    name_am: t("employees_import_page.col_name_am", "Name in Amharic"),
    email: t(
      "employees_import_page.col_email",
      "Needed for a login invitation",
    ),
    phone: t("employees_import_page.col_phone", "09... or +251..."),
    gender: t("employees_import_page.col_gender", "male or female, M or F"),
    date_of_birth: t(
      "employees_import_page.col_date_of_birth",
      "Same formats as the hire date",
    ),
    department: t(
      "employees_import_page.col_department",
      "Its name or its code in ETHR",
    ),
    branch: t("employees_import_page.col_branch", "Its name or its code"),
    position: t("employees_import_page.col_position", "Its title or its code"),
    salary: t(
      "employees_import_page.col_salary",
      "Monthly, in birr, such as 12,500.00",
    ),
    national_id: t(
      "employees_import_page.col_national_id",
      "Fayda number, stored encrypted",
    ),
    tin: t("employees_import_page.col_tin", "Taxpayer identification number"),
    badge_number: t(
      "employees_import_page.col_badge_number",
      "The number used on attendance devices",
    ),
    nationality_status: t("employees_import_page.nationality_status_values"),
    marital_status: t(
      "employees_import_page.col_marital_status",
      "single, married, divorced or widowed",
    ),
  };

  return (
    <div className="rounded-lg border bg-muted/30 p-3">
      <p className="text-xs font-semibold text-foreground">
        {t("employees_import_page.required_columns")}
      </p>
      <dl className="mt-2 space-y-1.5 text-xs">
        {COLUMNS.map((column) => (
          <div key={column.key}>
            <dt className="font-mono text-foreground">
              {column.key}
              {column.required && (
                <span className="ml-1 font-sans text-destructive">
                  ({t("employees_import_page.required")})
                </span>
              )}
            </dt>
            <dd className="text-muted-foreground">{help[column.key]}</dd>
          </div>
        ))}
      </dl>
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
