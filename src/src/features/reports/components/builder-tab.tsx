"use client";

import { useState } from "react";
import { reportFieldLabel } from "@/features/reports/field-labels";
import {
  Download,
  Loader2,
  Database,
  Columns3,
  ListFilter,
  Group,
  ArrowDownUp,
  Eye,
  Save,
  Plus,
  X,
  FileSpreadsheet,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  DialogDescription,
} from "@/components/ui/dialog";
import {
  useReportSources,
  useGenerateReport,
  useSaveReport,
  type ReportConfig,
  type ReportSourceKey,
  type ReportSources,
} from "@/features/reports/api";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import { buildCsv, centsAsAmount, saveCsv } from "@/lib/utils/csv-export";
import { usePlanFeatures } from "@/features/auth/api";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { ReportFilterValue } from "@/features/reports/components/report-filter-value";
import { apiErrorMessage } from "@/lib/api/error-message";
import { ErrorState } from "@/components/shared/error-state";
import { PreviewResult } from "@/features/reports/components/preview-result";

export interface FilterRow {
  field: string;
  value: string;
}

export function BuilderTab() {
  const { t } = useT();
  const canSaveTemplates = usePlanFeatures().has("custom_reports");
  const {
    data: sourcesData,
    isLoading: sourcesLoading,
    isError: sourcesError,
    refetch: refetchSources,
  } = useReportSources();
  const generate = useGenerateReport();
  const [config, setConfig] = useState<ReportConfig>({ source: "employees" });
  const [filters, setFilters] = useState<FilterRow[]>([]);
  const [saveOpen, setSaveOpen] = useState(false);

  const { hasPermission } = usePermissions();
  // The server refuses the payroll source without payroll.viewAll and drops
  // salary without employee.viewFinancial, so neither is offered here.
  const sources: Partial<ReportSources> = Object.fromEntries(
    Object.entries(sourcesData?.sources ?? {}).filter(
      ([key]) => key !== "payroll" || hasPermission("payroll.viewAll"),
    ),
  );
  const sourceMeta = sources[config.source];
  const availableFields: string[] = (sourceMeta?.fields ?? []).filter(
    (field: string) =>
      field !== "salary_cents" || hasPermission("employee.viewFinancial"),
  );
  // Filters are not columns: only these keys reach the query.
  const filterFields: string[] = sourceMeta?.filters ?? [];
  const selectedColumns = config.columns ?? [];

  function toggleColumn(field: string) {
    const current = selectedColumns.includes(field);
    const next = current
      ? selectedColumns.filter((f) => f !== field)
      : [...selectedColumns, field];
    setConfig((p) => ({ ...p, columns: next.length > 0 ? next : undefined }));
  }

  function selectAllColumns() {
    setConfig((p) => ({ ...p, columns: availableFields }));
  }

  function clearColumns() {
    setConfig((p) => ({ ...p, columns: undefined }));
  }

  function addFilter() {
    if (filterFields.length === 0) return;
    setFilters((p) => [...p, { field: filterFields[0], value: "" }]);
  }

  function updateFilter(i: number, patch: Partial<FilterRow>) {
    setFilters((p) => p.map((f, idx) => (idx === i ? { ...f, ...patch } : f)));
  }

  function removeFilter(i: number) {
    setFilters((p) => p.filter((_, idx) => idx !== i));
  }

  // The Select only offers the keys of `sources`.
  function changeSource(source: string) {
    setConfig({ source: source as ReportSourceKey });
    setFilters([]);
  }

  function buildPayload(): ReportConfig {
    const payload: ReportConfig = { source: config.source };
    if (config.columns?.length) payload.columns = config.columns;
    if (config.group_by) payload.group_by = config.group_by;
    if (config.sort_by) {
      payload.sort_by = config.sort_by;
      payload.sort_dir = config.sort_dir ?? "asc";
    }
    const validFilters = filters.filter((f) => f.field && f.value);
    if (validFilters.length > 0) {
      payload.filters = Object.fromEntries(
        validFilters.map((f) => [f.field, f.value]),
      );
    }
    return payload;
  }

  function handlePreview() {
    generate.mutate(buildPayload(), {
      onError: (err) =>
        toast.error(apiErrorMessage(err, t("reports_page.generate_failed"))),
    });
  }

  function downloadCsv() {
    const result = generate.data;
    if (!result?.data?.length) return;
    saveCsv(
      `${config.source}-report-${new Date().toISOString().split("T")[0]}.csv`,
      // Columns read as on screen, in the reader's language, and money as
      // birr: the file had API field names and raw cents.
      buildCsv(result.data, {
        label: (key) => reportFieldLabel(t, key),
        value: centsAsAmount,
      }),
    );
  }

  if (sourcesLoading) return <Skeleton className="h-96 w-full" />;
  // Without the sources the builder drew with nothing to choose from.
  if (sourcesError) return <ErrorState onRetry={() => refetchSources()} />;

  return (
    <div className="grid gap-6 lg:grid-cols-[320px_1fr]">
      {/* Configuration sidebar */}
      <div className="space-y-4">
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="flex items-center gap-2 text-base">
              <Database className="h-4 w-4" /> {t("reports_page.data_source")}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <Select value={config.source} onValueChange={changeSource}>
              <SelectTrigger aria-label={t("reports_page.data_source")}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {Object.entries(sources).map(([key, meta]) => (
                  <SelectItem key={key} value={key}>
                    {meta.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="mt-2 text-xs text-muted-foreground">
              {t(
                "reports_page.available_fields_count",
                ":count available fields",
                {
                  count: availableFields.length,
                },
              )}
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3 flex flex-row items-center justify-between">
            <CardTitle className="flex items-center gap-2 text-base">
              <Columns3 className="h-4 w-4" /> {t("reports_page.columns")}
              <Badge variant="outline" className="ml-1 text-[10px]">
                {selectedColumns.length || t("reports_page.all")}
              </Badge>
            </CardTitle>
            <div className="flex gap-1">
              <Button
                size="sm"
                variant="ghost"
                className="h-6 text-xs px-2"
                onClick={selectAllColumns}
              >
                {t("reports_page.all")}
              </Button>
              <Button
                size="sm"
                variant="ghost"
                className="h-6 text-xs px-2"
                onClick={clearColumns}
              >
                {t("reports_page.clear")}
              </Button>
            </div>
          </CardHeader>
          <CardContent>
            <div className="max-h-56 space-y-1 overflow-y-auto pr-1">
              {availableFields.map((field) => (
                <label
                  key={field}
                  className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-muted/50"
                >
                  <input
                    type="checkbox"
                    checked={
                      selectedColumns.length === 0 ||
                      selectedColumns.includes(field)
                    }
                    onChange={() => toggleColumn(field)}
                    className="h-3.5 w-3.5 rounded"
                  />
                  <span className="text-xs">{reportFieldLabel(t, field)}</span>
                </label>
              ))}
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3 flex flex-row items-center justify-between">
            <CardTitle className="flex items-center gap-2 text-base">
              <ListFilter className="h-4 w-4" /> {t("reports_page.filters")}
            </CardTitle>
            <Button
              size="sm"
              variant="ghost"
              className="h-6 px-2"
              onClick={addFilter}
              disabled={filterFields.length === 0}
              aria-label={t("reports_page.add_filter", "Add filter")}
            >
              <Plus className="h-3 w-3" />
            </Button>
          </CardHeader>
          <CardContent className="space-y-2">
            {filterFields.length === 0 ? (
              <p className="text-xs text-muted-foreground">
                {t(
                  "reports_page.source_not_filterable",
                  "This data source cannot be filtered.",
                )}
              </p>
            ) : filters.length === 0 ? (
              <p className="text-xs text-muted-foreground">
                {t("reports_page.no_filters")}
              </p>
            ) : (
              filters.map((f, i) => (
                <div key={i} className="flex gap-1">
                  <Select
                    value={f.field}
                    onValueChange={(v) =>
                      updateFilter(i, { field: v, value: "" })
                    }
                  >
                    <SelectTrigger
                      className="h-8 flex-1"
                      aria-label={t(
                        "reports_page.filter_field",
                        "Filter field",
                      )}
                    >
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {filterFields.map((field) => (
                        <SelectItem key={field} value={field}>
                          {reportFieldLabel(t, field)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <ReportFilterValue
                    source={config.source}
                    field={f.field}
                    value={f.value}
                    onChange={(value) => updateFilter(i, { value })}
                    label={`${t("reports_page.value")}: ${reportFieldLabel(t, f.field)}`}
                  />
                  <Button
                    size="sm"
                    variant="ghost"
                    className="h-8 w-8 p-0"
                    onClick={() => removeFilter(i)}
                    aria-label={[
                      t("reports_page.remove_filter", "Remove filter"),
                      f.field ? reportFieldLabel(t, f.field) : "",
                    ]
                      .filter(Boolean)
                      .join(": ")}
                  >
                    <X className="h-3 w-3" aria-hidden="true" />
                  </Button>
                </div>
              ))
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="flex items-center gap-2 text-base">
              <Group className="h-4 w-4" /> {t("reports_page.group_by")}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <Select
              value={config.group_by ?? "__none__"}
              onValueChange={(v) =>
                setConfig((p) => ({
                  ...p,
                  group_by: v === "__none__" ? undefined : v,
                }))
              }
            >
              <SelectTrigger aria-label={t("reports_page.group_by")}>
                <SelectValue placeholder={t("reports_page.no_grouping")} />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="__none__">
                  {t("reports_page.no_grouping")}
                </SelectItem>
                {availableFields.map((field) => (
                  <SelectItem key={field} value={field}>
                    {reportFieldLabel(t, field)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="flex items-center gap-2 text-base">
              <ArrowDownUp className="h-4 w-4" /> {t("reports_page.sort")}
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            <Select
              value={config.sort_by ?? "__none__"}
              onValueChange={(v) =>
                setConfig((p) => ({
                  ...p,
                  sort_by: v === "__none__" ? undefined : v,
                }))
              }
            >
              <SelectTrigger aria-label={t("reports_page.sort")}>
                <SelectValue placeholder={t("reports_page.no_sorting")} />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="__none__">
                  {t("reports_page.no_sorting")}
                </SelectItem>
                {availableFields.map((field) => (
                  <SelectItem key={field} value={field}>
                    {reportFieldLabel(t, field)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {config.sort_by && (
              <Select
                value={config.sort_dir ?? "asc"}
                onValueChange={(v) =>
                  setConfig((p) => ({ ...p, sort_dir: v as "asc" | "desc" }))
                }
              >
                <SelectTrigger
                  aria-label={t(
                    "reports_page.sort_direction",
                    "Sort direction",
                  )}
                >
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="asc">
                    {t("reports_page.ascending")}
                  </SelectItem>
                  <SelectItem value="desc">
                    {t("reports_page.descending")}
                  </SelectItem>
                </SelectContent>
              </Select>
            )}
          </CardContent>
        </Card>

        <div className="flex flex-col gap-2">
          <Button onClick={handlePreview} disabled={generate.isPending}>
            {generate.isPending ? (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            ) : (
              <Eye className="mr-2 h-4 w-4" />
            )}
            {t("reports_page.run_preview")}
          </Button>
          {generate.data && (
            <>
              <Button variant="outline" onClick={downloadCsv}>
                <Download className="mr-2 h-4 w-4" />{" "}
                {t("reports_page.download_csv")}
              </Button>
              {canSaveTemplates && (
                <Button variant="outline" onClick={() => setSaveOpen(true)}>
                  <Save className="mr-2 h-4 w-4" />{" "}
                  {t("reports_page.save_as_template")}
                </Button>
              )}
            </>
          )}
        </div>
      </div>

      {/* Preview area */}
      <div>
        {!generate.data && !generate.isPending && (
          <Card>
            <CardContent className="p-12 text-center text-muted-foreground">
              <FileSpreadsheet className="mx-auto h-12 w-12 opacity-30" />
              <p className="mt-4 text-sm">
                {t("reports_page.configure_hint")}{" "}
                <span className="font-semibold">
                  {t("reports_page.run_preview")}
                </span>
              </p>
            </CardContent>
          </Card>
        )}

        {generate.isPending && (
          <Card>
            <CardContent className="p-12 text-center">
              <Loader2 className="mx-auto h-10 w-10 animate-spin text-primary" />
              <p className="mt-3 text-sm text-muted-foreground">
                {t("reports_page.generating")}
              </p>
            </CardContent>
          </Card>
        )}

        {generate.data && <PreviewResult result={generate.data} />}
      </div>

      <SaveReportDialog
        open={saveOpen}
        onClose={() => setSaveOpen(false)}
        config={buildPayload()}
      />
    </div>
  );
}

export function SaveReportDialog({
  open,
  onClose,
  config,
}: {
  open: boolean;
  onClose: () => void;
  config: ReportConfig;
}) {
  const { t } = useT();
  const [name, setName] = useState("");
  const save = useSaveReport();

  function handleSave() {
    if (!name.trim()) return;
    save.mutate(
      { name: name.trim(), config },
      {
        onSuccess: () => {
          toast.success(t("reports_page.template_saved"));
          setName("");
          onClose();
        },
        onError: (err) =>
          toast.error(
            apiErrorMessage(err, t("reports_page.save_template_failed")),
          ),
      },
    );
  }

  return (
    <Dialog open={open} onOpenChange={onClose}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t("reports_page.save_report_template")}</DialogTitle>
          <DialogDescription>
            {t("reports_page.save_template_desc")}
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-3">
          <div>
            <Label>{t("reports_page.template_name")}</Label>
            <Input
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder={t("reports_page.template_name_placeholder")}
              className="mt-1"
              autoFocus
            />
          </div>
          <div className="rounded-lg border bg-muted/30 p-3">
            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              {t("reports_page.configuration")}
            </p>
            <div className="mt-2 space-y-1 text-xs">
              <p>
                <span className="text-muted-foreground">
                  {t("reports_page.source")}:
                </span>{" "}
                <span className="font-mono">{config.source}</span>
              </p>
              {config.columns && (
                <p>
                  <span className="text-muted-foreground">
                    {t("reports_page.columns")}:
                  </span>{" "}
                  {config.columns.length}
                </p>
              )}
              {config.filters && (
                <p>
                  <span className="text-muted-foreground">
                    {t("reports_page.filters")}:
                  </span>{" "}
                  {Object.keys(config.filters).length}
                </p>
              )}
              {config.group_by && (
                <p>
                  <span className="text-muted-foreground">
                    {t("reports_page.group_by")}:
                  </span>{" "}
                  <span className="font-mono">{config.group_by}</span>
                </p>
              )}
              {config.sort_by && (
                <p>
                  <span className="text-muted-foreground">
                    {t("reports_page.sort")}:
                  </span>{" "}
                  <span className="font-mono">
                    {config.sort_by} {config.sort_dir ?? "asc"}
                  </span>
                </p>
              )}
            </div>
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t("common.cancel")}
          </Button>
          <Button
            onClick={handleSave}
            disabled={save.isPending || !name.trim()}
          >
            {save.isPending && (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            )}
            {t("reports_page.save_template")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ── QUICK REPORTS TAB ──────────────────────────────────────────
