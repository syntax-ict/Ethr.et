export function formatETB(cents: number): string {
  return (
    new Intl.NumberFormat('en-ET', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(cents / 100) + ' ETB'
  );
}

export function centsToETB(cents: number): number {
  return cents / 100;
}

export function etbToCents(etb: number): number {
  return Math.round(etb * 100);
}
