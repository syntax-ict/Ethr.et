"use client";

import { useT } from "@/lib/i18n/useT";

/**
 * The four values a Hikvision terminal's HTTP listening screen asks for,
 * read from the address. With `masked` the address carries only the start of
 * the token, which must never be typed in: the path is shown without its
 * query and the box says it is a description, not something to enter.
 */
export function PushSetup({
  url,
  masked = false,
}: {
  url: string;
  masked?: boolean;
}) {
  const { t } = useT();
  let parts: { label: string; value: string }[] = [];
  try {
    const parsed = new URL(url);
    const https = parsed.protocol === "https:";
    parts = [
      {
        label: t("device_detail_page.push_host", "Server address"),
        value: parsed.hostname,
      },
      {
        label: t("device_detail_page.push_port", "Port"),
        value: parsed.port || (https ? "443" : "80"),
      },
      {
        label: t("device_detail_page.push_protocol", "Protocol"),
        value: https ? "HTTPS" : "HTTP",
      },
      {
        label: t("device_detail_page.push_path", "Path (URL)"),
        value: masked ? parsed.pathname : parsed.pathname + parsed.search,
      },
    ];
  } catch {
    return null;
  }

  return (
    <div className="space-y-2 rounded-lg border p-3">
      <p className="text-sm font-medium">
        {masked
          ? t("device_token.masked_setup_title")
          : t(
              "device_detail_page.push_setup_title",
              "Enter these on the terminal",
            )}
      </p>
      {!masked && (
        <p className="text-xs text-muted-foreground">
          {t(
            "device_detail_page.push_setup_intro",
            "On the terminal's own web page, open Network, then HTTP listening (some firmware calls it Event alarm or Alarm host), and enter the four values below. The terminal needs internet access and must support HTTPS. ETHR shows it as online once the first punch arrives.",
          )}
        </p>
      )}
      <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
        {parts.map((part) => (
          <div key={part.label} className="contents">
            <dt className="text-muted-foreground">{part.label}</dt>
            <dd className="font-mono break-all">{part.value}</dd>
          </div>
        ))}
      </dl>
      {masked && (
        <p className="text-xs text-muted-foreground">
          {t("device_token.path_hidden")}
        </p>
      )}
    </div>
  );
}
