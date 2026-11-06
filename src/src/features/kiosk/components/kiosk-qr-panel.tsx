"use client";

import { useEffect, useState } from "react";
import { QRCodeSVG } from "qrcode.react";
import { Loader2, QrCode } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { useT } from "@/lib/i18n/useT";
import { fetchKioskQr } from "../api";

/** Ask for the next code this long before the shown one runs out. */
const RENEW_MARGIN_MS = 10_000;
/** Never ask more often than this, whatever the code's lifetime says. */
const MIN_WAIT_MS = 5_000;
/** After a failed request, ask again after this long. */
const RETRY_MS = 10_000;

interface ShownCode {
  token: string;
  /** On this terminal's clock, from when the answer arrived. */
  expiresAt: number;
}

/**
 * The code a phone scans to punch in at this kiosk. It is the same code HR
 * prints, issued by the server for seconds at a time and good for one scan.
 * Replaced before it runs out, and blanked if the terminal cannot reach the
 * server in time, so a stale one is never left on show.
 *
 * Renders nothing where the organisation or branch has QR switched off.
 */
export function KioskQrPanel({
  token,
  paused,
}: {
  token: string;
  paused: boolean;
}) {
  const { t } = useT();
  const [code, setCode] = useState<ShownCode | null>(null);
  const [failed, setFailed] = useState(false);
  const [disabled, setDisabled] = useState(false);

  useEffect(() => {
    if (paused || disabled) return;
    let stopped = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let expiry: ReturnType<typeof setTimeout> | undefined;

    async function renew() {
      try {
        const next = await fetchKioskQr(token);
        if (stopped) return;
        const lifetimeMs = next.ttl_seconds * 1000;
        const expiresAt = Date.now() + lifetimeMs;
        setCode({ token: next.token, expiresAt });
        setFailed(false);
        clearTimeout(expiry);
        expiry = setTimeout(() => setCode(null), lifetimeMs);
        timer = setTimeout(
          renew,
          Math.max(MIN_WAIT_MS, lifetimeMs - RENEW_MARGIN_MS),
        );
      } catch (err) {
        if (stopped) return;
        const status = (err as { response?: { status?: number } })?.response
          ?.status;
        // QR switched off for this branch, or the feature is: stay hidden.
        if (status === 403 || status === 404) {
          setDisabled(true);
          return;
        }
        setFailed(true);
        timer = setTimeout(renew, RETRY_MS);
      }
    }

    void renew();
    return () => {
      stopped = true;
      clearTimeout(timer);
      clearTimeout(expiry);
      setCode(null);
    };
  }, [token, paused, disabled]);

  if (disabled) return null;

  return (
    <Card className="w-full max-w-xs shrink-0">
      <CardContent className="space-y-4 p-6 text-center">
        <p className="flex items-center justify-center gap-2 text-sm font-semibold">
          <QrCode className="h-4 w-4" aria-hidden="true" />
          {t("kiosk_page.qr_title")}
        </p>
        <div
          className="mx-auto flex aspect-square w-full max-w-[220px] items-center justify-center rounded-xl border bg-white p-3"
          role="img"
          aria-label={t("kiosk_page.qr_title")}
        >
          {code ? (
            <QRCodeSVG
              value={code.token}
              size={196}
              level="M"
              className="h-full w-full"
            />
          ) : failed ? (
            <p className="px-2 text-xs text-muted-foreground">
              {t("kiosk_page.qr_unavailable")}
            </p>
          ) : (
            <Loader2
              className="h-6 w-6 animate-spin text-muted-foreground"
              aria-label={t("kiosk_page.qr_refreshing")}
            />
          )}
        </div>
        <p className="text-xs text-muted-foreground">
          {t("kiosk_page.qr_hint")}
        </p>
      </CardContent>
    </Card>
  );
}
