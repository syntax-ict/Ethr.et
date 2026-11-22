"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
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
import { usePermissions } from "@/lib/hooks/usePermissions";
import {
  CommitStalled,
  isNotFound,
  listColumns,
  StagingInterrupted,
  StagingStalled,
  type StagingProgress,
  useCarryOnStaging,
  useCommitMigration,
  useImportSources,
  useMigrationBatch,
  useReadPeople,
  useSetRowAction,
  useConfirmSameName,
  useStageFromDevice,
  useStageFromRows,
} from "../api";
import type {
  ArrivalStatus,
  ColumnMatch,
  ListMode,
  MatchOutcome,
  StagingAction,
} from "../types";
import {
  type DateCalendar,
  decodeText,
  MAX_PEOPLE,
  templateCsv,
  toGrid,
} from "../parse-people";
import { fetchEmployeeImportTemplate } from "@/features/employees/api";
import { columnLabel } from "@/features/employees/employee-columns";
import { employeeTemplateText } from "@/features/employees/components/import-column-guide";
import {
  buildFixList,
  buildResult,
  buildTemplate,
  gridToText,
  MAX_LINES,
  NoSheet,
  readWorkbookSheets,
  TooManyLines,
  XLSX_MIME,
} from "../xlsx-grid";
import {
  fixList,
  groupFound,
  type Outcome,
  outcomeOf,
} from "../import-problems";
import { ColumnMatcher } from "./column-matcher";
import { ProblemTable } from "./problem-table";
import { MigrationListsInProgress } from "./migration-lists-in-progress";
import { MigrationLogins } from "./migration-logins";
import { MigrationReadiness } from "./migration-readiness";
import { MigrationTerminals } from "./migration-terminals";
import { MigrationUndoButton, MigrationUndoNote } from "./migration-undo";
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
    case "same_name":
      return "var(--color-status-warning, #92400E)";
    case "ambiguous":
      return "var(--color-status-error, #B91C1C)";
    default:
      return "var(--color-text-secondary, #64748B)";
  }
}

/** Lines a staging the server refused are listed before the rest are counted. */
const PROBLEMS_SHOWN = 5;

/** A file this large is not a list of people; it is not read into the page. */
const FILE_MAX_BYTES = 5 * 1024 * 1024;

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
 * @param initialBatchId A list to open as the step appears, as one that was
 *   just staged is opened. A page that keeps the open list in its address
 *   passes it here and hears every change through `onBatchChange`.
 * @param onBatchChange Called with the open list's id when one is opened, and
 *   with null when it is closed (or turns out not to exist).
 * @param initialDeviceId Preselects the device, when opened from that device.
 * @param sources "list" leaves the attendance device out: the employee import
 *   is about a file.
 * @param canComplete Offers the choice between adding people and completing
 *   the people who are already here (nobody new is added). Only the employee
 *   import page does: the guided setup and a device's roster add people.
 * @param inviteByDefault Whether the "invite them as soon as they are added"
 *   tick starts ticked. It does everywhere a list is brought into a running
 *   organisation; the guided setup passes false (owner decision 2026-11-16).
 * @param title Heading, where the page is not the guided setup.
 * @param description The line under it.
 */
