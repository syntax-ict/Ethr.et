"use client";

import { SimpleTable } from "@/components/shared/simple-table";
import { columnLabel } from "@/features/employees/employee-columns";
import { useT } from "@/lib/i18n/useT";
import { type FoundGroup, lineNumbers } from "../import-problems";

/**
 * What a read found wrong, or worth knowing, grouped by the column it is
 * about: what to fix, how many lines, and which. Every group is shown; a
 * long file's forty problems are a handful of rows here, not forty lines.
 *
 * The heading says what the table is, so the meaning is in words and not
 * only in the colour of the text.
 */
export function ProblemTable({
  heading,
  groups,
  tone,
}: {
  heading: string;
  groups: FoundGroup[];
  tone: "error" | "warning";
}) {
  const { t } = useT();

  return (
    <div className="space-y-1">
      <p
        className={
          tone === "error"
            ? "font-medium text-destructive"
            : "font-medium text-warning-on-soft"
        }
      >
        {heading}
      </p>
      <div className="rounded-md border md:[&_table]:table-fixed [&_td]:break-words [&_td]:px-3 [&_th]:px-3">
        <SimpleTable
          caption={heading}
          headers={[
            t("setup2.template_column", "Column"),
            t("setup2.problem_col_what", "What to fix"),
            t("setup2.problem_col_count", "Lines"),
            t("setup2.problem_col_lines", "Line numbers"),
          ]}
          align={["left", "left", "right", "left"]}
          colClassName={[
            "md:w-[17%]",
            "md:w-[43%]",
            "md:w-[10%]",
            "md:w-[30%]",
          ]}
          rows={groups.map((group) => {
            const { shown, more } = lineNumbers(group.lines);
            return {
              key: group.key,
              cells: [
                group.column === null
                  ? t("setup2.problem_whole_list", "Whole list")
                  : columnLabel(group.column, t),
                group.message,
                group.lines.length,
                more > 0
                  ? `${shown.join(", ")} ${t("setup2.more_problems", "and :count more", { count: more })}`
                  : shown.join(", "),
              ],
            };
          })}
        />
      </div>
    </div>
  );
}
