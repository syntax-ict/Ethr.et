"use client";

import { useMemo, useRef, useState } from "react";
import Link from "next/link";
import {
  Loader2,
  HardDrive,
  FileSpreadsheet,
  Check,
  Download,
  Upload,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";

import { Badge } from "@/components/ui/badge";
import { SimpleTable } from "@/components/shared/simple-table";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useT } from "@/lib/i18n/useT";
import { useConfirm } from "@/components/shared/use-confirm";
import { apiErrorMessage } from "@/lib/api/error-message";
import { toast } from "sonner";
import { useDevices } from "@/features/devices/api";
import { saveBlob, saveCsv } from "@/lib/utils/csv-export";
import { useOptionalCalendar } from "@/lib/calendar/calendar-context";
import { useDebounce } from "@/lib/hooks/useDebounce";
import {
  CommitStalled,
  listColumns,
  useCommitMigration,
  useImportSources,
  useMigrationBatch,
  useReadPeople,
  useSetRowAction,
  useStageFromDevice,
  useStageFromRows,
} from "../api";
import type { ColumnMatch, MatchOutcome, StagingAction } from "../types";
import {
  type DateCalendar,
  MAX_PEOPLE,
  templateCsv,
  toGrid,
} from "../parse-people";
import { fetchEmployeeImportTemplate } from "@/features/employees/api";
import { importColumnHelp } from "@/features/employees/components/import-column-guide";
import {
  buildTemplate,
  gridToText,
  MAX_LINES,
  NoSheet,
  readWorkbook,
  TooManyLines,
} from "../xlsx-grid";
import { ColumnMatcher } from "./column-matcher";
import { MigrationLogins } from "./migration-logins";
import { StagedChanges } from "./staged-changes";
import { matchReasonLabel } from "@/features/employees/likeness";

const ACTIONS: StagingAction[] = ["merge", "create", "skip", "defer"];

const reasonLabel = matchReasonLabel;

function outcomeTone(outcome: MatchOutcome | null): string {
  switch (outcome) {
    case "matched":
      return "var(--color-status-success, #047857)";
    case "probable":
    case "archived":
      return "var(--color-status-warning, #92400E)";
    case "ambiguous":
      return "var(--color-status-error, #B91C1C)";
    default:
      return "var(--color-text-secondary, #64748B)";
  }
}

/** A column the server read, by the name it already has in the dictionary. */
const COLUMN_LABELS: Record<string, [string, string]> = {
  name: ["setup2.col_name", "Name"],
  name_am: ["employee.name_am", "Name in Amharic"],
  email: ["setup2.col_email", "Email"],
  phone: ["setup2.col_phone", "Phone"],
  employee_code: ["setup2.col_employee_code", "Employee number"],
  badge_number: ["setup2.col_badge_number", "Badge number"],
  hire_date: ["setup2.col_hire_date", "Hire date"],
  date_of_birth: ["employee.detail.date_of_birth", "Date of birth"],
  gender: ["common.gender", "Gender"],
  department_code: ["setup2.col_department", "Department"],
  branch_code: ["common.branch", "Branch"],
  position_code: ["common.position", "Position"],
  salary: ["employee.detail.salary", "Salary"],
  salary_cents: ["employee.detail.salary", "Salary"],
  national_id: ["employee.national_id", "National ID"],
  tin: ["employee.tin", "TIN"],
  nationality: ["employee.detail.nationality", "Nationality"],
  nationality_status: ["employee.nationality_status", "Ethiopian national?"],
  marital_status: ["employee.detail.marital_status", "Marital status"],
  status: ["common.status", "Status"],
  external_id: ["setup2.col_external_id", "ID in the other system"],
};

/** Problems shown under the list before the rest are counted. */
const PROBLEMS_SHOWN = 5;

/** A file this large is not a list of people; it is not read into the page. */
const FILE_MAX_BYTES = 5 * 1024 * 1024;

const XLSX_MIME =
  "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";

/** How long the list rests before the server is asked what it makes of it. */
const READ_AFTER_MS = 400;

/**
 * The lines a refused staging names, from the server's validation errors
 * (`grid.2: ["Line 3: ..."]`), in the order of the list.
 */
