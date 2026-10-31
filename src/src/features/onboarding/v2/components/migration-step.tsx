"use client";

import { useRef, useState } from "react";
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
import { departmentsApi } from "@/features/organization/api";
import { saveCsv } from "@/lib/utils/csv-export";
import {
  useCommitMigration,
  useMigrationBatch,
  useSetRowAction,
  useStageFromDevice,
  useStageFromRows,
} from "../api";
import type { MatchOutcome, StagingAction } from "../types";
import {
  MAX_PEOPLE,
  parsePeople,
  peopleTemplateCsv,
  type PersonField,
  type Problem,
} from "../parse-people";
import { MigrationLogins } from "./migration-logins";

const ACTIONS: StagingAction[] = ["merge", "create", "skip", "defer"];

/** `IdentityResolver` reasons: known signal names as-is, `name~0.85` for the
 * fuzzy-name-similarity signal (score baked into the key). */
function reasonLabel(
  reason: string,
  t: (key: string, fallback?: string) => string,
): string {
  if (reason.startsWith("name~")) {
    const pct = Math.round(Number(reason.slice("name~".length)) * 100);
    return `${t("setup2.reason_name", "name")} ~${pct}%`;
  }
  return t(`setup2.reason_${reason}`, reason.replace(/_/g, " "));
}

function outcomeTone(outcome: MatchOutcome | null): string {
  switch (outcome) {
    case "matched":
      return "var(--color-status-success, #047857)";
    case "probable":
      return "var(--color-status-warning, #92400E)";
    case "ambiguous":
      return "var(--color-status-error, #B91C1C)";
    default:
      return "var(--color-text-secondary, #64748B)";
  }
}

const FIELD_LABELS: Record<PersonField, [string, string]> = {
  name: ["setup2.col_name", "Name"],
  email: ["setup2.col_email", "Email"],
  phone: ["setup2.col_phone", "Phone"],
  employee_code: ["setup2.col_employee_code", "Employee number"],
  hire_date: ["setup2.col_hire_date", "Hire date"],
  department: ["setup2.col_department", "Department"],
};

/** Problems shown under the list before the rest are counted. */
const PROBLEMS_SHOWN = 5;

/**
 * @param onCommitted Called when the reviewer moves on after the commit, from
 *   the "give them a way to sign in" step, not at the commit itself.
 * @param initialDeviceId Preselects the device, when opened from that device.
 */
