interface CurrencyDisplayProps {
  cents: number;
  className?: string;
}

export function CurrencyDisplay({ cents, className }: CurrencyDisplayProps) {
  const formatted = new Intl.NumberFormat("en-ET", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(cents / 100);

  return <span className={className}>{formatted} ETB</span>;
}
