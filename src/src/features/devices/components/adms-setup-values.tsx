"use client";

import { CopyValueButton } from "@/features/devices/components/copy-value-button";
import { useT } from "@/lib/i18n/useT";

/**
 * What is typed on a ZKTeco terminal under COMM, then Cloud Server Setting.
 * One list for the setup card on the device page and for the dialog shown
 * after registering, so the two cannot say different things.
 *
 * With `copyable`, each value typed on the terminal has a copy button.
 */
export function AdmsSetupValues({
  server,
  serial,
  copyable = false,
}: {
  server: string;
  serial: string | null | undefined;
  copyable?: boolean;
}) {
  const { t } = useT();
  // Monospace for what is typed on the device; plain text for advice.
  const rows: [string, string, boolean][] = [
    [t("adms_setup.server_address"), server, true],
    [t("adms_setup.server_port"), t("adms_setup.server_port_value"), false],
    [t("adms_setup.domain_name"), t("adms_setup.on"), false],
    [t("adms_setup.serial"), serial ?? "—", true],
  ];

  return (
    <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[max-content_1fr]">
      {rows.map(([label, value, typed]) => (
        <div key={label} className="contents">
          <dt className="text-muted-foreground">{label}</dt>
          <dd
            className={
              typed
                ? "flex items-start gap-2 font-mono break-all"
                : undefined
            }
          >
            {value}
            {copyable && typed && value !== "—" && (
              <CopyValueButton value={value} label={label} />
            )}
          </dd>
        </div>
      ))}
    </dl>
  );
}
