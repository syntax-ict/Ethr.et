import { cn } from "@/lib/utils";
import { formatETB, formatETBCompact } from "@/lib/utils/currency";

interface CurrencyDisplayProps {
  cents: number;
  className?: string;
}

/**
 * An amount in birr: `1,793,658.90 ETB`. Never broken across lines — in a
 * narrow table column the number and "ETB" wrapped apart, so every amount
 * in a payroll run took two lines.
 */
export function CurrencyDisplay({ cents, className }: CurrencyDisplayProps) {
  const formatted = new Intl.NumberFormat("en-ET", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(cents / 100);

  return (
    <span className={cn("whitespace-nowrap tabular-nums", className)}>
      {formatted} ETB
    </span>
  );
}

/**
 * An amount fitted to a tile, `2.59M ETB`, with the exact figure as its
 * tooltip so nothing is lost by fitting it.
 */
export function CompactCurrency({ cents }: { cents: number }) {
  return <span title={formatETB(cents)}>{formatETBCompact(cents)}</span>;
}
