"use client";

import type { ReactNode } from "react";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";
import { ChartTable } from "./chart-card";
import { seriesColor } from "./chart-theme";

/** One row or column of a heatmap: a key for the data, a label for the eye. */
export interface HeatmapAxis {
  key: string;
  label: ReactNode;
  /** The label as plain text, for cell tooltips; needed when `label` is not a string. */
  name?: string;
}

export interface HeatmapData {
  rows: HeatmapAxis[];
  columns: HeatmapAxis[];
  /** The value at a row and column; `undefined` or 0 is an empty cell. */
  value: (row: string, column: string) => number | undefined;
  /** How a value is said in a tooltip or a table cell: "12 punches". */
  format?: (value: number) => string;
}

/** Shades from the muted surface up to the base colour, lightest first. */
const STEPS = [28, 46, 64, 82, 100] as const;

function axisName(axis: HeatmapAxis): string {
  return axis.name ?? (typeof axis.label === "string" ? axis.label : axis.key);
}

/** Which shade a value takes: 0 for nothing, 1 to 5 against the scale's top. */
export function heatmapLevel(value: number | undefined, max: number): number {
  if (!value || value <= 0 || max <= 0) return 0;
  return Math.min(
    STEPS.length,
    Math.max(1, Math.ceil((value / max) * STEPS.length)),
  );
}

/** The CSS colour for a shade level, in the theme's own colours. */
export function heatmapColor(level: number, color: string): string {
  if (level <= 0) return "var(--muted)";
  return `color-mix(in srgb, ${color} ${STEPS[level - 1]}%, var(--card))`;
}

/**
 * A grid of cells coloured by value: punches by day and hour, people by day
 * and shift. CSS grid, not recharts, so it costs no chart chunk and every
 * colour follows the theme through `color-mix`. The scale runs from the
 * muted surface (nothing) to the base colour (the busiest cell), with a
 * legend underneath.
 *
 * It is drawn inside a `<ChartCard>` whose children take the view, and the
 * same data renders `<HeatmapTable>` for the table view, so a screen reader
 * or a phone reads the figures as rows.
 */
export function Heatmap({
  rows,
  columns,
  value,
  format,
  color = seriesColor(0),
  max,
  columnLabelEvery = 1,
  className,
}: HeatmapData & {
  /** The base colour; defaults to the first palette colour. */
  color?: string;
  /** The scale's top; defaults to the largest value drawn. */
  max?: number;
  /** Label every nth column, where many columns would crowd the header. */
  columnLabelEvery?: number;
  className?: string;
}) {
  const { t } = useT();
  const say = format ?? ((v: number) => String(v));

  const top =
    max ??
    Math.max(
      0,
      ...rows.flatMap((row) =>
        columns.map((column) => value(row.key, column.key) ?? 0),
      ),
    );

  return (
    <div className={cn("min-w-0 space-y-3", className)}>
      <div
        className="grid gap-0.5 sm:gap-1"
        style={{
          gridTemplateColumns: `auto repeat(${columns.length}, minmax(0, 1fr))`,
        }}
      >
        <span aria-hidden="true" />
        {columns.map((column, index) => (
          <span
            key={column.key}
            className="min-w-0 text-center text-caption leading-4 text-muted-foreground"
          >
            {index % columnLabelEvery === 0 ? column.label : null}
          </span>
        ))}
        {rows.map((row) => (
          <HeatmapRow
            key={row.key}
            row={row}
            columns={columns}
            value={value}
            say={say}
            top={top}
            color={color}
          />
        ))}
      </div>
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-caption text-muted-foreground">
        <span className="flex items-center gap-1.5">
          <span
            className="h-3 w-3 shrink-0 rounded-[3px] border border-border/60"
            style={{ background: heatmapColor(0, color) }}
            aria-hidden="true"
          />
          {t("charts.heatmap_none", "None")}
        </span>
        <span className="flex items-center gap-1.5">
          <span>{t("charts.heatmap_fewer", "Fewer")}</span>
          <span className="flex gap-0.5" aria-hidden="true">
            {STEPS.map((_, i) => (
              <span
                key={i}
                className="h-3 w-3 rounded-[3px]"
                style={{ background: heatmapColor(i + 1, color) }}
              />
            ))}
          </span>
          <span>{t("charts.heatmap_more", "More")}</span>
        </span>
      </div>
    </div>
  );
}

function HeatmapRow({
  row,
  columns,
  value,
  say,
  top,
  color,
}: {
  row: HeatmapAxis;
  columns: HeatmapAxis[];
  value: HeatmapData["value"];
  say: (value: number) => string;
  top: number;
  color: string;
}) {
  return (
    <>
      <span className="flex items-center pr-2 text-caption leading-4 text-muted-foreground">
        {row.label}
      </span>
      {columns.map((column) => {
        const v = value(row.key, column.key);
        const level = heatmapLevel(v, top);
        const title = `${axisName(row)}, ${axisName(column)}: ${say(v ?? 0)}`;
        return (
          <div
            key={column.key}
            role="img"
            aria-label={title}
            title={title}
            data-level={level}
            className="h-6 min-w-0 rounded-[3px] sm:h-7"
            style={{ background: heatmapColor(level, color) }}
          />
        );
      })}
    </>
  );
}

/**
 * The same figures as a table: one row per heatmap row, one column per
 * heatmap column, for `<ChartCard>`'s table view.
 */
export function HeatmapTable({
  caption,
  rowHeading,
  rows,
  columns,
  value,
  format,
  className,
}: HeatmapData & {
  caption: ReactNode;
  /** The first column's header, naming what the rows are: "Day". */
  rowHeading: ReactNode;
  className?: string;
}) {
  const say = format ?? ((v: number) => String(v));
  return (
    <ChartTable
      className={className}
      caption={caption}
      columns={[
        { key: "__row", label: rowHeading },
        ...columns.map((column) => ({
          key: column.key,
          label: column.label,
          numeric: true,
        })),
      ]}
      rows={rows.map((row) => ({
        __row: row.label,
        ...Object.fromEntries(
          columns.map((column) => [
            column.key,
            say(value(row.key, column.key) ?? 0),
          ]),
        ),
      }))}
    />
  );
}
