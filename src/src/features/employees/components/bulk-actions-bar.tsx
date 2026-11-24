"use client";

import { useState } from "react";
import {
  Archive,
  Briefcase,
  Building2,
  GitBranch,
  KeyRound,
  Landmark,
  Loader2,
  UserCog,
  UserRound,
  Users,
} from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import {
  EmployeeSearchSelect,
  type PickedEmployee,
} from "@/components/shared/employee-search-select";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  branchesApi,
  costCentersApi,
  departmentsApi,
  positionsApi,
  teamsApi,
} from "@/features/organization/api";
import {
  useBulkUpdateEmployees,
  type BulkUpdateRun,
  type EmployeeIdsResult,
} from "@/features/employees/api";
import { ArchiveDialog } from "@/features/employees/components/archive-dialog";
import { GiveSignInDialog } from "@/features/employees/components/give-sign-in-dialog";
import type { SignInPerson } from "@/features/employees/sign-in";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { useTenantTimezone } from "@/lib/hooks/useTenantTimezone";
import { todayIn } from "@/lib/utils/date";
import { apiErrorMessage } from "@/lib/api/error-message";

type BulkField =
  | "department_id"
  | "branch_id"
  | "position_id"
  | "supervisor_id"
  | "team_id"
  | "cost_center_id"
  | "status";

// Mirrors App\Enums\EmployeeStatus. Every case is offered because a selection
// can mix statuses: the backend applies the transition rules per employee and
// reports the ones it refused, so the choice is checked there, not here.
const STATUS_OPTIONS = [
  "hired",
  "probation",
  "confirmed",
  "suspended",
  "resigned",
  "terminated",
  "retired",
] as const;

/**
 * Statuses that take someone off the active workforce. Each is recorded with
 * the reason in the employee's history, as a single change from the lifecycle
 * tab is; the bulk dialog sent none, so a batch of terminations carried no
 * reason and the date of the click (FRONTEND_REDESIGN_PLAN.md, Phase 4).
 */
const NEEDS_REASON: readonly string[] = [
  "suspended",
  "resigned",
  "terminated",
  "retired",
];

interface BulkActionsBarProps {
  selectedIds: string[];
  /**
   * Called after a bulk action succeeds, so the caller can clear selection.
   * `result` is the update's answer when something in it needs showing person
   * by person (a refusal, someone already as asked, a role left waiting).
   */
  onComplete: (result?: BulkUpdateRun) => void;
  /** The rows on screen, so the sign-in dialog can tell who has no email and no phone. */
  people?: SignInPerson[];
  /** Everyone the list's search and filters match, from the list's own count. */
  totalMatching?: number;
  /**
   * Asks the server for the ids of everyone the list matches. The ids go to the
   * bulk endpoints, which check each person again; nothing changes by filter.
   */
  fetchAllMatching?: () => Promise<EmployeeIdsResult>;
}

