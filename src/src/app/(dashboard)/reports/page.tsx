"use client";

import { useState, useMemo } from "react";
import {
  BarChart3,
  Download,
  Loader2,
  Users,
  Clock,
  CalendarDays,
  Wallet,
  Database,
  Columns3,
  ListFilter,
  Group,
  ArrowDownUp,
  Eye,
  Save,
  Bookmark,
  CalendarClock,
  Trash2,
  Mail,
  Plus,
  X,
  AlertCircle,
  FileSpreadsheet,
  Play,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
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
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { RoleGate } from "@/components/shared/role-gate";
import {
  useReportSources,
  useGenerateReport,
  useSavedReports,
  useSaveReport,
  useDeleteSavedReport,
  useScheduledReports,
  useScheduleReport,
  useDeleteScheduledReport,
  type ReportConfig,
  type ReportSources,
  type SavedReport,
} from "@/features/reports/api";
import { useMutation } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { toast } from "sonner";
import { cn } from "@/lib/utils";

const prebuilt = [
  {
    key: "employees",
    icon: Users,
    title: "Employee Directory",
    description: "Full employee listing",
    color: "text-blue-600",
  },
  {
    key: "attendance",
    icon: Clock,
    title: "Attendance Summary",
    description: "Attendance records",
    color: "text-green-600",
  },
  {
    key: "leave",
    icon: CalendarDays,
    title: "Leave Balance Report",
    description: "Leave balances for all",
    color: "text-purple-600",
  },
  {
    key: "payroll",
    icon: Wallet,
    title: "Payroll Register",
    description: "Detailed payroll entries",
    color: "text-amber-600",
  },
];

export default function ReportsPage() {
  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <PageHeader
          title="Reports"
          description="Build custom reports, save templates, and schedule recurring delivery"
        />

        <Tabs defaultValue="builder">
          <TabsList>
            <TabsTrigger value="builder">Builder</TabsTrigger>
            <TabsTrigger value="quick">Quick Reports</TabsTrigger>
            <TabsTrigger value="saved">Saved</TabsTrigger>
            <TabsTrigger value="scheduled">Scheduled</TabsTrigger>
          </TabsList>

          <TabsContent value="builder" className="mt-4">
            <BuilderTab />
          </TabsContent>
          <TabsContent value="quick" className="mt-4">
            <QuickTab />
          </TabsContent>
          <TabsContent value="saved" className="mt-4">
            <SavedTab />
          </TabsContent>
          <TabsContent value="scheduled" className="mt-4">
            <ScheduledTab />
          </TabsContent>
        </Tabs>
      </div>
    </RoleGate>
  );
}

// ── BUILDER TAB ────────────────────────────────────────────────

interface FilterRow {
  field: string;
  value: string;
}

