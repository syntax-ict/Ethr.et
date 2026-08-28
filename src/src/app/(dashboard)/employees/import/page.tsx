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
import { useMutation } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import { cn } from "@/lib/utils";

interface PreviewResult {
  headers: string[];
  rows: Array<Record<string, string | null>>;
  errors: Record<number, string[]>;
}

interface CommitResult {
  created: number;
  skipped: number;
  errors: Record<number, string[]>;
}

type Step = "upload" | "preview" | "result";

export default function EmployeeImportPage() {
  const { t } = useT();
  const router = useRouter();
  const [step, setStep] = useState<Step>("upload");
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<PreviewResult | null>(null);
  const [result, setResult] = useState<CommitResult | null>(null);
  const [importKey] = useState(() => `import_${Date.now()}`);
  const dragRef = useRef<HTMLDivElement>(null);
  const [dragOver, setDragOver] = useState(false);

  const downloadTemplate = useMutation({
    mutationFn: async () => {
      const fd = new FormData();
      const dummy = new Blob([""], { type: "text/csv" });
      fd.append("file", dummy, "empty.csv");
      const { data } = await apiClient.post<{
        template: string;
        headers: string[];
      }>("/employees/import/template", fd, {
        headers: { "Content-Type": "multipart/form-data" },
      });
      return data;
    },
    onSuccess: (data) => {
      const csv = data.template || data.headers.join(",") + "\n";
      const blob = new Blob([csv], { type: "text/csv" });
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = "employees-import-template.csv";
      link.click();
      URL.revokeObjectURL(url);
      toast.success(t("employees_import_page.template_downloaded"));
    },
    onError: () =>
      toast.error(t("employees_import_page.template_download_failed")),
  });

  const previewMutation = useMutation({
    mutationFn: async (csvFile: File) => {
      const fd = new FormData();
      fd.append("file", csvFile);
      const { data } = await apiClient.post<PreviewResult>(
        "/employees/import/preview",
        fd,
        {
          headers: { "Content-Type": "multipart/form-data" },
        },
      );
      return data;
    },
    onSuccess: (data) => {
      setPreview(data);
      setStep("preview");
    },
    onError: () => toast.error(t("employees_import_page.parse_failed")),
  });

  const commitMutation = useMutation({
    mutationFn: async () => {
      if (!preview) throw new Error("No preview");
      const { data } = await apiClient.post<CommitResult>(
        "/employees/import/commit",
        {
          import_key: importKey,
          rows: preview.rows,
        },
      );
      return data;
    },
    onSuccess: (data) => {
      setResult(data);
      setStep("result");
      if (data.created > 0)
        toast.success(
          `${t("employees_import_page.imported")} ${data.created} ${t("employees_import_page.employees")}`,
        );
    },
    onError: () => toast.error(t("employees_import_page.import_failed")),
  });

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
    previewMutation.mutate(f);
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

  const totalRows = preview?.rows?.length ?? 0;
  const errorRows = preview ? Object.keys(preview.errors).length : 0;
  const validRows = totalRows - errorRows;

  return (
    <RoleGate minRole="hr_admin">
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
                        type="file"
                        id="csv-input"
                        accept=".csv,text/csv"
                        className="hidden"
                        onChange={(e) =>
                          handleFileSelect(e.target.files?.[0] ?? null)
                        }
                      />
                      <label htmlFor="csv-input">
                        <Button
                          variant="outline"
                          size="sm"
                          className="mt-3"
                          asChild
                        >
                          <span className="cursor-pointer">
                            {t("employees_import_page.browse_files")}
                          </span>
                        </Button>
                      </label>
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
                  onClick={() => downloadTemplate.mutate()}
                  disabled={downloadTemplate.isPending}
                >
                  {downloadTemplate.isPending ? (
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
                value={validRows}
                color="green"
              />
              <StatCard
                label={t("employees_import_page.rows_with_errors")}
                value={errorRows}
                color="red"
              />
            </div>

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
                onClick={() => commitMutation.mutate()}
                disabled={commitMutation.isPending || validRows === 0}
              >
                {commitMutation.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <UploadIcon className="mr-2 h-4 w-4" />
                )}
                {t("employees_import_page.import_prefix")} {validRows}{" "}
                {validRows !== 1
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
                <StatCard
                  label={t("employees_import_page.skipped")}
                  value={result.skipped}
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