export function MigrationStep({
  onCommitted,
  initialDeviceId,
}: {
  onCommitted?: () => void;
  initialDeviceId?: string;
}) {
  const { t } = useT();
  const { requestConfirm, confirmDialog } = useConfirm();
  const devicesQuery = useDevices();
  const stageDevice = useStageFromDevice();
  const stageRows = useStageFromRows();
  const setAction = useSetRowAction();
  const commit = useCommitMigration();

  const [batchId, setBatchId] = useState<string | null>(null);
  const [deviceId, setDeviceId] = useState<string>(initialDeviceId ?? "");
  const [csv, setCsv] = useState("");
  const fileInput = useRef<HTMLInputElement>(null);

  const batchQuery = useMigrationBatch(batchId);
  const batch = batchQuery.data;

  const devices = devicesQuery.data?.data ?? [];
  // One device is the device: there is nothing to choose.
  const device = deviceId || (devices.length === 1 ? devices[0].public_id : "");
  // The departments the list may name, so one the organisation does not have
  // is reported before staging instead of refused by the server after.
  const departmentsQuery = departmentsApi.useList();
  const parsed = parsePeople(csv, departmentsQuery.data?.data);

  function problemText(problem: Problem): string {
    switch (problem.kind) {
      case "no_name":
        return t("setup2.problem_no_name", "Line :line has no name.", {
          line: problem.line,
        });
      case "bad_email":
        return t(
          "setup2.problem_bad_email",
          "Line :line: :value is not an email address.",
          { line: problem.line, value: problem.value ?? "" },
        );
      case "bad_date":
        return t(
          "setup2.problem_bad_date",
          "Line :line: write the hire date :value as YYYY-MM-DD, for example 2019-07-08.",
          { line: problem.line, value: problem.value ?? "" },
        );
      case "duplicate_code":
        return t(
          "setup2.problem_duplicate_code",
          "Line :line: employee number :value is also on line :first. Each person needs their own.",
          {
            line: problem.line,
            value: problem.value ?? "",
            first: problem.firstLine ?? "",
          },
        );
      case "duplicate_email":
        return t(
          "setup2.problem_duplicate_email",
          "Line :line: :value is also on line :first. Each person needs their own email address.",
          {
            line: problem.line,
            value: problem.value ?? "",
            first: problem.firstLine ?? "",
          },
        );
      case "unknown_department":
        return t(
          "setup2.problem_unknown_department",
          "Line :line: there is no department :value. Use a department's name or code as it is in ETHR, or leave it empty.",
          { line: problem.line, value: problem.value ?? "" },
        );
      case "too_many":
        return t(
          "setup2.problem_too_many",
          "This list has :value people. Bring in at most :max at a time.",
          { value: problem.value ?? "", max: MAX_PEOPLE },
        );
    }
  }

  async function readFile(file: File) {
    if (/\.xlsx?$/i.test(file.name)) {
      toast.error(
        t(
          "setup2.xlsx_not_read",
          "Excel files are not read directly. Select the rows in Excel, copy them and paste them here, or save the sheet as CSV.",
        ),
      );
      return;
    }
    setCsv(await file.text());
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

  async function stageCsv() {
    const { rows, problems } = parsed;
    if (problems.length > 0 || rows.length === 0) return;
    try {
      const b = await stageRows.mutateAsync({ rows });
      setBatchId(b.public_id);
    } catch (err) {
      toast.error(
        apiErrorMessage(err, t("setup2.stage_failed", "Could not stage rows")),
      );
    }
  }

  async function changeAction(
    rowId: string,
    action: StagingAction,
    employeePublicId?: string,
  ) {
    try {
      await setAction.mutateAsync({ rowId, action, employeePublicId });
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
          "Migration complete: :created created, :merged merged",
          {
            created: result.totals.created ?? 0,
            merged: result.totals.merged ?? 0,
          },
        ),
      );
    } catch (err) {
      toast.error(
        apiErrorMessage(
          err,
          t("setup2.commit_failed", "Could not commit migration"),
        ),
      );
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <div>
          <h2 className="text-lg font-semibold text-foreground">
            {t("setup2.migration_title", "Bring in your workforce")}
          </h2>
          <p className="mt-1 text-sm text-muted-foreground">
            {t(
              "setup2.migration_desc",
              "Discover people from an attendance device or a list. We match them to existing employees so nobody is duplicated.",
            )}
          </p>
        </div>
      </div>

      {!batch && (
        <div className="grid gap-4 lg:grid-cols-2">
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
              {devicesQuery.isSuccess && devices.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                  {t(
                    "setup2.no_device",
                    "No attendance device is connected yet.",
                  )}{" "}
                  <Link
                    href="/devices"
                    className="font-medium text-primary underline-offset-4 hover:underline"
                  >
                    {t("setup2.connect_device", "Connect a device")}
                  </Link>
                </p>
              ) : (
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
              )}
            </CardContent>
          </Card>

          <Card>
            <CardContent className="space-y-3 p-4">
              <div className="flex items-center gap-2 text-sm font-semibold text-foreground">
                <FileSpreadsheet className="h-4 w-4" aria-hidden="true" />
                {t("setup2.from_sheet", "From a spreadsheet or list")}
              </div>
              <p className="text-xs text-muted-foreground">
                {t(
                  "setup2.sheet_hint",
                  "Copy the rows from Excel or Google Sheets and paste them here, or choose a CSV file. A first row of column names is recognised, in any order.",
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
                  {t("setup2.choose_csv", "Choose a CSV file")}
                </Button>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={() =>
                    saveCsv("ethr-people-template.csv", peopleTemplateCsv())
                  }
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
              <textarea
                value={csv}
                onChange={(e) => setCsv(e.target.value)}
                rows={6}
                aria-label={t(
                  "setup2.from_sheet",
                  "From a spreadsheet or list",
                )}
                placeholder={
                  "Name, Email, Phone, Employee number, Hire date, Department\nAbebe Kebede, abebe@acme.et, 0911223344, EMP-1, 2019-07-08, Finance"
                }
                className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              />
              {csv.trim() !== "" && (
                <div className="space-y-1 text-xs" aria-live="polite">
                  <p className="font-medium text-foreground">
                    {parsed.rows.length === 1
                      ? t("setup2.people_ready_one", "1 person ready")
                      : t("setup2.people_ready", ":count people ready", {
                          count: parsed.rows.length,
                        })}
                    {parsed.header && (
                      <span className="font-normal text-muted-foreground">
                        {" · "}
                        {t("setup2.columns_read", "Columns: :columns", {
                          columns: parsed.header
                            .map((f) => t(...FIELD_LABELS[f]))
                            .join(", "),
                        })}
                      </span>
                    )}
                  </p>
                  {parsed.problems.length > 0 && (
                    <ul className="space-y-0.5 text-destructive">
                      {parsed.problems.slice(0, PROBLEMS_SHOWN).map((p) => (
                        <li key={`${p.line}-${p.kind}`}>{problemText(p)}</li>
                      ))}
                      {parsed.problems.length > PROBLEMS_SHOWN && (
                        <li>
                          {t("setup2.more_problems", "and :count more", {
                            count: parsed.problems.length - PROBLEMS_SHOWN,
                          })}
                        </li>
                      )}
                    </ul>
                  )}
                </div>
              )}
              <p className="text-xs text-muted-foreground">
                {t(
                  "setup2.csv_hint",
                  "Without column names, the order is: name, email, phone, employee number, hire date (YYYY-MM-DD), department. Only the name is required. Without a hire date, today is used.",
                )}
              </p>
              <Button
                className="w-full"
                onClick={stageCsv}
                disabled={
                  stageRows.isPending ||
                  parsed.rows.length === 0 ||
                  parsed.problems.length > 0
                }
              >
                {stageRows.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : null}
                {t("setup2.stage_rows", "Stage for review")}
              </Button>
              <p className="text-xs text-muted-foreground">
                {t(
                  "setup2.import_instead",
                  "Need salaries, contracts and bank details too?",
                )}{" "}
                <Link
                  href="/employees/import"
                  className="font-medium text-primary underline-offset-4 hover:underline"
                >
                  {t("setup2.import_link", "Use the full employee import")}
                </Link>
              </p>
            </CardContent>
          </Card>
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
