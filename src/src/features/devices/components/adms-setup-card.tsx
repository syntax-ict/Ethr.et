"use client";

import { AlertTriangle } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import type { Device } from "@/features/devices/api";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";

/**
 * What to enter on a ZKTeco terminal so it reaches ETHR over ADMS, and why it
 * might not be getting through yet.
 *
 * ETHR admits an ADMS device only from an address on its allowlist. The
 * serial number alone proves nothing, because it is printed on the case. So
 * the most useful thing this card can say is the address of the last refused
 * attempt: that is usually the office address the allowlist is missing.
 */
export function AdmsSetupCard({ device }: { device: Device }) {
  const { t } = useT();
  const { timeAgo } = useDateFormatters();
  const adms = device.adms;
  if (!adms) return null;

  const allowlist = device.webhook_ip_allowlist ?? [];
  // Monospace for what is typed on the device; plain text for advice.
  const rows: [string, string, boolean][] = [
    [t("adms_setup.server_address"), adms.server, true],
    [t("adms_setup.server_port"), t("adms_setup.server_port_value"), false],
    [t("adms_setup.domain_name"), t("adms_setup.on"), false],
    [t("adms_setup.serial"), device.serial_number ?? "—", true],
  ];

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="text-sm">{t("adms_setup.title")}</CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        <p className="text-sm text-muted-foreground">{t("adms_setup.where")}</p>
        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[max-content_1fr]">
          {rows.map(([label, value, typed]) => (
            <div key={label} className="contents">
              <dt className="text-muted-foreground">{label}</dt>
              <dd className={typed ? "font-mono break-all" : ""}>{value}</dd>
            </div>
          ))}
        </dl>

        {allowlist.length === 0 && (
          <div className="flex gap-2 rounded-lg border border-warning-edge bg-warning-soft p-3 text-sm text-warning-on-soft">
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
            <p>{t("adms_setup.no_allowlist")}</p>
          </div>
        )}

        {adms.last_rejected && (
          <div className="flex gap-2 rounded-lg border p-3 text-sm">
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-status-warning" />
            <p>
              {t("adms_setup.last_rejected", undefined, {
                ip: adms.last_rejected.ip ?? "?",
                when: timeAgo(adms.last_rejected.at),
              })}
            </p>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