export function MigrationStep({
  onCommitted,
  initialBatchId = null,
  onBatchChange,
  initialDeviceId,
  sources = "all",
  canComplete = false,
  inviteByDefault = true,
  title,
  description,
}: {
  onCommitted?: () => void;
  initialBatchId?: string | null;
  onBatchChange?: (batchId: string | null) => void;
  initialDeviceId?: string;
  sources?: "all" | "list";
  canComplete?: boolean;
  inviteByDefault?: boolean;
  title?: string;
  description?: string;
}) {
  const { t } = useT();
  const { requestConfirm, confirmDialog } = useConfirm();
  const { can } = usePermissions();
  const devicesQuery = useDevices();
  const stageDevice = useStageFromDevice();
  // How far a long list has been matched, while it is, and the list whose
  // matching stopped, so it can be carried on and not read again.
  const [staging, setStaging] = useState<StagingProgress | null>(null);
  const [interrupted, setInterrupted] = useState<{
    batchId: string;
    message: string;
  } | null>(null);
  const stageRows = useStageFromRows(setStaging);
  const carryOn = useCarryOnStaging(setStaging);
  const setAction = useSetRowAction();
  const confirmSameName = useConfirmSameName();
  // Rows a long list still has to add, while it is being added.
  const [remaining, setRemaining] = useState<number | null>(null);
  const commit = useCommitMigration((result) => setRemaining(result.remaining));

  const [openedId, setOpenedId] = useState<string | null>(initialBatchId);
  // Every change of the open list goes through here, so a page that keeps it
  // in its address hears all of them.
  const setBatchId = useCallback(
    (id: string | null) => {
      setOpenedId(id);
      onBatchChange?.(id);
    },
    [onBatchChange],
  );
  // What the whole list is brought in with, chosen before it is added: what
  // its new people arrive as, and whether they are invited straight away.
  // That starts ticked, except in the guided setup, where it starts unticked:
  // the organisation has not yet chosen how people sign in, and a first list
  // is the least-checked data (owner decision 2026-11-16).
  const [arrival, setArrival] = useState<ArrivalStatus>("hired");
  const [inviteNow, setInviteNow] = useState(inviteByDefault);
  // What the list is for, chosen before it is staged: add people, or fill in
  // the people who are already here and add nobody.
  const [mode, setMode] = useState<ListMode>("add");
  const [deviceId, setDeviceId] = useState<string>(initialDeviceId ?? "");
  const [csv, setCsv] = useState("");
  const fileInput = useRef<HTMLInputElement>(null);

  const batchQuery = useMigrationBatch(openedId);
  // A list that is gone, or is another organisation's, is let go quietly: the
  // normal screen is shown as if none were open, and the page's address is
  // told to forget it.
  const gone = batchQuery.isError && isNotFound(batchQuery.error);
  const batchId = gone ? null : openedId;
  useEffect(() => {
    if (gone) onBatchChange?.(null);
  }, [gone, onBatchChange]);
  // A list still being read has no review yet: it is carried on, as one whose
  // matching stopped is, and not shown as a table of people nobody matched.
  const stagingBatchId = batchQuery.data?.status === "staging" ? batchId : null;
  const batch = stagingBatchId === null ? batchQuery.data : undefined;
  // Rows held because someone is already here under the same name.
  const sameNameCount = batch?.summary?.same_name ?? 0;
  // Of those, the ones naming one person and not yet decided.
  const sameNameOpen =
    batch?.rows.filter(
      (row) =>
        row.match_outcome === "same_name" &&
        row.action === "defer" &&
        !row.processed_at &&
        row.resolved_employee_public_id !== null,
    ).length ?? 0;
  const openingList = batchId !== null && batchQuery.isPending && !gone;
  const pending: { batchId: string; message: string } | null =
    interrupted ??
    (stagingBatchId === null
      ? null
      : {
          batchId: stagingBatchId,
          message: t(
            "setup2.staging_resumed",
            "This list was still being read. Carry on to finish it.",
          ),
        });

  const allDevices = devicesQuery.data?.data ?? [];
  // Only a device ETHR can call can be asked who is enrolled on it. A
  // terminal that only sends to ETHR answers no roster, and offering it here
  // staged an empty list that read as "nobody is enrolled".
  const devices = allDevices.filter((d) => d.receives_only !== true);
  const onlySends = allDevices.length > 0 && devices.length === 0;
  // The terminal the page was opened for, when it is one that cannot be read.
  // Another is not put in its place without a word: with a readable terminal
  // elsewhere, the default below would choose it, and the list staged would
  // be another terminal's.
  const refusedDevice =
    deviceId !== "" && deviceId === initialDeviceId
      ? allDevices.find(
          (d) => d.public_id === deviceId && d.receives_only === true,
        )
      : undefined;
  // One device is the device: there is nothing to choose.
  const device = devices.some((d) => d.public_id === deviceId)
    ? deviceId
    : devices.length === 1 && !refusedDevice
      ? devices[0].public_id
      : "";
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
  // Where the column names are, when the reader has said: a line, or 0 for none.
  const [headerLine, setHeaderLine] = useState<number | null>(null);
  // Asked for by the reader, in a list where no column names were found.
  const [pickingLine, setPickingLine] = useState(false);
  // A workbook with more than one sheet that may hold people: the reader picks.
  const [workbook, setWorkbook] = useState<{
    data: ArrayBuffer;
    sheets: string[];
    sheet: string;
  } | null>(null);
  // What a text file had to be read as, when that was a guess.
  const [fileEncoding, setFileEncoding] = useState<string | null>(null);
  const source = savedSources.find((s) => s.public_id === sourceId) ?? null;
  const named = useMemo(
    () => ({ source: sourceId, mapping: matched, header_line: headerLine }),
    [sourceId, matched, headerLine],
  );
  const readQuery = useReadPeople(
    grid,
    dateCalendar,
    batchId === null,
    named,
    canComplete ? mode : "add",
  );
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
    (matched !== null &&
      !matches.some((m) => m.column === "name" || m.column === "first_name"));
  // A value under each column name, from the first person in the list.
  const foundLine = read?.header_line ?? null;
  const samples = useMemo(() => {
    // From the line the column names are on, not from the top of the list.
    const lines = grid
      .slice(foundLine !== null ? foundLine - 1 : 0)
      .filter((line) => line.some((cell) => cell !== ""));
    const [names = [], first = []] = lines;
    const found: Record<string, string> = {};
    names.forEach((cell, index) => {
      const header = cell.trim();
      if (header !== "" && found[header] === undefined) {
        found[header] = (first[index] ?? "").trim();
      }
    });
    return found;
  }, [grid, foundLine]);
  // The first lines that have anything in them, for choosing which holds the column names.
  const lineChoices = useMemo(
    () =>
      grid
        .map((cells, index) => ({ line: index + 1, cells }))
        .filter(({ cells }) => cells.some((cell) => cell !== ""))
        .slice(0, 15),
    [grid],
  );
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
  // What the server's read found, grouped by column and kind. A staging the
  // server refused names lines only, and is listed as it says.
  const refusedNow = refused.csv === csv && refused.lines.length > 0;
  const problemGroups = useMemo(
    () => (refusedNow ? [] : groupFound(read?.problems ?? [])),
    [read, refusedNow],
  );
  const warningGroups = useMemo(() => groupFound(read?.warnings ?? []), [read]);
  // The lines to correct and bring back: the list as it was read, the
  // refused lines only. Nothing is asked of the server for it.
  const toFix =
    !refusedNow && !reading && read && read.problems.length > 0
      ? fixList(grid, read, (line, messages) =>
          t("setup2.problem_line", "Line :line: :message", {
            line,
            message: messages.join("; "),
          }),
        )
      : null;
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
    startList();
    if (!/.xlsx$/i.test(file.name)) {
      // Decoded from its bytes: a file saved by Excel in a Western locale is
      // Windows-1252, and a "Unicode text" one is UTF-16.
      const decoded = decodeText(new Uint8Array(await file.arrayBuffer()));
      setCsv(decoded.text);
      setFileEncoding(decoded.guessed ? "Windows-1252" : null);
      return;
    }
    // A workbook is opened here, not on the server. Its cells go into the
    // box as text, to be seen and corrected like a pasted list.
    await readSheet(await file.arrayBuffer());
  }

  /** What was said about the list before this one does not carry over to the next. */
  function startList() {
    setSourceId(null);
    setMatched(null);
    setMatching(false);
    setHeaderLine(null);
    setPickingLine(false);
    setFileEncoding(null);
    setWorkbook(null);
  }

  /** A workbook's sheet into the list box: the one named, or the one that is read by default. */
  async function readSheet(data: ArrayBuffer, sheetName?: string) {
    try {
      const sheet = await readWorkbookSheets(data, sheetName);
      setCsv(gridToText(sheet.grid));
      setWorkbook(
        sheet.sheets.length > 1
          ? { data, sheets: sheet.sheets, sheet: sheet.sheet }
          : null,
      );
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
        const workbook = await buildTemplate(
          spec,
          dateCalendar,
          employeeTemplateText(t),
        );
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

  /** The refused lines as a workbook, to be corrected and chosen again. */
  async function downloadFixList() {
    if (!toFix) return;
    try {
      const workbook = await buildFixList(toFix.header, toFix.lines);
      saveBlob(
        "ethr-employees-to-fix.xlsx",
        new Blob([workbook], { type: XLSX_MIME }),
      );
    } catch {
      toast.error(
        t(
          "setup2.problem_fix_failed",
          "The file could not be built. Fix the lines in the list box instead.",
        ),
      );
    }
  }

  /** What became of each line of the list that was added, one row each. */
  async function downloadResult() {
    if (!batch) return;
    const words: Record<Outcome, string> = {
      added: t("setup2.result_added", "Added"),
      filled: t("setup2.result_filled", "Filled in"),
      unchanged: t(
        "setup2.result_unchanged",
        "Already here, nothing to change",
      ),
      left_out: t("setup2.left_out", "Left out"),
      not_found: t("setup2.not_found_left_out", "Not found in ETHR, left out"),
    };
    try {
      const workbook = await buildResult(
        [
          t("setup2.col_name", "Name"),
          t("setup2.col_employee_code", "Employee number"),
          t("setup2.result_what", "What happened"),
          t("setup2.result_note", "Note"),
        ],
        batch.rows.map((row) => [
          row.display_name ?? row.external_identifier ?? "",
          row.resolved_employee_code ?? "",
          words[outcomeOf(row, batch.mode)],
          (row.warnings ?? []).map((w) => w.message).join("; "),
        ]),
      );
      saveBlob(
        "ethr-import-result.xlsx",
        new Blob([workbook], { type: XLSX_MIME }),
      );
    } catch {
      toast.error(t("setup2.result_failed", "The file could not be built."));
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
        ...(canComplete && mode === "complete"
          ? { mode: "complete" as const }
          : {}),
        ...(leaveOutProblems ? { skip_problems: true } : {}),
        ...listColumns(named),
      });
      setInterrupted(null);
      setStaging(null);
      setBatchId(b.public_id);
    } catch (err) {
      setStaging(null);
      // The list was stored and matching stopped: it is kept, and the reader
      // is told so, with a way to carry on that does not read it again.
      if (err instanceof StagingInterrupted) {
        rememberInterrupted(err);
        return;
      }
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

  function stagingText(progress: StagingProgress): string {
    return t("setup2.staging_progress", "Matching people: :done of :total", {
      done: progress.staged,
      total: progress.staged + progress.remaining,
    });
  }

  function rememberInterrupted(err: StagingInterrupted) {
    setInterrupted({
      batchId: err.batchId,
      message:
        err.cause instanceof StagingStalled
          ? t(
              "setup2.staging_stalled",
              "Matching stopped moving. The list is kept; carry on to finish it.",
            )
          : apiErrorMessage(
              err.cause,
              t(
                "setup2.staging_interrupted",
                "The list was read, but matching the people stopped before it finished. The list is kept; carry on to finish it.",
              ),
            ),
    });
  }

  async function carryOnStagingList(id: string | undefined = pending?.batchId) {
    if (!id) return;
    try {
      const b = await carryOn.mutateAsync(id);
      setInterrupted(null);
      setStaging(null);
      setBatchId(b.public_id);
    } catch (err) {
      setStaging(null);
      if (err instanceof StagingInterrupted) {
        rememberInterrupted(err);
        return;
      }
      toast.error(
        apiErrorMessage(err, t("setup2.stage_failed", "Could not stage rows")),
      );
    }
  }

  /**
   * A list from "lists in progress" is opened as one just staged is: its id
   * is set and the screen for its state takes over. One still being read is
   * carried on first, by the same request a stopped staging uses.
   */
  function openList(list: { public_id: string; status: string }) {
    if (list.status === "staging") {
      void carryOnStagingList(list.public_id);
      return;
    }
    setBatchId(list.public_id);
  }

  function startOver() {
    setInterrupted(null);
    if (batchId !== null) setBatchId(null);
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

  /** Every row naming one person already here by name is them. */
  async function runConfirmSameName() {
    if (!batchId) return;
    const confirmed = await requestConfirm({
      title: t(
        "setup2.same_name_all_title",
        "Link all :count to the people already here?",
        { count: sameNameOpen },
      ),
      description: t(
        "setup2.same_name_all_desc",
        "Each row held because someone here has the same name, and that names only one person, is set to This is them. Rows that name more than one person, and rows you have already decided, are left as they are. You can still change any row before adding the list.",
      ),
      confirmLabel: t("setup2.same_name_all_confirm", "Link them"),
    });
    if (!confirmed) return;
    try {
      await confirmSameName.mutateAsync(batchId);
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
    const completing = batch?.mode === "complete";
    const confirmed = await requestConfirm({
      title: completing
        ? t("setup2.complete_confirm_title", "Fill in these records?")
        : t("setup2.commit_confirm_title", "Bring these records in?"),
      description: completing
        ? t(
            "setup2.complete_confirm_desc",
            "Blanks are filled in as the table shows. Nobody is added. This cannot be undone from here.",
          )
        : can.deleteEmployees
          ? t(
              "setup2.commit_confirm_desc_undoable",
              "Employees are created and merged as the table shows. The people this list adds can be archived again afterwards with Undo this list's additions. Changes to people who are already here cannot be undone from here.",
            )
          : t(
              "setup2.commit_confirm_desc",
              "Employees are created and merged as the table shows. This cannot be undone from here.",
            ),
      confirmLabel: completing
        ? t("setup2.complete_confirm", "Fill them in")
        : t("setup2.commit_confirm", "Bring them in"),
    });
    if (!confirmed) return;
    try {
      const result = await commit.mutateAsync({
        batchId,
        status: completing ? undefined : arrival,
      });
      toast.success(
        completing
          ? t(
              "setup2.complete_done",
              "Done: :updated people filled in, :skipped left out",
              {
                updated: result.totals.updated ?? 0,
                skipped: result.totals.skipped ?? 0,
              },
            )
          : t(
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

      {batchId === null && pending === null && !stageRows.isPending && (
        <MigrationListsInProgress
          onOpen={openList}
          openingId={carryOn.isPending ? (carryOn.variables ?? null) : null}
          openingText={staging ? stagingText(staging) : null}
          disabled={stageDevice.isPending}
        />
      )}

      {openingList && (
        <p
          role="status"
          className="flex items-center gap-2 text-sm text-muted-foreground"
        >
          <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
          {t("migration_lists.opening", "Opening the list")}
        </p>
      )}

      {!batch && !openingList && (
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
                {refusedDevice && (
                  <p role="status" className="text-xs text-status-warning">
                    {t(
                      "setup2.device_refused",
                      ":name sends its punches to ETHR and cannot be asked who is enrolled on it. Choose another terminal.",
                      { name: refusedDevice.name },
                    )}
                  </p>
                )}
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
              {canComplete && (
                <fieldset className="space-y-1 text-sm">
                  <legend className="sr-only">
                    {t("setup2.mode_legend", "What this list is for")}
                  </legend>
                  <label className="flex items-start gap-2 text-foreground">
                    <input
                      type="radio"
                      name="list-mode"
                      className="mt-0.5 h-4 w-4"
                      checked={mode === "add"}
                      onChange={() => setMode("add")}
                    />
                    <span>{t("setup2.mode_add", "Add people")}</span>
                  </label>
                  <label className="flex items-start gap-2 text-foreground">
                    <input
                      type="radio"
                      name="list-mode"
                      className="mt-0.5 h-4 w-4"
                      checked={mode === "complete"}
                      onChange={() => setMode("complete")}
                    />
                    <span>
                      {t(
                        "setup2.mode_complete",
                        "Complete people who are already here (nobody new is added)",
                      )}
                    </span>
                  </label>
                </fieldset>
              )}
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
              {workbook && (
                <div className="flex flex-wrap items-center gap-2 text-xs">
                  <span className="text-muted-foreground">
                    {t(
                      "setup2.sheets_note",
                      "This workbook has :count sheets. Reading: :sheet.",
                      { count: workbook.sheets.length, sheet: workbook.sheet },
                    )}
                  </span>
                  <select
                    aria-label={t("setup2.sheet_pick", "Sheet to read")}
                    value={workbook.sheet}
                    onChange={(e) => {
                      startList();
                      void readSheet(workbook.data, e.target.value);
                    }}
                    className="h-8 rounded-md border border-border-default bg-background px-2 text-xs"
                  >
                    {workbook.sheets.map((name) => (
                      <option key={name} value={name}>
                        {name}
                      </option>
                    ))}
                  </select>
                </div>
              )}
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
                onChange={(e) => {
                  setCsv(e.target.value);
                  setFileEncoding(null);
                }}
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
                              .map((c) => columnLabel(c, t))
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
                  {fileEncoding && (
                    <p className="text-muted-foreground">
                      {t(
                        "setup2.file_read_as",
                        "This file was read as :encoding text.",
                        { encoding: fileEncoding },
                      )}
                    </p>
                  )}
                  {read &&
                    (headerLine !== null ||
                      (read.header_line ?? 1) > 1 ||
                      (!read.header &&
                        lineChoices.length > 1 &&
                        pickingLine)) && (
                      <p className="flex flex-wrap items-center gap-2 text-muted-foreground">
                        {read.header_line != null ? (
                          <span>
                            {t(
                              "setup2.header_line_found",
                              "Column names are on line :line.",
                              { line: read.header_line },
                            )}
                          </span>
                        ) : (
                          <label htmlFor="people-header-line">
                            {t(
                              "setup2.header_line_pick",
                              "Line with the column names",
                            )}
                          </label>
                        )}
                        <select
                          id="people-header-line"
                          aria-label={t(
                            "setup2.header_line_pick",
                            "Line with the column names",
                          )}
                          value={headerLine ?? read.header_line ?? 0}
                          onChange={(e) => {
                            // The columns matched were matched on another line.
                            setMatched(null);
                            setMatching(false);
                            setHeaderLine(Number(e.target.value));
                          }}
                          className="h-8 max-w-full rounded-md border border-border-default bg-background px-2 text-xs"
                        >
                          {lineChoices.map(({ line, cells }) => (
                            <option key={line} value={line}>
                              {`${t("setup2.header_line_option", "Line :line", { line })}: ${cells
                                .filter((cell) => cell !== "")
                                .join(", ")
                                .slice(0, 40)}`}
                            </option>
                          ))}
                          <option value={0}>
                            {t(
                              "setup2.header_line_none",
                              "There are no column names",
                            )}
                          </option>
                        </select>
                      </p>
                    )}
                  {read &&
                    !read.header &&
                    headerLine === null &&
                    !pickingLine &&
                    lineChoices.length > 1 && (
                      <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => setPickingLine(true)}
                      >
                        {t(
                          "setup2.header_line_other",
                          "The column names are on another line",
                        )}
                      </Button>
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
                  {(read?.without_hire_date ?? 0) > 0 &&
                    !(canComplete && mode === "complete") && (
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
                  {warningGroups.length > 0 && (
                    <ProblemTable
                      heading={t(
                        "setup2.warnings_heading",
                        "Worth knowing before you add them",
                      )}
                      groups={warningGroups}
                      tone="warning"
                    />
                  )}
                  {problemGroups.length > 0 && (
                    <ProblemTable
                      heading={t("setup2.problem_heading", "Lines to fix")}
                      groups={problemGroups}
                      tone="error"
                    />
                  )}
                  {toFix && (
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={() => void downloadFixList()}
                    >
                      <Download className="mr-2 h-4 w-4" aria-hidden="true" />
                      {t(
                        "setup2.problem_download_fix",
                        "Download the lines to fix",
                      )}
                    </Button>
                  )}
                  {refusedNow && (
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
                  label={(column) => columnLabel(column, t)}
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
              {pending && (
                <div
                  role="alert"
                  className="space-y-2 rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm text-foreground"
                >
                  <p>{pending.message}</p>
                  <div className="flex flex-wrap gap-2">
                    <Button
                      size="sm"
                      onClick={() => void carryOnStagingList()}
                      disabled={carryOn.isPending}
                    >
                      {carryOn.isPending ? (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                      ) : null}
                      {carryOn.isPending && staging
                        ? stagingText(staging)
                        : t("setup2.staging_carry_on", "Carry on")}
                    </Button>
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={startOver}
                      disabled={carryOn.isPending}
                    >
                      {t("setup2.start_over", "Start over")}
                    </Button>
                  </div>
                </div>
              )}
              <Button
                className="w-full"
                onClick={() => stageCsv()}
                disabled={
                  stageRows.isPending ||
                  carryOn.isPending ||
                  pending !== null ||
                  csv.trim() === "" ||
                  problems.length > 0 ||
                  nameless ||
                  (read !== undefined && !reading && read.people === 0)
                }
              >
                {stageRows.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : null}
                {stageRows.isPending && staging
                  ? stagingText(staging)
                  : t("setup2.stage_rows", "Stage for review")}
              </Button>
              {canLeaveOut && (
                <Button
                  className="w-full"
                  variant="outline"
                  onClick={() => stageCsv(true)}
                  disabled={
                    stageRows.isPending || carryOn.isPending || pending !== null
                  }
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
          {noDevice && !listOnly && onlySends && (
            <p className="flex flex-wrap items-center gap-2 rounded-md border border-dashed border-border px-4 py-3 text-sm text-muted-foreground">
              <HardDrive className="h-4 w-4 shrink-0" aria-hidden="true" />
              {t(
                "setup2.only_sends_row",
                "Your terminal sends its punches to ETHR and cannot be asked who is enrolled on it. Badges it has sent that nobody owns yet are listed under Devices, where each can become an employee.",
              )}
              <Link
                href="/devices"
                className="font-medium text-primary underline-offset-4 hover:underline"
              >
                {t("nav.devices", "Devices")}
              </Link>
            </p>
          )}
          {noDevice && !listOnly && !onlySends && (
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
          {batch.mode === "complete" && (
            <div className="space-y-1">
              <h3 className="text-sm font-semibold text-foreground">
                {t(
                  "setup2.complete_title",
                  "Completing people who are already here",
                )}
              </h3>
              <p className="text-xs text-muted-foreground">
                {t(
                  "setup2.complete_hint",
                  "Blanks are filled in. A difference is shown and left as it is, unless it is personal and you accept it. Nobody is added.",
                )}
              </p>
            </div>
          )}
          <div className="flex flex-wrap items-center gap-2">
            {Object.entries(batch.summary ?? {}).map(([outcome, count]) => (
              <Badge
                key={outcome}
                variant="outline"
                style={{ color: outcomeTone(outcome as MatchOutcome) }}
              >
                {count}{" "}
                {outcome === "new" && batch.mode === "complete"
                  ? t("setup2.outcome_new_complete", "not found")
                  : t(`setup2.outcome_${outcome}`, outcome)}
              </Badge>
            ))}
          </div>
          {batch.mode !== "complete" &&
            sameNameCount * 2 > batch.rows.length && (
              <div
                role="status"
                className="space-y-1 rounded-md border border-warning-edge bg-warning-soft px-4 py-3 text-sm text-warning-on-soft"
              >
                <p className="font-medium">
                  {t(
                    "setup2.same_name_most",
                    ":count of these :total people are already here by name.",
                    { count: sameNameCount, total: batch.rows.length },
                  )}
                </p>
                <p className="text-xs">
                  {t(
                    "setup2.same_name_most_hint",
                    "This list may have been brought in before. Each of them is held until you say whether it is the person already here. Adding the list as it stands adds none of them again.",
                  )}
                </p>
              </div>
            )}

          {sameNameOpen > 0 && batch.status === "reviewing" && (
            <div className="flex flex-wrap items-center gap-3">
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={confirmSameName.isPending}
                onClick={runConfirmSameName}
              >
                {t(
                  "setup2.same_name_all",
                  "All :count are the people already here",
                  { count: sameNameOpen },
                )}
              </Button>
              <span className="text-xs text-muted-foreground">
                {t(
                  "setup2.same_name_all_hint",
                  "Only for rows that name one person. Check a few first.",
                )}
              </span>
            </div>
          )}

          <div className="rounded-lg border">
            <SimpleTable
              caption={t("setup2.migration_review", "Migration review")}
              headers={[
                t("setup2.person", "Person"),
                t("setup2.match", "Match"),
                t("setup2.action", "Action"),
              ]}
              rows={batch.rows.map((row) => {
                const sameName = row.match_outcome === "same_name";
                const reviewable =
                  !row.processed_at && batch.status === "reviewing";
                return {
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
                        {row.match_confidence &&
                        row.match_outcome !== "same_name"
                          ? ` · ${Math.round(row.match_confidence * 100)}%`
                          : ""}
                      </span>
                      {batch.mode === "complete" &&
                        row.match_outcome === "new" && (
                          <p className="text-[11px] text-muted-foreground">
                            {t(
                              "setup2.not_found_left_out",
                              "Not found in ETHR, left out",
                            )}
                          </p>
                        )}
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
                                {sameName ? (
                                  c.employee_code && (
                                    <span className="font-mono">
                                      · {c.employee_code}
                                    </span>
                                  )
                                ) : (
                                  <span>· {Math.round(c.score * 100)}%</span>
                                )}
                                <span className="italic">
                                  (
                                  {c.reasons
                                    .map((r) => reasonLabel(r, t))
                                    .join(", ")}
                                  )
                                </span>
                                {sameName && reviewable && (
                                  <Button
                                    type="button"
                                    size="sm"
                                    variant={
                                      chosen && row.action === "merge"
                                        ? "default"
                                        : "outline"
                                    }
                                    className="h-6 px-2 text-[11px]"
                                    aria-pressed={
                                      chosen && row.action === "merge"
                                    }
                                    onClick={() =>
                                      changeAction(
                                        row.public_id,
                                        "merge",
                                        c.employee_public_id,
                                      )
                                    }
                                  >
                                    {t(
                                      "setup2.same_name_is_them",
                                      "This is them",
                                    )}
                                  </Button>
                                )}
                                {!sameName &&
                                  !row.processed_at &&
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
                      {sameName && reviewable && batch.mode !== "complete" && (
                        <Button
                          type="button"
                          size="sm"
                          variant={
                            row.action === "create" ? "default" : "outline"
                          }
                          className="h-6 px-2 text-[11px]"
                          aria-pressed={row.action === "create"}
                          onClick={() => changeAction(row.public_id, "create")}
                        >
                          {t(
                            "setup2.same_name_different",
                            "A different person",
                          )}
                        </Button>
                      )}
                      {(row.warnings ?? []).length > 0 && (
                        <ul className="space-y-0.5 text-[11px] text-warning-on-soft">
                          {row.warnings!.map((warning) => (
                            <li key={warning.code}>{warning.message}</li>
                          ))}
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
                    ) : batch.mode === "complete" &&
                      row.match_outcome === "new" ? (
                      <span key="a" className="text-xs text-muted-foreground">
                        {t("setup2.left_out", "Left out")}
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
                          {(batch.mode === "complete"
                            ? ACTIONS.filter((a) => a !== "create")
                            : ACTIONS
                          ).map((a) => (
                            <SelectItem key={a} value={a}>
                              {t(`setup2.action_${a}`, a)}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    ),
                  ],
                };
              })}
            />
          </div>

          {batch.status !== "committed" && batch.mode !== "complete" && (
            <fieldset
              className="space-y-3 border-t pt-4 text-sm"
              disabled={commit.isPending || batch.status === "committing"}
            >
              <legend className="sr-only">
                {t("setup2.arrival_legend", "How this list is brought in")}
              </legend>
              <div className="flex flex-wrap items-center gap-2">
                <label htmlFor="list-arrival" className="text-foreground">
                  {t("setup2.arrival_label", "The new people on this list")}
                </label>
                <select
                  id="list-arrival"
                  value={batch.arrival_status ?? arrival}
                  onChange={(e) => setArrival(e.target.value as ArrivalStatus)}
                  className="h-9 rounded-md border border-border-default bg-background px-3 text-sm"
                >
                  <option value="hired">
                    {t("setup2.arrival_hired", "are newly hired")}
                  </option>
                  <option value="probation">
                    {t("setup2.arrival_probation", "are on probation")}
                  </option>
                  <option value="confirmed">
                    {t(
                      "setup2.arrival_confirmed",
                      "already work here, confirmed",
                    )}
                  </option>
                </select>
              </div>
              <p className="text-xs text-muted-foreground">
                {t(
                  "setup2.arrival_hint",
                  "Someone confirmed can take leave, be suspended or resign from the first day. A line that gives its own status keeps it.",
                )}
              </p>
              <label className="flex items-start gap-2 text-foreground">
                <input
                  type="checkbox"
                  className="mt-0.5 h-4 w-4"
                  checked={inviteNow}
                  onChange={(e) => setInviteNow(e.target.checked)}
                />
                <span>
                  {t(
                    "setup2.invite_now",
                    "Invite them to sign in as soon as they are added",
                  )}
                  <span className="block text-xs text-muted-foreground">
                    {t(
                      "setup2.invite_now_hint",
                      "People with an email are sent an invitation. People without one are listed for a printed code.",
                    )}
                  </span>
                </span>
              </label>
              {batch.sign_in_plan && (
                <MigrationReadiness
                  plan={batch.sign_in_plan}
                  inviteNow={inviteNow}
                />
              )}
            </fieldset>
          )}

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
                  ? batch.mode === "complete"
                    ? t("setup2.completing", "Filling in people, :count left", {
                        count: remaining,
                      })
                    : t("setup2.committing", "Adding people, :count left", {
                        count: remaining,
                      })
                  : batch.mode === "complete"
                    ? t("setup2.complete_commit", "Fill in these people")
                    : t("setup2.commit", "Commit migration")}
            </Button>
          </div>

          {batch.status === "committed" && (
            <div className="flex flex-wrap justify-end gap-2 border-t pt-4">
              <MigrationUndoNote batch={batch} />
              {batchId && (
                <MigrationUndoButton batchId={batchId} batch={batch} />
              )}
              <Button
                variant="outline"
                size="sm"
                onClick={() => void downloadResult()}
                disabled={batchQuery.isFetching}
              >
                <Download className="mr-2 h-4 w-4" aria-hidden="true" />
                {t("setup2.result_download", "Download what happened")}
              </Button>
            </div>
          )}

          {batch.status === "committed" && batch.mode === "complete" && (
            <div className="flex justify-end border-t pt-4">
              <Button onClick={onCommitted} disabled={!onCommitted}>
                {t("setup2.complete_continue", "Back to employees")}
              </Button>
            </div>
          )}

          {batch.status === "committed" &&
            batchId &&
            batch.mode !== "complete" && (
              <MigrationLogins
                batchId={batchId}
                onContinue={onCommitted}
                autoSend={inviteNow && commit.isSuccess}
                inviteMarked={commit.isSuccess}
                undone={Boolean(batch.undone_at)}
              />
            )}

          {batch.status === "committed" && batchId && (
            <MigrationTerminals batchId={batchId} />
          )}
        </div>
      )}
      {confirmDialog}
    </div>
  );
}
