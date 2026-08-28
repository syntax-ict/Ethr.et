"use client";

import { useState } from "react";
import { Users, Loader2, HardDrive, FileText, Check } from "lucide-react";
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
import { toast } from "sonner";
import { useDevices } from "@/features/devices/api";
import {
  useCommitMigration,
  useMigrationBatch,
  useSetRowAction,
  useStageFromDevice,
  useStageFromRows,
} from "../api";
import type { MatchOutcome, StagingAction } from "../types";

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
      return "var(--color-status-success, #059669)";
    case "probable":
      return "var(--color-status-warning, #D97706)";
    case "ambiguous":
      return "var(--color-status-error, #DC2626)";
    default:
      return "var(--color-text-secondary, #64748B)";
  }
}

/** Parse pasted lines: "name, email, phone, employee_code" (extra columns ignored). */
function parseRows(text: string): Record<string, unknown>[] {
  return text
    .split(/\r?\n/)
    .map((l) => l.trim())
    .filter(Boolean)
    .map((line) => {
      const [name, email, phone, employee_code] = line
        .split(",")
        .map((c) => c.trim());
      return {
        name,
        email: email || undefined,
        phone: phone || undefined,
        employee_code: employee_code || undefined,
      };
    })
    .filter((r) => r.name);
}

export function MigrationStep({ onCommitted }: { onCommitted?: () => void }) {
  const { t } = useT();
  const devicesQuery = useDevices();
  const stageDevice = useStageFromDevice();
  const stageRows = useStageFromRows();
  const setAction = useSetRowAction();
  const commit = useCommitMigration();

  const [batchId, setBatchId] = useState<string | null>(null);
  const [deviceId, setDeviceId] = useState<string>("");
  const [csv, setCsv] = useState("");

  const batchQuery = useMigrationBatch(batchId);
  const batch = batchQuery.data;

  async function discoverDevice() {
    if (!deviceId) return;
    try {
      const b = await stageDevice.mutateAsync(deviceId);
      setBatchId(b.public_id);
    } catch {
      toast.error(t("setup2.stage_failed", "Could not read the device"));
    }
  }

  async function stageCsv() {
    const rows = parseRows(csv);
    if (rows.length === 0) {
      toast.error(t("setup2.no_rows", "Add at least one row (name required)"));
      return;
    }
    try {
      const b = await stageRows.mutateAsync({ rows });
      setBatchId(b.public_id);
    } catch {
      toast.error(t("setup2.stage_failed", "Could not stage rows"));
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
    } catch {
      toast.error(t("setup2.save_failed", "Save failed"));
    }
  }

  async function runCommit() {
    if (!batchId) return;
    try {
      const result = await commit.mutateAsync(batchId);
      toast.success(
        t(
          "setup2.migration_done",
          "Migration complete — {{c}} created, {{m}} merged",
        )
          .replace("{{c}}", String(result.totals.created ?? 0))
          .replace("{{m}}", String(result.totals.merged ?? 0)),
      );
      onCommitted?.();
    } catch {
      toast.error(t("setup2.commit_failed", "Could not commit migration"));
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
          <Users className="h-5 w-5 text-primary" />
        </div>
        <div>
          <h2 className="text-xl font-bold text-foreground">
            {t("setup2.migration_title", "Bring in your workforce")}
          </h2>
          <p className="text-sm text-muted-foreground">
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
                <HardDrive className="h-4 w-4" />
                {t("setup2.from_device", "From an attendance device")}
              </div>
              <Select value={deviceId} onValueChange={setDeviceId}>
                <SelectTrigger>
                  <SelectValue
                    placeholder={t("setup2.select_device", "Select a device")}
                  />
                </SelectTrigger>
                <SelectContent>
                  {(devicesQuery.data?.data ?? []).map((d) => (
                    <SelectItem key={d.public_id} value={d.public_id}>
                      {d.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <Button
                className="w-full"
                onClick={discoverDevice}
                disabled={!deviceId || stageDevice.isPending}
              >
                {stageDevice.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : null}
                {t("setup2.discover", "Discover enrolled users")}
              </Button>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="space-y-3 p-4">
              <div className="flex items-center gap-2 text-sm font-semibold text-foreground">
                <FileText className="h-4 w-4" />
                {t("setup2.from_list", "Paste a list")}
              </div>
              <textarea
                value={csv}
                onChange={(e) => setCsv(e.target.value)}
                rows={5}
                placeholder={"Abebe Kebede, abebe@acme.et, 0911223344, EMP-1"}
                className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              />
              <p className="text-xs text-muted-foreground">
                {t(
                  "setup2.csv_hint",
                  "One person per line: name, email, phone, employee number.",
                )}
              </p>
              <Button
                className="w-full"
                variant="outline"
                onClick={stageCsv}
                disabled={stageRows.isPending}
              >
                {stageRows.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : null}
                {t("setup2.stage_rows", "Stage for review")}
              </Button>
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
        </div>
      )}
    </div>
  );
}