function BuilderTab() {
  const { data: sourcesData, isLoading: sourcesLoading } = useReportSources();
  const generate = useGenerateReport();
  const [config, setConfig] = useState<ReportConfig>({ source: "employees" });
  const [filters, setFilters] = useState<FilterRow[]>([]);
  const [saveOpen, setSaveOpen] = useState(false);

  const sources: ReportSources = sourcesData?.sources ?? {};
  const sourceMeta = sources[config.source];
  const availableFields = sourceMeta?.fields ?? [];
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
    setFilters((p) => [...p, { field: availableFields[0] ?? "", value: "" }]);
  }

  function updateFilter(i: number, patch: Partial<FilterRow>) {
    setFilters((p) => p.map((f, idx) => (idx === i ? { ...f, ...patch } : f)));
  }

  function removeFilter(i: number) {
    setFilters((p) => p.filter((_, idx) => idx !== i));
  }

  function changeSource(source: string) {
    setConfig({ source });
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
      onError: () => toast.error("Failed to generate report"),
    });
  }

  function downloadCsv() {
    const result = generate.data;
    if (!result?.data?.length) return;
    const headers = Object.keys(result.data[0]);
    const csv = [
      headers.join(","),
      ...result.data.map((row) =>
        headers
          .map((h) => {
            const v = row[h];
            const s = v == null ? "" : String(v);
            return s.includes(",") || s.includes('"') || s.includes("\n")
              ? `"${s.replace(/"/g, '""')}"`
              : s;
          })
          .join(","),
      ),
    ].join("\n");
    const blob = new Blob([csv], { type: "text/csv" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `${config.source}-report-${new Date().toISOString().split("T")[0]}.csv`;
    link.click();
    URL.revokeObjectURL(url);
  }

  if (sourcesLoading) return <Skeleton className="h-96 w-full" />;

  return (
    <div className="grid gap-6 lg:grid-cols-[320px_1fr]">
      {/* Configuration sidebar */}
      <div className="space-y-4">
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="flex items-center gap-2 text-base">
              <Database className="h-4 w-4" /> Data Source
            </CardTitle>
          </CardHeader>
          <CardContent>
            <Select value={config.source} onValueChange={changeSource}>
              <SelectTrigger>
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
              {availableFields.length} available fields
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3 flex flex-row items-center justify-between">
            <CardTitle className="flex items-center gap-2 text-base">
              <Columns3 className="h-4 w-4" /> Columns
              <Badge variant="outline" className="ml-1 text-[10px]">
                {selectedColumns.length || "All"}
              </Badge>
            </CardTitle>
            <div className="flex gap-1">
              <Button
                size="sm"
                variant="ghost"
                className="h-6 text-xs px-2"
                onClick={selectAllColumns}
              >
                All
              </Button>
              <Button
                size="sm"
                variant="ghost"
                className="h-6 text-xs px-2"
                onClick={clearColumns}
              >
                Clear
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
                  <span className="font-mono text-xs">{field}</span>
                </label>
              ))}
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3 flex flex-row items-center justify-between">
            <CardTitle className="flex items-center gap-2 text-base">
              <ListFilter className="h-4 w-4" /> Filters
            </CardTitle>
            <Button
              size="sm"
              variant="ghost"
              className="h-6 px-2"
              onClick={addFilter}
            >
              <Plus className="h-3 w-3" />
            </Button>
          </CardHeader>
          <CardContent className="space-y-2">
            {filters.length === 0 ? (
              <p className="text-xs text-muted-foreground">
                No filters. Click + to add one.
              </p>
            ) : (
              filters.map((f, i) => (
                <div key={i} className="flex gap-1">
                  <Select
                    value={f.field}
                    onValueChange={(v) => updateFilter(i, { field: v })}
                  >
                    <SelectTrigger className="h-8 flex-1">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {availableFields.map((field) => (
                        <SelectItem key={field} value={field}>
                          {field}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <Input
                    value={f.value}
                    onChange={(e) => updateFilter(i, { value: e.target.value })}
                    placeholder="value"
                    className="h-8 flex-1"
                  />
                  <Button
                    size="sm"
                    variant="ghost"
                    className="h-8 w-8 p-0"
                    onClick={() => removeFilter(i)}
                  >
                    <X className="h-3 w-3" />
                  </Button>
                </div>
              ))
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="flex items-center gap-2 text-base">
              <Group className="h-4 w-4" /> Group By
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
              <SelectTrigger>
                <SelectValue placeholder="No grouping" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="__none__">No grouping</SelectItem>
                {availableFields.map((field) => (
                  <SelectItem key={field} value={field}>
                    {field}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="flex items-center gap-2 text-base">
              <ArrowDownUp className="h-4 w-4" /> Sort
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
              <SelectTrigger>
                <SelectValue placeholder="No sorting" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="__none__">No sorting</SelectItem>
                {availableFields.map((field) => (
                  <SelectItem key={field} value={field}>
                    {field}
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
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="asc">Ascending</SelectItem>
                  <SelectItem value="desc">Descending</SelectItem>
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
            Run Preview
          </Button>
          {generate.data && (
            <>
              <Button variant="outline" onClick={downloadCsv}>
                <Download className="mr-2 h-4 w-4" /> Download CSV
              </Button>
              <Button variant="outline" onClick={() => setSaveOpen(true)}>
                <Save className="mr-2 h-4 w-4" /> Save as Template
              </Button>
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
                Configure your report on the left, then click{" "}
                <span className="font-semibold">Run Preview</span>
              </p>
            </CardContent>
          </Card>
        )}

        {generate.isPending && (
          <Card>
            <CardContent className="p-12 text-center">
              <Loader2 className="mx-auto h-10 w-10 animate-spin text-primary" />
              <p className="mt-3 text-sm text-muted-foreground">
                Generating report…
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

function PreviewResult({
  result,
}: {
  result: {
    source: string;
    total: number;
    data: Array<Record<string, unknown>>;
    summary: { grouped_by?: string; groups?: Record<string, number> };
  };
}) {
  const headers = useMemo(
    () => (result.data.length > 0 ? Object.keys(result.data[0]) : []),
    [result.data],
  );
  const groups = result.summary?.groups;

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <p className="text-sm text-muted-foreground">
            <span className="capitalize">{result.source}</span> · {result.total}{" "}
            records
          </p>
        </div>
      </div>

      {groups && Object.keys(groups).length > 0 && (
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm">
              Grouped by {result.summary.grouped_by}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="flex flex-wrap gap-2">
              {Object.entries(groups).map(([key, count]) => (
                <Badge key={key} variant="outline" className="text-xs">
                  {key}: <span className="ml-1 font-semibold">{count}</span>
                </Badge>
              ))}
            </div>
          </CardContent>
        </Card>
      )}

      {result.data.length === 0 ? (
        <EmptyState
          icon={FileSpreadsheet}
          title="No data"
          description="The query returned no rows"
        />
      ) : (
        <Card>
          <CardContent className="p-0">
            <div className="overflow-x-auto max-h-[calc(100vh-280px)]">
              <table className="w-full text-sm">
                <thead className="sticky top-0 bg-muted">
                  <tr className="border-b">
                    {headers.map((col) => (
                      <th
                        key={col}
                        className="px-3 py-2 text-left font-medium text-muted-foreground capitalize whitespace-nowrap"
                      >
                        {col.replace(/_/g, " ").replace(/cents/i, "(¢)")}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {result.data.slice(0, 200).map((row, i) => (
                    <tr
                      key={i}
                      className="border-b last:border-0 hover:bg-muted/30"
                    >
                      {headers.map((h) => (
                        <td
                          key={h}
                          className="px-3 py-2 text-foreground whitespace-nowrap"
                        >
                          {row[h] == null ? "—" : String(row[h])}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {result.data.length > 200 && (
              <div className="border-t bg-muted/30 px-4 py-2 text-xs text-muted-foreground">
                Showing first 200 of {result.data.length} rows. Download CSV for
                full data.
              </div>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  );
}

function SaveReportDialog({
  open,
  onClose,
  config,
}: {
  open: boolean;
  onClose: () => void;
  config: ReportConfig;
}) {
  const [name, setName] = useState("");
  const save = useSaveReport();

  function handleSave() {
    if (!name.trim()) return;
    save.mutate(
      { name: name.trim(), config },
      {
        onSuccess: () => {
          toast.success("Report template saved");
          setName("");
          onClose();
        },
        onError: () => toast.error("Failed to save template"),
      },
    );
  }

  return (
    <Dialog open={open} onOpenChange={onClose}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Save Report Template</DialogTitle>
          <DialogDescription>
            Saved templates can be re-run and scheduled for automatic delivery.
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-3">
          <div>
            <Label>Template Name</Label>
            <Input
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="e.g. Monthly Active Employees"
              className="mt-1"
              autoFocus
            />
          </div>
          <div className="rounded-lg border bg-muted/30 p-3">
            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              Configuration
            </p>
            <div className="mt-2 space-y-1 text-xs">
              <p>
                <span className="text-muted-foreground">Source:</span>{" "}
                <span className="font-mono">{config.source}</span>
              </p>
              {config.columns && (
                <p>
                  <span className="text-muted-foreground">Columns:</span>{" "}
                  {config.columns.length}
                </p>
              )}
              {config.filters && (
                <p>
                  <span className="text-muted-foreground">Filters:</span>{" "}
                  {Object.keys(config.filters).length}
                </p>
              )}
              {config.group_by && (
                <p>
                  <span className="text-muted-foreground">Group by:</span>{" "}
                  <span className="font-mono">{config.group_by}</span>
                </p>
              )}
              {config.sort_by && (
                <p>
                  <span className="text-muted-foreground">Sort:</span>{" "}
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
            Cancel
          </Button>
          <Button
            onClick={handleSave}
            disabled={save.isPending || !name.trim()}
          >
            {save.isPending && (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            )}
            Save Template
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ── QUICK REPORTS TAB ──────────────────────────────────────────

function QuickTab() {
  const generate = useGenerateReport();

  function handleGenerate(source: string) {
    generate.mutate(
      { source },
      {
        onSuccess: () =>
          toast.success(`Generated ${generate.data?.total ?? 0} records`),
        onError: () => toast.error("Failed to generate report"),
      },
    );
  }

  function downloadCsv() {
    const result = generate.data;
    if (!result?.data?.length) return;
    const headers = Object.keys(result.data[0]);
    const csv = [
      headers.join(","),
      ...result.data.map((row) =>
        headers
          .map((h) => {
            const v = row[h];
            const s = v == null ? "" : String(v);
            return s.includes(",") || s.includes('"')
              ? `"${s.replace(/"/g, '""')}"`
              : s;
          })
          .join(","),
      ),
    ].join("\n");
    const blob = new Blob([csv], { type: "text/csv" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `${result.source}-${new Date().toISOString().split("T")[0]}.csv`;
    link.click();
    URL.revokeObjectURL(url);
  }

  return (
    <div className="space-y-4">
      <div className="grid gap-4 sm:grid-cols-2">
        {prebuilt.map((r) => {
          const Icon = r.icon;
          const isLoading =
            generate.isPending && generate.variables?.source === r.key;
          return (
            <Card key={r.key} className="hover:shadow-md transition-shadow">
              <CardHeader className="pb-3">
                <div className="flex items-center gap-3">
                  <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
                    <Icon className={cn("h-5 w-5", r.color)} />
                  </div>
                  <CardTitle className="text-base">{r.title}</CardTitle>
                </div>
              </CardHeader>
              <CardContent>
                <p className="mb-4 text-sm text-muted-foreground">
                  {r.description}
                </p>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => handleGenerate(r.key)}
                  disabled={isLoading}
                >
                  {isLoading ? (
                    <Loader2 className="mr-2 h-3 w-3 animate-spin" />
                  ) : (
                    <Play className="mr-2 h-3 w-3" />
                  )}
                  Run
                </Button>
              </CardContent>
            </Card>
          );
        })}
      </div>

      {generate.data && (
        <>
          <div className="flex items-center justify-between">
            <p className="text-sm text-muted-foreground">
              {generate.data.total} records ·{" "}
              <span className="capitalize">{generate.data.source}</span>
            </p>
            <Button size="sm" onClick={downloadCsv}>
              <Download className="mr-2 h-3 w-3" /> Download CSV
            </Button>
          </div>
          <PreviewResult result={generate.data} />
        </>
      )}
    </div>
  );
}

// ── SAVED TAB ──────────────────────────────────────────────────

function SavedTab() {
  const { data, isLoading } = useSavedReports();
  const deleteReport = useDeleteSavedReport();
  const runReport = useGenerateReport();
  const [scheduleFor, setScheduleFor] = useState<SavedReport | null>(null);
  const [resultFor, setResultFor] = useState<string | null>(null);

  function handleRun(r: SavedReport) {
    runReport.mutate(r.config, {
      onSuccess: () => {
        setResultFor(r.public_id);
        toast.success(`Generated ${runReport.data?.total ?? 0} records`);
      },
      onError: () => toast.error("Failed to run report"),
    });
  }

  function handleDelete(r: SavedReport) {
    if (!confirm(`Delete saved report "${r.name}"?`)) return;
    deleteReport.mutate(r.public_id, {
      onSuccess: () => toast.success("Template deleted"),
    });
  }

  const reports = data?.reports ?? [];

  return (
    <div className="space-y-4">
      {isLoading ? (
        <div className="space-y-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-20" />
          ))}
        </div>
      ) : reports.length === 0 ? (
        <EmptyState
          icon={Bookmark}
          title="No saved templates"
          description="Build a report and save it as a template to reuse it later"
        />
      ) : (
        <div className="grid gap-3">
          {reports.map((r) => (
            <Card key={r.public_id}>
              <CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex-1">
                  <p className="font-semibold text-foreground">{r.name}</p>
                  <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                    <Badge variant="outline" className="capitalize">
                      {r.config.source}
                    </Badge>
                    {r.config.columns && (
                      <span>· {r.config.columns.length} columns</span>
                    )}
                    {r.config.filters && (
                      <span>
                        · {Object.keys(r.config.filters).length} filters
                      </span>
                    )}
                    {r.config.group_by && (
                      <span>· grouped by {r.config.group_by}</span>
                    )}
                    <span>
                      · saved {new Date(r.created_at).toLocaleDateString()}
                    </span>
                  </div>
                </div>
                <div className="flex gap-1">
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => handleRun(r)}
                    disabled={runReport.isPending}
                  >
                    <Play className="mr-1 h-3 w-3" /> Run
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setScheduleFor(r)}
                  >
                    <CalendarClock className="mr-1 h-3 w-3" /> Schedule
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => handleDelete(r)}
                  >
                    <Trash2 className="h-3 w-3 text-destructive" />
                  </Button>
                </div>
              </CardContent>
              {resultFor === r.public_id && runReport.data && (
                <CardContent className="border-t pt-4">
                  <PreviewResult result={runReport.data} />
                </CardContent>
              )}
            </Card>
          ))}
        </div>
      )}

      {scheduleFor && (
        <ScheduleDialog
          report={scheduleFor}
          onClose={() => setScheduleFor(null)}
        />
      )}
    </div>
  );
}

function ScheduleDialog({
  report,
  onClose,
}: {
  report: SavedReport;
  onClose: () => void;
}) {
  const schedule = useScheduleReport();
  const [frequency, setFrequency] = useState<"daily" | "weekly" | "monthly">(
    "weekly",
  );
  const [emailInput, setEmailInput] = useState("");
  const [recipients, setRecipients] = useState<string[]>([]);

  function addEmail() {
    const e = emailInput.trim().toLowerCase();
    if (!e || !e.includes("@")) {
      toast.error("Enter a valid email");
      return;
    }
    if (recipients.includes(e)) return;
    setRecipients((p) => [...p, e]);
    setEmailInput("");
  }

  function handleSchedule() {
    if (recipients.length === 0) {
      toast.error("Add at least one recipient");
      return;
    }
    schedule.mutate(
      { saved_report_public_id: report.public_id, frequency, recipients },
      {
        onSuccess: () => {
          toast.success("Report scheduled");
          onClose();
        },
        onError: () => toast.error("Failed to schedule report"),
      },
    );
  }

  return (
    <Dialog open onOpenChange={onClose}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Schedule Report</DialogTitle>
          <DialogDescription>
            <span className="font-medium">{report.name}</span> will be sent
            automatically.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div>
            <Label>Frequency</Label>
            <Select
              value={frequency}
              onValueChange={(v) =>
                setFrequency(v as "daily" | "weekly" | "monthly")
              }
            >
              <SelectTrigger className="mt-1">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="daily">Daily — 6:00 AM</SelectItem>
                <SelectItem value="weekly">Weekly — Monday 6:00 AM</SelectItem>
                <SelectItem value="monthly">
                  Monthly — 1st of month 6:00 AM
                </SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div>
            <Label>Recipients</Label>
            <div className="mt-1 flex gap-2">
              <Input
                type="email"
                value={emailInput}
                onChange={(e) => setEmailInput(e.target.value)}
                onKeyDown={(e) =>
                  e.key === "Enter" && (e.preventDefault(), addEmail())
                }
                placeholder="hr@example.com"
              />
              <Button type="button" variant="outline" onClick={addEmail}>
                Add
              </Button>
            </div>
            {recipients.length > 0 && (
              <div className="mt-2 flex flex-wrap gap-1">
                {recipients.map((r) => (
                  <Badge
                    key={r}
                    variant="outline"
                    className="flex items-center gap-1 text-xs"
                  >
                    <Mail className="h-3 w-3" /> {r}
                    <button
                      onClick={() =>
                        setRecipients((p) => p.filter((x) => x !== r))
                      }
                      className="ml-1 hover:text-destructive"
                    >
                      <X className="h-3 w-3" />
                    </button>
                  </Badge>
                ))}
              </div>
            )}
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button
            onClick={handleSchedule}
            disabled={schedule.isPending || recipients.length === 0}
          >
            {schedule.isPending && (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            )}
            Schedule
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ── SCHEDULED TAB ──────────────────────────────────────────────

function ScheduledTab() {
  const { data, isLoading } = useScheduledReports();
  const deleteScheduled = useDeleteScheduledReport();

  function handleDelete(publicId: string, name: string) {
    if (!confirm(`Cancel scheduled report "${name}"?`)) return;
    deleteScheduled.mutate(publicId, {
      onSuccess: () => toast.success("Schedule cancelled"),
    });
  }

  const schedules = data?.schedules ?? [];

  return (
    <div className="space-y-4">
      {isLoading ? (
        <div className="space-y-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-20" />
          ))}
        </div>
      ) : schedules.length === 0 ? (
        <EmptyState
          icon={CalendarClock}
          title="No scheduled reports"
          description="Save a template, then schedule it from the Saved tab"
        />
      ) : (
        <div className="grid gap-3">
          {schedules.map((s) => (
            <Card key={s.public_id}>
              <CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2">
                    <p className="font-semibold text-foreground truncate">
                      {s.report_name}
                    </p>
                    <Badge variant="outline" className="capitalize text-[10px]">
                      {s.frequency}
                    </Badge>
                  </div>
                  <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                    <span className="flex items-center gap-1">
                      <Mail className="h-3 w-3" /> {s.recipients.length}{" "}
                      recipient{s.recipients.length > 1 ? "s" : ""}
                    </span>
                    <span className="flex items-center gap-1">
                      <CalendarClock className="h-3 w-3" /> next:{" "}
                      {new Date(s.next_run_at).toLocaleString()}
                    </span>
                    {s.last_run_at && (
                      <span>
                        last: {new Date(s.last_run_at).toLocaleString()}
                      </span>
                    )}
                  </div>
                  <div className="mt-2 flex flex-wrap gap-1">
                    {s.recipients.map((r) => (
                      <Badge key={r} variant="outline" className="text-[10px]">
                        {r}
                      </Badge>
                    ))}
                  </div>
                </div>
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() => handleDelete(s.public_id, s.report_name)}
                >
                  <Trash2 className="h-4 w-4 text-destructive" />
                </Button>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