/** One organisation list as a plain select, mounted only for the field chosen. */
function ListSelect({
  useList,
  value,
  onChange,
  label,
}: {
  useList: () => {
    data?: { data: { public_id: string; name?: string; title?: string }[] };
  };
  value: string;
  onChange: (value: string) => void;
  label: string;
}) {
  const { t } = useT();
  const { data } = useList();

  return (
    <Select value={value} onValueChange={onChange}>
      <SelectTrigger aria-label={label}>
        <SelectValue
          placeholder={t("employees.bulk.select_value", "Select a value")}
        />
      </SelectTrigger>
      <SelectContent>
        {data?.data?.map((x) => (
          <SelectItem key={x.public_id} value={x.public_id}>
            {x.name ?? x.title}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}

export function BulkActionsBar({
  selectedIds,
  onComplete,
  people = [],
  totalMatching,
  fetchAllMatching,
}: BulkActionsBarProps) {
  const { t } = useT();
  const { can, hasPermission } = usePermissions();
  // The endpoint needs both: it makes logins and it edits the employee.
  const maySignIn = can.inviteUsers && hasPermission("employee.update");
  const [signInOpen, setSignInOpen] = useState(false);
  const [archiveOpen, setArchiveOpen] = useState(false);
  const timeZone = useTenantTimezone();
  // Today where the organisation is, not the UTC date `toISOString()` gives.
  const today = () => todayIn(timeZone);
  const [field, setField] = useState<BulkField | null>(null);
  const [value, setValue] = useState("");
  const [effectiveDate, setEffectiveDate] = useState(today);
  const [reason, setReason] = useState("");
  const [supervisor, setSupervisor] = useState<PickedEmployee | null>(null);
  const reasonRequired = field === "status" && NEEDS_REASON.includes(value);
  const { data: departments } = departmentsApi.useList();
  const { data: branches } = branchesApi.useList();
  const bulkUpdate = useBulkUpdateEmployees();

  // "Select everyone matching": the ids the server named, good only while the
  // page's own selection is the one they were asked for. Unticking a row, or
  // moving to another page, ends it.
  const [matching, setMatching] = useState<{
    key: string;
    result: EmployeeIdsResult;
  } | null>(null);
  const [selectingAll, setSelectingAll] = useState(false);
  const pageKey = selectedIds.join(",");
  const allMatching = matching?.key === pageKey ? matching.result : null;
  const ids = allMatching ? allMatching.ids : selectedIds;
  const everyRowTicked =
    people.length > 0 &&
    people.every((p) => selectedIds.includes(p.public_id)) &&
    selectedIds.length === people.length;
  const offerAll =
    !allMatching &&
    fetchAllMatching !== undefined &&
    everyRowTicked &&
    (totalMatching ?? 0) > people.length;

  async function selectAllMatching() {
    if (!fetchAllMatching) return;
    setSelectingAll(true);
    try {
      setMatching({ key: pageKey, result: await fetchAllMatching() });
    } catch (err) {
      toast.error(
        apiErrorMessage(
          err,
          t(
            "employees.bulk.select_all_failed",
            "The list of people could not be loaded.",
          ),
        ),
      );
    } finally {
      setSelectingAll(false);
    }
  }

  function openDialog(next: BulkField) {
    setField(next);
    setValue("");
    setSupervisor(null);
    setEffectiveDate(today());
    setReason("");
  }

  function closeDialog() {
    setField(null);
    setValue("");
    setSupervisor(null);
    setReason("");
  }

  function confirm() {
    if (!field || !value) return;
    if (reasonRequired && !reason.trim()) return;
    bulkUpdate.mutate(
      field === "status"
        ? {
            employee_ids: ids,
            status: value,
            effective_date: effectiveDate,
            reason: reason.trim() || undefined,
          }
        : { employee_ids: ids, [field]: value },
      {
        onSuccess: (data) => {
          const refused = data.results.filter((r) => r.outcome === "failed");
          if (data.unsent.length > 0) {
            toast.warning(
              t(
                "employees.bulk.partial_unsent",
                "Updated :updated employees. :count were not sent because a request failed.",
                { updated: data.updated, count: data.unsent.length },
              ),
            );
          } else if (refused.length > 0) {
            const reasons = [
              ...new Set(refused.map((r) => r.error).filter(Boolean)),
            ];
            toast.warning(
              t(
                "employees.bulk.partial",
                "Updated :updated employees. :failed could not be changed.",
                { updated: data.updated, failed: refused.length },
              ),
              { description: reasons.join(" ") },
            );
          } else {
            toast.success(
              t("employees.bulk.updated", "Updated :count employees", {
                count: data.updated,
              }),
            );
          }
          const needsDetail =
            data.unsent.length > 0 ||
            data.results.some(
              (r) =>
                r.outcome !== "updated" ||
                (r.outcome === "updated" && r.access_held),
            );
          closeDialog();
          onComplete(needsDetail ? data : undefined);
        },
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t("employees.bulk.failed", "Bulk update failed"),
            ),
          ),
      },
    );
  }

  const titles: Record<BulkField, string> = {
    department_id: t("employees.bulk.change_department", "Change department"),
    branch_id: t("employees.bulk.change_branch", "Change branch"),
    position_id: t("employees.bulk.change_position", "Change job title"),
    supervisor_id: t("employees.bulk.change_supervisor", "Change supervisor"),
    team_id: t("employees.bulk.change_team", "Change team"),
    cost_center_id: t(
      "employees.bulk.change_cost_center",
      "Change cost centre",
    ),
    status: t("employees.bulk.change_status", "Change status"),
  };

  const hints: Partial<Record<BulkField, string>> = {
    status: t(
      "employees.bulk.status_rules",
      "Anyone who cannot move to the chosen status keeps their current one.",
    ),
    position_id: t(
      "employees.bulk.position_rules",
      "A job title can give a person's login a different role. You give only the roles you could give on the Users page, and the rest wait there.",
    ),
    supervisor_id: t(
      "employees.bulk.supervisor_rules",
      "A person cannot be their own supervisor, or report to someone who reports to them. Anyone this would affect keeps their current supervisor.",
    ),
  };

  return (
    <>
      <div className="space-y-2 rounded-lg border border-border/60 bg-muted/40 px-3 py-2">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-sm font-medium text-foreground">
            {t("table.count_selected", ":count selected", {
              count: ids.length,
            })}
          </span>
          <div className="flex flex-wrap items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => openDialog("department_id")}
            >
              <Building2 className="mr-2 h-3.5 w-3.5" />
              {titles.department_id}
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => openDialog("branch_id")}
            >
              <GitBranch className="mr-2 h-3.5 w-3.5" />
              {titles.branch_id}
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => openDialog("position_id")}
            >
              <Briefcase className="mr-2 h-3.5 w-3.5" aria-hidden="true" />
              {titles.position_id}
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => openDialog("supervisor_id")}
            >
              <UserRound className="mr-2 h-3.5 w-3.5" aria-hidden="true" />
              {titles.supervisor_id}
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => openDialog("team_id")}
            >
              <Users className="mr-2 h-3.5 w-3.5" aria-hidden="true" />
              {titles.team_id}
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => openDialog("cost_center_id")}
            >
              <Landmark className="mr-2 h-3.5 w-3.5" aria-hidden="true" />
              {titles.cost_center_id}
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => openDialog("status")}
            >
              <UserCog className="mr-2 h-3.5 w-3.5" />
              {titles.status}
            </Button>
            {maySignIn && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => setSignInOpen(true)}
              >
                <KeyRound className="mr-2 h-3.5 w-3.5" />
                {t("employees.bulk.sign_in.button", "Give a way to sign in")}
              </Button>
            )}
            {can.deleteEmployees && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => setArchiveOpen(true)}
              >
                <Archive className="mr-2 h-3.5 w-3.5" aria-hidden="true" />
                {t("employees.bulk.archive", "Archive")}
              </Button>
            )}
          </div>
        </div>

        {offerAll && (
          <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
            {t(
              "employees.bulk.page_all_selected",
              "All :count people on this page are selected.",
              { count: people.length },
            )}
            <Button
              variant="link"
              size="sm"
              className="h-auto p-0"
              disabled={selectingAll}
              onClick={selectAllMatching}
            >
              {selectingAll && (
                <Loader2
                  className="mr-1 h-3.5 w-3.5 animate-spin"
                  aria-hidden="true"
                />
              )}
              {t(
                "employees.bulk.select_all_matching",
                "Select all :total people matching this filter",
                { total: totalMatching ?? 0 },
              )}
            </Button>
          </p>
        )}

        {allMatching && (
          <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
            {allMatching.capped
              ? t(
                  "employees.bulk.all_matching_capped",
                  "Only the first :cap of :total people matching this filter are selected. Narrow the filter to reach the rest.",
                  { cap: allMatching.cap, total: allMatching.total },
                )
              : t(
                  "employees.bulk.all_matching_selected",
                  "All :count people matching this filter are selected.",
                  { count: allMatching.total },
                )}
            <Button
              variant="link"
              size="sm"
              className="h-auto p-0"
              onClick={() => setMatching(null)}
            >
              {t("employees.bulk.clear_all_matching", "Keep this page only")}
            </Button>
          </p>
        )}
      </div>

      {maySignIn && (
        <GiveSignInDialog
          open={signInOpen}
          onOpenChange={setSignInOpen}
          selectedIds={selectedIds}
          people={people}
          onComplete={onComplete}
        />
      )}

      {/* Mounted only while open, so its preview is asked afresh each time. */}
      {can.deleteEmployees && archiveOpen && (
        <ArchiveDialog
          selectedIds={ids}
          onClose={(done) => {
            setArchiveOpen(false);
            if (done) onComplete();
          }}
        />
      )}

      <Dialog
        open={field !== null}
        onOpenChange={(open) => !open && closeDialog()}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{field ? titles[field] : null}</DialogTitle>
            <DialogDescription>
              {t(
                "employees.bulk.affects_count",
                "This will update :count selected employees.",
                { count: ids.length },
              )}
              {field && hints[field] ? ` ${hints[field]}` : ""}
            </DialogDescription>
          </DialogHeader>

          {field === "department_id" && (
            <Select value={value} onValueChange={setValue}>
              <SelectTrigger>
                <SelectValue
                  placeholder={t(
                    "employees.bulk.select_value",
                    "Select a value",
                  )}
                />
              </SelectTrigger>
              <SelectContent>
                {departments?.data?.map((d) => (
                  <SelectItem key={d.public_id} value={d.public_id}>
                    {d.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
          {field === "branch_id" && (
            <Select value={value} onValueChange={setValue}>
              <SelectTrigger>
                <SelectValue
                  placeholder={t(
                    "employees.bulk.select_value",
                    "Select a value",
                  )}
                />
              </SelectTrigger>
              <SelectContent>
                {branches?.data?.map((b) => (
                  <SelectItem key={b.public_id} value={b.public_id}>
                    {b.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
          {field === "status" && (
            <Select value={value} onValueChange={setValue}>
              <SelectTrigger>
                <SelectValue
                  placeholder={t(
                    "employees.bulk.select_value",
                    "Select a value",
                  )}
                />
              </SelectTrigger>
              <SelectContent>
                {STATUS_OPTIONS.map((s) => (
                  <SelectItem key={s} value={s}>
                    {t(`status.${s}`, s)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
          {field === "position_id" && (
            <ListSelect
              key={field}
              useList={positionsApi.useList}
              value={value}
              onChange={setValue}
              label={titles.position_id}
            />
          )}
          {field === "team_id" && (
            <ListSelect
              key={field}
              useList={teamsApi.useList}
              value={value}
              onChange={setValue}
              label={titles.team_id}
            />
          )}
          {field === "cost_center_id" && (
            <ListSelect
              key={field}
              useList={costCentersApi.useList}
              value={value}
              onChange={setValue}
              label={titles.cost_center_id}
            />
          )}
          {/* A server search, not every employee as an option: a closed Radix
              Select still renders them all, which froze the devices page. */}
          {field === "supervisor_id" && (
            <EmployeeSearchSelect
              value={supervisor}
              onChange={(picked) => {
                setSupervisor(picked);
                setValue(picked.public_id);
              }}
            />
          )}

          {field === "status" && (
            <div className="space-y-3">
              <div>
                <Label htmlFor="bulk_effective_date" className="text-xs">
                  {t("employee.lifecycle.effective_date", "Effective Date")}
                </Label>
                <DualCalendarDateInput
                  id="bulk_effective_date"
                  value={effectiveDate}
                  onChange={setEffectiveDate}
                  required
                  className="mt-1"
                />
              </div>
              <div>
                <Label htmlFor="bulk_reason" className="text-xs">
                  {t("employee.lifecycle.reason", "Reason")}
                  {reasonRequired && (
                    <span aria-hidden="true" className="text-destructive">
                      {" "}
                      *
                    </span>
                  )}
                </Label>
                <Textarea
                  id="bulk_reason"
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  required={reasonRequired}
                  maxLength={1000}
                  rows={3}
                  className="mt-1"
                />
                <p className="mt-1 text-xs text-muted-foreground">
                  {reasonRequired
                    ? t(
                        "employees.bulk.reason_required_hint",
                        "Recorded in each employee's history.",
                      )
                    : t("common.optional", "Optional")}
                </p>
              </div>
            </div>
          )}

          <DialogFooter>
            <Button variant="outline" onClick={closeDialog}>
              {t("common.cancel", "Cancel")}
            </Button>
            <Button
              onClick={confirm}
              disabled={
                !value ||
                (reasonRequired && !reason.trim()) ||
                (field === "status" && !effectiveDate) ||
                bulkUpdate.isPending
              }
            >
              {bulkUpdate.isPending && (
                <Loader2 className="mr-2 h-3.5 w-3.5 animate-spin" />
              )}
              {t("common.confirm", "Confirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
