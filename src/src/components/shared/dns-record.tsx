/**
 * One DNS record an organisation is asked to publish: the type, then the
 * name and value as two copyable lines. Used wherever a custom domain is
 * verified — the platform console and the organisation's own settings.
 */
export function DnsRecord({
  type,
  name,
  value,
  nameLabel,
  valueLabel,
}: {
  type: string;
  name: string;
  value: string;
  nameLabel: string;
  valueLabel: string;
}) {
  return (
    <div className="rounded-md border p-3">
      <dt className="text-xs font-semibold text-foreground">{type}</dt>
      <dd className="mt-2 grid gap-1 text-xs sm:grid-cols-[6rem_1fr]">
        <span className="text-muted-foreground">{nameLabel}</span>
        <code className="break-all font-mono">{name}</code>
        <span className="text-muted-foreground">{valueLabel}</span>
        <code className="break-all font-mono">{value}</code>
      </dd>
    </div>
  );
}
