export function formatETB(cents: number): string {
  return (
    new Intl.NumberFormat("en-ET", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(cents / 100) + " ETB"
  );
}

const compactETB = new Intl.NumberFormat("en-ET", {
  notation: "compact",
  maximumFractionDigits: 2,
});

/**
 * A headline birr amount for a KPI tile: `2.59M ETB`. Seven-figure payroll
 * totals with cents do not fit a tile; wherever the exact figure matters (a
 * payslip, a tooltip, a table) use `formatETB`.
 */
export function formatETBCompact(cents: number): string {
  return `${compactETB.format(cents / 100)} ETB`;
}

export function centsToETB(cents: number): number {
  return cents / 100;
}

export function etbToCents(etb: number): number {
  return Math.round(etb * 100);
}
