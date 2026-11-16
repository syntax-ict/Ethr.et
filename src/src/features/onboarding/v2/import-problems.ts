/**
 * What a read says about a list, arranged for a person to act on: the
 * problems grouped by column and kind, the lines that were refused, and what
 * became of each line after the list was added.
 *
 * The server says what is wrong and why (EmployeeImportGrid). This only
 * gathers it: nothing here decides whether a line is acceptable.
 */

import { templateKey } from "@/features/employees/employee-columns";
import type { MigrationBatch, PeopleRead, StagingRow } from "./types";

/** A problem or a warning from a read. `column` is null for the whole list. */
export interface Found {
  line: number;
  message: string;
  column: string | null;
  code: string;
}

/** The problems of one column and kind, together. */
export interface FoundGroup {
  key: string;
  column: string | null;
  code: string;
  /** The first of them, with its own value left in. */
  message: string;
  /** Line numbers as the reader counts them, in the order found. */
  lines: number[];
}

/** Line numbers listed in full before "and N more". */
export const LINES_SHOWN = 10;

/** Problems and warnings are grouped by what they are about, largest group first. */
export function groupFound(items: readonly Found[]): FoundGroup[] {
  const groups = new Map<string, FoundGroup>();
  for (const item of items) {
    const key = `${item.column ?? ""}\u0000${item.code}`;
    const group = groups.get(key);
    if (group) {
      if (!group.lines.includes(item.line)) group.lines.push(item.line);
    } else {
      groups.set(key, {
        key,
        column: item.column,
        code: item.code,
        message: item.message,
        lines: [item.line],
      });
    }
  }
  // Array.sort is stable: groups of the same size stay in the order found.
  return [...groups.values()].sort((a, b) => b.lines.length - a.lines.length);
}

/** The lines the reader names as a list, and how many are left out of it. */
export function lineNumbers(lines: readonly number[]): {
  shown: number[];
  more: number;
} {
  return {
    shown: lines.slice(0, LINES_SHOWN),
    more: Math.max(0, lines.length - LINES_SHOWN),
  };
}

/** One refused line, as it was read, and why it was refused. */
export interface FixLine {
  cells: string[];
  /** "Line 14: the messages, joined". */
  problem: string;
}

/**
 * The lines a read refused, with the reader's own header line: the line the
 * server found the column names on, which a title above them may have pushed
 * down. Without a header line, the columns the server read the list as stand in for one, in
 * the order it read them.
 *
 * `say` composes a line's messages into the cell's words, in the reader's
 * language. A problem about the whole list is not about a line and is left
 * out. Nothing is returned when no line was refused.
 */
export function fixList(
  grid: readonly (readonly string[])[],
  read: Pick<PeopleRead, "header" | "header_line" | "columns" | "problems">,
  say: (line: number, messages: string[]) => string,
): { header: string[]; lines: FixLine[] } | null {
  const firstLine = grid.findIndex((cells) =>
    cells.some((c) => c.trim() !== ""),
  );
  // The line the names were on, as the reader counts: the server says, and
  // for an answer that does not, the first line with anything in it.
  const headerAt = read.header_line ?? firstLine + 1;
  const header = read.header
    ? [...(grid[headerAt - 1] ?? [])]
    : read.columns.map(templateKey);

  const messages = new Map<number, string[]>();
  for (const problem of read.problems) {
    // A problem about the whole list, or about the header line, has no line to fix.
    if (problem.column === null && problem.code === "too_many") continue;
    if (read.header && problem.line === headerAt) continue;
    if (problem.line < 1 || problem.line > grid.length) continue;
    messages.set(problem.line, [
      ...(messages.get(problem.line) ?? []),
      problem.message,
    ]);
  }
  if (messages.size === 0) return null;

  const lines = [...messages.entries()]
    .sort(([a], [b]) => a - b)
    .map(([line, found]) => ({
      cells: [...grid[line - 1]],
      problem: say(line, found),
    }));

  return { header, lines };
}

/** What became of a line of the list. */
export type Outcome =
  "added" | "filled" | "unchanged" | "left_out" | "not_found";

/** What became of a row once the list was added; a row not yet dealt with was left out. */
export function outcomeOf(
  row: StagingRow,
  mode: MigrationBatch["mode"],
): Outcome {
  if (mode === "complete" && row.match_outcome === "new") return "not_found";
  if (!row.processed_at) return "left_out";
  if (row.action === "create") return "added";
  if (row.action === "merge") {
    // The commit writes only for someone who may edit the person.
    const wrote =
      row.can_apply_changes &&
      row.changes.some(
        (change) =>
          (change.kind === "fill" && !change.taken) ||
          (change.kind === "conflict" && change.accepted),
      );
    return wrote ? "filled" : "unchanged";
  }
  return "left_out";
}
