"use client";

import { useId, useState } from "react";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useConfirm } from "@/components/shared/use-confirm";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";
import { useDeleteImportSource, useSaveImportSource } from "../api";
import type { ColumnMatch, ImportSource } from "../types";

/**
 * The columns a file's column may be, in the order the template lists them
 * (`EmployeeImportRow::MAPPABLE`). The server refuses any other.
 */
export const MATCH_COLUMNS = [
  "name",
  "name_am",
  "employee_code",
  "email",
  "phone",
  "gender",
  "date_of_birth",
  "hire_date",
  "department_code",
  "branch_code",
  "position_code",
  "salary",
  "national_id",
  "tin",
  "badge_number",
  "nationality",
  "nationality_status",
  "marital_status",
  "status",
  "external_id",
] as const;

/**
 * Says which column of another system's file is which.
 *
 * A list exported from another HR or payroll system names its columns its
 * own way. Each one is shown as the file writes it, with a value from the
 * first person, and a choice of what it is in ETHR or that it is left out.
 * The server reads the list again on every choice, so the count above always
 * describes the columns as they are matched here.
 *
 * Matched once, the columns can be saved under the system's name. The next
 * list from that system is then read without asking, and an id from it finds
 * the same person every time.
 *
 * @param matches Each column name with its answer. The reader's own answer
 *   where they have given one: the server's reading lags a choice by a
 *   request, and an answer built from it would undo the choice before.
 * @param samples A value under each column, by the column's name as written.
 * @param label What an ETHR column is called on screen.
 * @param source The saved source the list is read as, when it is one.
 * @param calendar The calendar the list's dates are in, kept with the source.
 * @param needsName True when no column is matched to the name.
 * @param onMatch Called with every column's answer when one changes.
 * @param onSaved Called with the source once the columns are saved under it.
 * @param onForgotten Called when the saved source has been removed.
 */
export function ColumnMatcher({
  matches,
  samples,
  label,
  source,
  calendar,
  needsName,
  onMatch,
  onSaved,
  onForgotten,
}: {
  matches: ColumnMatch[];
  samples: Record<string, string>;
  label: (column: string) => string;
  source: ImportSource | null;
  calendar: "ethiopian" | "gregorian";
  needsName: boolean;
  onMatch: (matches: ColumnMatch[]) => void;
  onSaved: (source: ImportSource) => void;
  onForgotten: () => void;
}) {
  const { t } = useT();
  const { requestConfirm, confirmDialog } = useConfirm();
  const save = useSaveImportSource();
  const forget = useDeleteImportSource();
  const [name, setName] = useState("");
  const nameId = useId();

  function choose(index: number, column: string) {
    onMatch(
      matches.map((m, i) =>
        i === index ? { ...m, column: column === "" ? null : column } : m,
      ),
    );
  }

  async function saveColumns() {
    try {
      const saved = await save.mutateAsync(
        source
          ? { public_id: source.public_id, mapping: matches }
          : { name: name.trim(), mapping: matches, calendar },
      );
      setName("");
      onSaved(saved);
      toast.success(
        t(
          "setup2.source_saved",
          "Saved. The next list from :name is read without asking.",
          { name: saved.name },
        ),
      );
    } catch (err) {
      toast.error(
        apiErrorMessage(
          err,
          t("setup2.source_save_failed", "Could not save the columns"),
        ),
      );
    }
  }

  async function forgetSource() {
    if (!source) return;
    const confirmed = await requestConfirm({
      title: t("setup2.source_forget_title", "Forget :name?", {
        name: source.name,
      }),
      description: t(
        "setup2.source_forget_desc",
        "Its column names are no longer remembered. The people it brought stay as they are.",
      ),
      confirmLabel: t("setup2.source_forget_confirm", "Forget it"),
    });
    if (!confirmed) return;
    try {
      await forget.mutateAsync(source.public_id);
      onForgotten();
    } catch (err) {
      toast.error(
        apiErrorMessage(
          err,
          t("setup2.source_save_failed", "Could not save the columns"),
        ),
      );
    }
  }

  return (
    <section
      aria-label={t("setup2.match_title", "Which column is which")}
      className="space-y-3 rounded-md border border-border p-3"
    >
      {confirmDialog}
      <div className="space-y-1">
        <h3 className="text-sm font-medium">
          {t("setup2.match_title", "Which column is which")}
        </h3>
        <p className="text-xs text-muted-foreground">
          {t(
            "setup2.match_intro",
            "Say what each column of your file is. A column you leave out is not read. ETHR has filled in the ones it could guess, so check each one.",
          )}
        </p>
      </div>

      <ul className="space-y-2">
        {matches.map((h, index) => (
          <li
            key={`${index}:${h.header}`}
            className="grid grid-cols-1 items-center gap-1 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] sm:gap-3"
          >
            <div className="min-w-0 text-sm">
              <p className="truncate font-medium">{h.header}</p>
              {samples[h.header] ? (
                <p className="truncate text-xs text-muted-foreground">
                  {t("setup2.match_example", "For example: :value", {
                    value: samples[h.header],
                  })}
                </p>
              ) : null}
            </div>
            <select
              aria-label={t("setup2.match_for", "What :header is", {
                header: h.header,
              })}
              value={h.column ?? ""}
              onChange={(e) => choose(index, e.target.value)}
              className="h-9 w-full rounded-md border border-border-default bg-background px-3 text-sm"
            >
              <option value="">
                {t("setup2.match_leave_out", "Leave out")}
              </option>
              {MATCH_COLUMNS.map((column) => (
                <option key={column} value={column}>
                  {label(column)}
                </option>
              ))}
            </select>
          </li>
        ))}
      </ul>

      {needsName && (
        <p className="text-xs text-destructive" role="alert">
          {t(
            "setup2.match_needs_name",
            "Choose which column holds the person's name. Nothing can be read without it.",
          )}
        </p>
      )}

      <div className="space-y-2 border-t border-border pt-3">
        {source ? (
          <>
            <p className="text-xs text-muted-foreground">
              {t(
                "setup2.source_reading_as",
                "This list is read as :name. Its ids from that system find the same people each time.",
                { name: source.name },
              )}
            </p>
            <div className="flex flex-wrap gap-2">
              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={saveColumns}
                disabled={save.isPending || needsName}
              >
                {save.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : null}
                {t("setup2.source_update", "Update the saved columns")}
              </Button>
              <Button
                type="button"
                size="sm"
                variant="ghost"
                onClick={forgetSource}
                disabled={forget.isPending}
              >
                {t("setup2.source_forget", "Forget :name", {
                  name: source.name,
                })}
              </Button>
            </div>
          </>
        ) : (
          <>
            <Label htmlFor={nameId} className="text-xs">
              {t(
                "setup2.source_save_as",
                "Save these columns for the next list from this system",
              )}
            </Label>
            <div className="flex flex-wrap gap-2">
              <Input
                id={nameId}
                value={name}
                maxLength={80}
                onChange={(e) => setName(e.target.value)}
                placeholder={t(
                  "setup2.source_name",
                  "The system's name, for example Peachtree payroll",
                )}
                className="h-9 min-w-0 flex-1"
              />
              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={saveColumns}
                disabled={save.isPending || needsName || name.trim() === ""}
              >
                {save.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : null}
                {t("setup2.source_save", "Save")}
              </Button>
            </div>
          </>
        )}
      </div>
    </section>
  );
}