function refusedLines(err: unknown): string[] {
  const errors = (
    err as { response?: { data?: { errors?: Record<string, string[]> } } }
  )?.response?.data?.errors;
  if (!errors) return [];
  return Object.entries(errors)
    .filter(([key]) => key === "grid" || key.startsWith("grid."))
    .sort(
      ([a], [b]) =>
        Number(a.split(".")[1] ?? -1) - Number(b.split(".")[1] ?? -1),
    )
    .flatMap(([, messages]) => messages);
}

/**
 * The one review every list of people goes through: the guided setup, a
 * device's roster, and the employee import.
 *
 * @param onCommitted Called when the reviewer moves on after the commit, from
 *   the "give them a way to sign in" step, not at the commit itself.
 * @param initialDeviceId Preselects the device, when opened from that device.
 * @param sources "list" leaves the attendance device out: the employee import
 *   is about a file.
 * @param title Heading, where the page is not the guided setup.
 * @param description The line under it.
 */
export function MigrationStep({
  onCommitted,
  initialDeviceId,
  sources = "all",
  title,
  description,
}: {
  onCommitted?: () => void;
  initialDeviceId?: string;
  sources?: "all" | "list";
  title?: string;
  description?: string;
}) {
  const { t } = useT();
  const { requestConfirm, confirmDialog } = useConfirm();
  const devicesQuery = useDevices();
  const stageDevice = useStageFromDevice();
  const stageRows = useStageFromRows();
  const setAction = useSetRowAction();
  // Rows a long list still has to add, while it is being added.
  const [remaining, setRemaining] = useState<number | null>(null);
  const commit = useCommitMigration((result) => setRemaining(result.remaining));

  const [batchId, setBatchId] = useState<string | null>(null);
  const [deviceId, setDeviceId] = useState<string>(initialDeviceId ?? "");
  const [csv, setCsv] = useState("");
  const fileInput = useRef<HTMLInputElement>(null);

  const batchQuery = useMigrationBatch(batchId);
  const batch = batchQuery.data;

  const devices = devicesQuery.data?.data ?? [];
  // One device is the device: there is nothing to choose.
  const device = deviceId || (devices.length === 1 ? devices[0].public_id : "");
  // Hire dates are read in the reader's own calendar unless they say otherwise:
  // an Ethiopian 2011-10-30 read as Gregorian is eight years out.
  const preferred = useOptionalCalendar()?.calendar ?? "gregorian";
  const [chosenCalendar, setChosenCalendar] = useState<DateCalendar | null>(
    null,
  );
  const dateCalendar = chosenCalendar ?? preferred;
  // The server reads the list: what each column is, each date, each line that
  // cannot be staged. The browser only splits it into cells, once it rests.
  const rested = useDebounce(csv, READ_AFTER_MS);
  const grid = useMemo(() => toGrid(rested), [rested]);
  // A list from another system names its columns its own way. They are
  // matched here (`matched`), or already saved under that system's name
  // (`sourceId`), and the server reads the list as it is told.
  const sourcesQuery = useImportSources(batchId === null);
  const savedSources = useMemo(
    () => sourcesQuery.data ?? [],
    [sourcesQuery.data],
  );
  const [sourceId, setSourceId] = useState<string | null>(null);
  const [matched, setMatched] = useState<ColumnMatch[] | null>(null);
  const [matching, setMatching] = useState(false);
  const source = savedSources.find((s) => s.public_id === sourceId) ?? null;
  const named = useMemo(
    () => ({ source: sourceId, mapping: matched }),
    [sourceId, matched],
  );
  const readQuery = useReadPeople(grid, dateCalendar, batchId === null, named);
  const read = csv.trim() === "" ? undefined : readQuery.data;
  // A saved source whose column names this list has, offered once the
  // reader has not already said where the list is from.
  const suggested =
    sourceId === null && matched === null && read?.suggested_source
      ? (savedSources.find((s) => s.public_id === read.suggested_source) ??
        null)
      : null;
  // Each column name with its answer: the reader's own where they have
  // given one. The server's reading lags a choice by a request, and an
  // answer rebuilt from it would undo the choice made just before.
  const matches = useMemo<ColumnMatch[]>(
    () =>
      (read?.headers ?? []).map((h) => {
        const said = matched?.find((m) => m.header === h.header);
        return { header: h.header, column: said ? said.column : h.column };
      }),
    [read, matched],
  );
  // The columns were matched and none of them is the name.
  const nameless =
    read?.needs_name === true ||
    (matched !== null && !matches.some((m) => m.column === "name"));
  // A value under each column name, from the first person in the list.
  const samples = useMemo(() => {
    const lines = grid.filter((line) => line.some((cell) => cell !== ""));
    const [names = [], first = []] = lines;
    const found: Record<string, string> = {};
    names.forEach((cell, index) => {
      const header = cell.trim();
      if (header !== "" && found[header] === undefined) {
        found[header] = (first[index] ?? "").trim();
      }
    });
    return found;
  }, [grid]);
  const reading = csv.trim() !== "" && (rested !== csv || readQuery.isFetching);
  // What a staging the server refused said, until the list changes.
  const [refused, setRefused] = useState<{ csv: string; lines: string[] }>({
    csv: "",
    lines: [],
  });
  const problems =
    refused.csv === csv && refused.lines.length > 0
      ? refused.lines
      : (read?.problems ?? []).map((p) =>
          t("setup2.problem_line", "Line :line: :message", {
            line: p.line,
            message: p.message,
          }),
        );
  const listOnly = sources === "list";
  const noDevice = listOnly || (devicesQuery.isSuccess && devices.length === 0);
  // The lines that cannot be staged may be left out, once the server has
  // said who is ready: a long file is not held up by three bad rows.
  const canLeaveOut =
    problems.length > 0 &&
    refused.csv !== csv &&
    !reading &&
    (read?.people ?? 0) > 0 &&
    (read?.people ?? 0) <= MAX_PEOPLE;
  const [templatePending, setTemplatePending] = useState(false);

  async function readFile(file: File) {
    if (/\.xls$/i.test(file.name)) {
      toast.error(
        t(
          "setup2.xls_not_read",
          "Old .xls files are not read. Save the file as .xlsx or CSV, or copy the rows and paste them here.",
        ),
      );
      return;
    }
    if (file.size > FILE_MAX_BYTES) {
      toast.error(
        t(
          "employees_import_page.file_too_large",
          "The file must be 5 MB or smaller.",
        ),
      );
      return;
    }
    // Another file is another list: what was said about the last one's
    // columns does not carry over.
    setSourceId(null);
    setMatched(null);
    setMatching(false);
    if (!/\.xlsx$/i.test(file.name)) {
      setCsv(await file.text());
      return;
    }
    // A workbook is opened here, not on the server. Its cells go into the
    // box as text, to be seen and corrected like a pasted list.
    try {
      setCsv(gridToText(await readWorkbook(await file.arrayBuffer())));
    } catch (err) {
      toast.error(
        err instanceof TooManyLines
          ? t(
              "setup2.xlsx_too_long",
              "This sheet has :count rows. Bring in at most :max at a time.",
              { count: err.lines, max: MAX_LINES },
            )
          : err instanceof NoSheet
            ? t(
                "setup2.xlsx_no_sheet",
                "This workbook has no sheet of people to read.",
              )
            : t(
                "setup2.xlsx_failed",
                "This Excel file could not be read. Save the sheet as CSV, or copy the rows and paste them here.",
              ),
      );
    }
  }

  /**
   * Read the list as a saved source names its columns, or as ETHR does.
   * What was matched by hand goes: it was about the columns as they were
   * read before. A source saved with a calendar brings it, unless the reader
   * has already said which calendar the dates are in.
   */
  function chooseSource(publicId: string | null) {
    setSourceId(publicId);
    setMatched(null);
    const chosen = savedSources.find((s) => s.public_id === publicId);
    if (
      chosenCalendar === null &&
      (chosen?.calendar === "ethiopian" || chosen?.calendar === "gregorian")
    ) {
      setChosenCalendar(chosen.calendar);
    }
  }

  /**
   * Open the matcher with every column answered: what the server read it
   * as, or its guess for one it did not read. From here the reader's word
   * is what is sent, column by column.
   */
  function openMatcher() {
    if (read) {
      setMatched(
        read.headers.map((h) => ({
          header: h.header,
          column: h.column ?? h.suggested,
        })),
      );
    }
    setMatching(true);
  }

  /**
   * The one employee template: every column ETHR reads, from the server, so
   * the setup, this page and the API hand out the same file. It is a
   * workbook, with a dropdown on each choice column and the columns a
   * spreadsheet would mangle formatted as text.
   */
  async function downloadTemplate() {
    setTemplatePending(true);
    try {
      const spec = await fetchEmployeeImportTemplate();
      try {
        const workbook = await buildTemplate(spec, dateCalendar, {
          title: t("setup2.template_title", "ETHR employee template"),
          lines: [
            t(
              "setup2.template_line_fill",
              "Fill in the Employees sheet, one person on each row. Only the name is required.",
            ),
            t(
              "setup2.template_line_dates",
              "Write a date year first, such as 2019-07-08, in the calendar its column name says.",
            ),
            t(
              "setup2.template_line_lists",
              "A column with a list takes one of its values. A department, branch or position can also be typed by its code.",
            ),
            t(
              "setup2.template_line_upload",
              "Save the file and choose it in ETHR. Everyone is shown to you before anyone is added.",
            ),
          ],
          columnHeading: t("setup2.template_column", "Column"),
          takesHeading: t("setup2.template_takes", "What it takes"),
          required: t("employees_import_page.required", "required"),
          help: importColumnHelp(t),
          notInList: t(
            "setup2.template_not_in_list",
            "This is not one of the listed values. It is kept, and checked when the list is read.",
          ),
        });
        saveBlob(
          "ethr-employee-template.xlsx",
          new Blob([workbook], { type: XLSX_MIME }),
        );
      } catch {
        // The workbook could not be built (its library did not load, offline):
        // the CSV has the same columns.
        saveCsv(
          "ethr-employee-template.csv",
          templateCsv(spec.headers, dateCalendar),
        );
      }
    } catch (err) {
      toast.error(
        apiErrorMessage(
          err,
          t(
            "employees_import_page.template_download_failed",
            "Failed to download template",
          ),
        ),
      );
    } finally {
      setTemplatePending(false);
    }
  }

  async function discoverDevice() {
    if (!device) return;
    try {
      const b = await stageDevice.mutateAsync(device);
      setBatchId(b.public_id);
    } catch (err) {
      // The server says why: the device is offline, its address does not
      // resolve, or the organisation does not allow it.
      toast.error(
        apiErrorMessage(
          err,
          t("setup2.stage_failed", "Could not read the device"),
        ),
      );
    }
  }

  async function stageCsv(leaveOutProblems = false) {
    if (csv.trim() === "" || (problems.length > 0 && !leaveOutProblems)) {
      return;
    }
    try {
      const b = await stageRows.mutateAsync({
        grid: toGrid(csv),
        calendar: dateCalendar,
        ...(leaveOutProblems ? { skip_problems: true } : {}),
        ...listColumns(named),
      });
      setBatchId(b.public_id);
    } catch (err) {
      // The list changed after it was last read, or was never read: the
      // server names the lines it will not stage, and they are shown where
      // the read's are.
      const lines = refusedLines(err);
      if (lines.length > 0) {
        setRefused({ csv, lines });
        return;
      }
      toast.error(
        apiErrorMessage(err, t("setup2.stage_failed", "Could not stage rows")),
      );
    }
  }

  async function changeAction(
    rowId: string,
    action: StagingAction,
    employeePublicId?: string,
    accept?: string[],
  ) {
    try {
      await setAction.mutateAsync({ rowId, action, employeePublicId, accept });
      batchQuery.refetch();
    } catch (err) {
      toast.error(apiErrorMessage(err, t("setup2.save_failed", "Save failed")));
    }
  }

  /**
   * Creates and merges employees as the table says, and cannot be undone
   * here, so it asks first. It went through on one click
   * (FRONTEND_REDESIGN_PLAN.md, onboarding).
   */
  async function runCommit() {
    if (!batchId) return;
    const confirmed = await requestConfirm({
      title: t("setup2.commit_confirm_title", "Bring these records in?"),
      description: t(
        "setup2.commit_confirm_desc",
        "Employees are created and merged as the table shows. This cannot be undone from here.",
      ),
      confirmLabel: t("setup2.commit_confirm", "Bring them in"),
    });
    if (!confirmed) return;
    try {
      const result = await commit.mutateAsync(batchId);
      toast.success(
        t(
          "setup2.migration_done",
          "Migration complete: :created created, :merged merged, :updated updated",
          {
            created: result.totals.created ?? 0,
            merged: result.totals.merged ?? 0,
            updated: result.totals.updated ?? 0,
          },
        ),
      );
    } catch (err) {
      toast.error(
        err instanceof CommitStalled
          ? t("setup2.commit_stalled", "The list stopped moving. Try again.")
          : apiErrorMessage(
              err,
              t("setup2.commit_failed", "Could not commit migration"),
            ),
      );
    } finally {
      setRemaining(null);
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <div>
          <h2 className="text-lg font-semibold text-foreground">
            {title ?? t("setup2.migration_title", "Bring in your workforce")}
          </h2>
          <p className="mt-1 text-sm text-muted-foreground">
            {description ??
              t(
                "setup2.migration_desc",
                "Discover people from an attendance device or a list. We match them to existing employees so nobody is duplicated.",
              )}
          </p>
        </div>
      </div>

      {!batch && (
        <div className={noDevice ? "space-y-4" : "grid gap-4 lg:grid-cols-2"}>
          {!noDevice && (
            <Card>
              <CardContent className="space-y-3 p-4">
                <div className="flex items-center gap-2 text-sm font-semibold text-foreground">
                  <HardDrive className="h-4 w-4" aria-hidden="true" />
                  {t("setup2.from_device", "From an attendance device")}
                </div>
                <p className="text-xs text-muted-foreground">
                  {t(
                    "setup2.device_hint",
                    "Everyone enrolled on the device is listed with their badge number, ready to match.",
                  )}
                </p>
                <>
                  <Select value={device} onValueChange={setDeviceId}>
                    <SelectTrigger
                      aria-label={t("setup2.select_device", "Select a device")}
                    >
                      <SelectValue
                        placeholder={t(
                          "setup2.select_device",
                          "Select a device",
                        )}
                      />
                    </SelectTrigger>
                    <SelectContent>
                      {devices.map((d) => (
                        <SelectItem key={d.public_id} value={d.public_id}>
                          {d.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <Button
                    className="w-full"
                    onClick={discoverDevice}
                    disabled={!device || stageDevice.isPending}
                  >
                    {stageDevice.isPending ? (
                      <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    ) : null}
                    {t("setup2.discover", "Discover enrolled users")}
                  </Button>
                </>
              </CardContent>
            </Card>
          )}

          <Card>
            <CardContent className="space-y-3 p-4">
              <div className="flex items-center gap-2 text-sm font-semibold text-foreground">
                <FileSpreadsheet className="h-4 w-4" aria-hidden="true" />
                {t("setup2.from_sheet", "From a spreadsheet or list")}
              </div>
              <p className="text-xs text-muted-foreground">
                {t(
                  "setup2.sheet_hint",
                  "Choose an Excel or CSV file, or copy the rows from Excel or Google Sheets and paste them here. A first row of column names is recognised, in any order.",
                )}
              </p>
              <div className="flex flex-wrap gap-2">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => fileInput.current?.click()}
                >
                  <Upload className="mr-2 h-4 w-4" aria-hidden="true" />
                  {t("setup2.choose_file", "Choose an Excel or CSV file")}
                </Button>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={downloadTemplate}
                  disabled={templatePending}
                >
                  <Download className="mr-2 h-4 w-4" aria-hidden="true" />
                  {t("setup2.download_template", "Download template")}
                </Button>
                <input
                  ref={fileInput}
                  type="file"
                  accept=".csv,.txt,text/csv,text/plain,.xlsx,.xls"
                  className="hidden"
                  data-testid="people-file"
                  onChange={(e) => {
                    const file = e.target.files?.[0];
                    if (file) void readFile(file);
                    e.target.value = "";
                  }}
                />
              </div>
              <div
                role="group"
                aria-label={t("setup2.dates_in", "Hire dates are in")}
                className="flex flex-wrap items-center gap-2 text-xs"
              >
                <span className="text-muted-foreground">
                  {t("setup2.dates_in", "Hire dates are in")}
                </span>
                {(["ethiopian", "gregorian"] as const).map((c) => (
                  <Button
                    key={c}
                    type="button"
                    size="sm"
                    variant={dateCalendar === c ? "default" : "outline"}
                    aria-pressed={dateCalendar === c}
                    onClick={() => setChosenCalendar(c)}
                  >
                    {c === "ethiopian"
                      ? t("setup2.calendar_ec", "Ethiopian (E.C.)")
                      : t("setup2.calendar_gc", "Gregorian (G.C.)")}
                  </Button>
                ))}
              </div>
              {savedSources.length > 0 && (
                <div className="flex flex-wrap items-center gap-2 text-xs">
                  <label
                    htmlFor="people-source"
                    className="text-muted-foreground"
                  >
                    {t("setup2.source_label", "This list comes from")}
                  </label>
                  <select
                    id="people-source"
                    value={sourceId ?? ""}
                    onChange={(e) => chooseSource(e.target.value || null)}
                    className="h-8 rounded-md border border-border-default bg-background px-2 text-xs"
                  >
                    <option value="">
                      {t(
                        "setup2.source_none",
                        "The ETHR template or a one-off file",
                      )}
                    </option>
                    {savedSources.map((s) => (
                      <option key={s.public_id} value={s.public_id}>
                        {s.name}
                      </option>
                    ))}
                  </select>
                </div>
              )}
              <textarea
                value={csv}
                onChange={(e) => setCsv(e.target.value)}
                rows={6}
                aria-label={t(
                  "setup2.from_sheet",
                  "From a spreadsheet or list",
                )}
                placeholder={
                  dateCalendar === "ethiopian"
                    ? "Name, Email, Phone, Employee number, Hire date (E.C.), Department\nAbebe Kebede, abebe@acme.et, 0911223344, EMP-1, 2011-10-30, Finance"
                    : "Name, Email, Phone, Employee number, Hire date (G.C.), Department\nAbebe Kebede, abebe@acme.et, 0911223344, EMP-1, 2019-07-07, Finance"
                }
                className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              />
              {csv.trim() !== "" && (
                <div className="space-y-1 text-xs" aria-live="polite">
                  {read ? (
                    <p className="font-medium text-foreground">
                      {read.people === 1
                        ? t("setup2.people_ready_one", "1 person ready")
                        : t("setup2.people_ready", ":count people ready", {
                            count: read.people,
                          })}
                      {read.header && (
                        <span className="font-normal text-muted-foreground">
                          {" · "}
                          {t("setup2.columns_read", "Columns: :columns", {
                            columns: read.columns
                              .map((c) =>
                                COLUMN_LABELS[c] ? t(...COLUMN_LABELS[c]) : c,
                              )
                              .join(", "),
                          })}
                        </span>
                      )}
                    </p>
                  ) : (
                    reading && (
                      <p className="text-muted-foreground">
                        {t("setup2.checking_list", "Reading the list")}
                      </p>
                    )
                  )}
                  {readQuery.isError && !reading && (
                    <p className="text-destructive">
                      {apiErrorMessage(
                        readQuery.error,
                        t("setup2.stage_failed", "Could not read the source"),
                      )}
                    </p>
                  )}
                  {(read?.ignored.length ?? 0) > 0 && (
                    <p className="text-warning-on-soft">
                      {t(
                        "setup2.columns_ignored",
                        "Not read, because the column name is not one ETHR knows: :columns",
                        { columns: read!.ignored.join(", ") },
                      )}
                    </p>
                  )}
                  {suggested && (
                    <p className="flex flex-wrap items-center gap-2 text-foreground">
                      {t(
                        "setup2.source_suggested",
                        "These look like the columns of :name.",
                        { name: suggested.name },
                      )}
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => chooseSource(suggested.public_id)}
                      >
                        {t("setup2.source_use", "Read it as :name", {
                          name: suggested.name,
                        })}
                      </Button>
                    </p>
                  )}
                  {read?.header_likely && !matching && (
                    <p className="text-warning-on-soft">
                      {t(
                        "setup2.header_likely",
                        "The first line looks like column names ETHR does not know, so it was read as a person. Match the columns and the list is read properly.",
                      )}
                    </p>
                  )}
                  {read && read.headers.length > 0 && !matching && (
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={openMatcher}
                    >
                      {t("setup2.match_columns", "Match columns")}
                    </Button>
                  )}
                  {read?.calendar_from_header && (
                    <p className="text-muted-foreground">
                      {read.calendar === "ethiopian"
                        ? t(
                            "setup2.dates_from_header_ec",
                            "Hire dates are read as Ethiopian (E.C.), as the column name says.",
                          )
                        : t(
                            "setup2.dates_from_header_gc",
                            "Hire dates are read as Gregorian (G.C.), as the column name says.",
                          )}
                    </p>
                  )}
                  {(read?.without_hire_date ?? 0) > 0 && (
                    <p className="text-warning-on-soft">
                      {read!.without_hire_date === 1
                        ? t(
                            "setup2.no_hire_date_one",
                            "1 person has no hire date. Today will be used, which affects leave and length of service.",
                          )
                        : t(
                            "setup2.no_hire_date",
                            ":count people have no hire date. Today will be used, which affects leave and length of service.",
                            { count: read!.without_hire_date },
                          )}
                    </p>
                  )}
                  {problems.length > 0 && (
                    <ul className="space-y-0.5 text-destructive">
                      {problems.slice(0, PROBLEMS_SHOWN).map((problem) => (
                        <li key={problem}>{problem}</li>
                      ))}
                      {problems.length > PROBLEMS_SHOWN && (
                        <li>
                          {t("setup2.more_problems", "and :count more", {
                            count: problems.length - PROBLEMS_SHOWN,
                          })}
                        </li>
                      )}
                    </ul>
                  )}
                </div>
              )}
              {matching && read && csv.trim() !== "" && (
                <ColumnMatcher
                  matches={matches}
                  samples={samples}
                  label={(column) =>
                    COLUMN_LABELS[column] ? t(...COLUMN_LABELS[column]) : column
                  }
                  source={source}
                  calendar={dateCalendar}
                  needsName={nameless}
                  onMatch={setMatched}
                  onSaved={(saved) => {
                    setSourceId(saved.public_id);
                    setMatched(null);
                  }}
                  onForgotten={() => setSourceId(null)}
                />
              )}
              <p className="text-xs text-muted-foreground">
                {t(
                  "setup2.csv_hint",
                  "Without column names, the order is: name, email, phone, employee number, hire date (YYYY-MM-DD), department. Only the name is required.",
                )}
              </p>
              <Button
                className="w-full"
                onClick={() => stageCsv()}
                disabled={
                  stageRows.isPending ||
                  csv.trim() === "" ||
                  problems.length > 0 ||
                  nameless ||
                  (read !== undefined && !reading && read.people === 0)
                }
              >
                {stageRows.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : null}
                {t("setup2.stage_rows", "Stage for review")}
              </Button>
              {canLeaveOut && (
                <Button
                  className="w-full"
                  variant="outline"
                  onClick={() => stageCsv(true)}
                  disabled={stageRows.isPending}
                >
                  {read!.people === 1
                    ? t(
                        "setup2.stage_ready_one",
                        "Stage the 1 person ready and leave those lines out",
                      )
                    : t(
                        "setup2.stage_ready",
                        "Stage the :count people ready and leave those lines out",
                        { count: read!.people },
                      )}
                </Button>
              )}
            </CardContent>
          </Card>
          {noDevice && !listOnly && (
            <p className="flex flex-wrap items-center gap-2 rounded-md border border-dashed border-border px-4 py-3 text-sm text-muted-foreground">
              <HardDrive className="h-4 w-4 shrink-0" aria-hidden="true" />
              {t(
                "setup2.no_device_row",
                "Using a fingerprint or face terminal? Connect a ZKTeco terminal and everyone enrolled on it can be brought in from here.",
              )}
              <Link
                href="/devices"
                className="font-medium text-primary underline-offset-4 hover:underline"
              >
                {t("setup2.connect_device", "Connect a device")}
              </Link>
            </p>
          )}
        </div>
      )}

      {batch && (
        <div className="space-y-4">
          <div className="flex flex-wrap items-center gap-2">
            {Object.entries(batch.summary ?? {}).map(([outcome, count]) => (
              <Badge
                key={outcome}
                variant="outline"
                style={{ color: outcomeTone(outcome as MatchOutcome) }}
              >
                {count} {t(`setup2.outcome_${outcome}`, outcome)}
              </Badge>
            ))}
          </div>

          <div className="rounded-lg border">
            <SimpleTable
              caption={t("setup2.migration_review", "Migration review")}
              headers={[
                t("setup2.person", "Person"),
                t("setup2.match", "Match"),
                t("setup2.action", "Action"),
              ]}
              rows={batch.rows.map((row) => ({
                key: row.public_id,
                cells: [
                  row.display_name ?? row.external_identifier ?? "—",
                  <div key="m" className="space-y-1">
                    <span
                      className="text-xs font-medium"
                      style={{ color: outcomeTone(row.match_outcome) }}
                    >
                      {t(
                        `setup2.outcome_${row.match_outcome}`,
                        row.match_outcome ?? "new",
                      )}
                      {row.match_confidence
                        ? ` · ${Math.round(row.match_confidence * 100)}%`
                        : ""}
                    </span>
                    {(row.candidates?.length ?? 0) > 0 && (
                      <ul className="space-y-0.5">
                        {row.candidates!.map((c) => {
                          const chosen =
                            row.resolved_employee_public_id ===
                            c.employee_public_id;
                          return (
                            <li
                              key={c.employee_public_id}
                              className="flex flex-wrap items-center gap-1 text-[11px] text-muted-foreground"
                            >
                              <span
                                className={
                                  chosen
                                    ? "font-semibold text-foreground"
                                    : undefined
                                }
                              >
                                {c.employee_name}
                              </span>
                              <span>· {Math.round(c.score * 100)}%</span>
                              <span className="italic">
                                (
                                {c.reasons
                                  .map((r) => reasonLabel(r, t))
                                  .join(", ")}
                                )
                              </span>
                              {!row.processed_at &&
                                row.candidates!.length > 1 &&
                                !chosen && (
                                  <button
                                    type="button"
                                    className="text-primary underline underline-offset-2 hover:no-underline"
                                    onClick={() =>
                                      changeAction(
                                        row.public_id,
                                        "merge",
                                        c.employee_public_id,
                                      )
                                    }
                                  >
                                    {t("setup2.use_candidate", "Use")}
                                  </button>
                                )}
                            </li>
                          );
                        })}
                      </ul>
                    )}
                    <StagedChanges
                      row={row}
                      locked={
                        Boolean(row.processed_at) ||
                        batch.status !== "reviewing"
                      }
                      onAccept={(fields) =>
                        changeAction(
                          row.public_id,
                          row.action,
                          undefined,
                          fields,
                        )
                      }
                    />
                  </div>,
                  row.processed_at ? (
                    <span
                      key="a"
                      className="flex items-center gap-1 text-xs text-muted-foreground"
                    >
                      <Check className="h-3 w-3" /> {t("setup2.done", "Done")}
                    </span>
                  ) : (
                    <Select
                      key="a"
                      value={row.action}
                      disabled={batch.status !== "reviewing"}
                      onValueChange={(v) =>
                        changeAction(row.public_id, v as StagingAction)
                      }
                    >
                      <SelectTrigger className="h-8 w-32">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {ACTIONS.map((a) => (
                          <SelectItem key={a} value={a}>
                            {t(`setup2.action_${a}`, a)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  ),
                ],
              }))}
            />
          </div>

          <div className="flex items-center justify-between gap-2 border-t pt-4">
            <Button
              variant="ghost"
              size="sm"
              onClick={() => setBatchId(null)}
              disabled={commit.isPending}
            >
              {t("setup2.start_over", "Start over")}
            </Button>
            <Button
              onClick={runCommit}
              disabled={commit.isPending || batch.status === "committed"}
            >
              {commit.isPending ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : null}
              {batch.status === "committed"
                ? t("setup2.committed", "Committed")
                : commit.isPending && remaining !== null
                  ? t("setup2.committing", "Adding people, :count left", {
                      count: remaining,
                    })
                  : t("setup2.commit", "Commit migration")}
            </Button>
          </div>

          {batch.status === "committed" && batchId && (
            <MigrationLogins batchId={batchId} onContinue={onCommitted} />
          )}
        </div>
      )}
      {confirmDialog}
    </div>
  );
}
