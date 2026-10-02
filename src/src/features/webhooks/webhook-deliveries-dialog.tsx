"use client";

import { History } from "lucide-react";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { useWebhookDeliveries } from "@/features/webhooks/api";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { Badge } from "@/components/ui/badge";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Skeleton } from "@/components/ui/skeleton";
import { EmptyState } from "@/components/shared/empty-state";
import { SimpleTable } from "@/components/shared/simple-table";

/**
 * Delivery history for one webhook.
 *
 * `GET /webhooks/{webhook}/deliveries` and the whole `WebhookDelivery` model
 * existed with no consumer, so a tenant whose endpoint was failing had no way
 * to see *what* was attempted or what came back — only a failure counter on the
 * webhook row. The endpoint returns the most recent 50 attempts.
 */

export function WebhookDeliveriesDialog({
  webhookId,
  webhookUrl,
  open,
  onClose,
}: {
  webhookId: string | null;
  webhookUrl?: string;
  open: boolean;
  onClose: () => void;
}) {
  const { t } = useT();
  const { formatDateTime } = useDateFormatters();

  const deliveriesQuery = useWebhookDeliveries(webhookId, { enabled: open });

  return (
    <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>
            {t("webhooks_page.deliveries_title", "Delivery history")}
          </DialogTitle>
          <DialogDescription>
            {webhookUrl ??
              t(
                "webhooks_page.deliveries_desc",
                "The 50 most recent delivery attempts",
              )}
          </DialogDescription>
        </DialogHeader>

        <QueryBoundary
          query={deliveriesQuery}
          loading={
            <div className="space-y-2">
              {Array.from({ length: 4 }).map((_, i) => (
                <Skeleton key={i} className="h-10 w-full" />
              ))}
            </div>
          }
          empty={
            <EmptyState
              icon={History}
              title={t("webhooks_page.no_deliveries", "No deliveries yet")}
              description={t(
                "webhooks_page.no_deliveries_desc",
                "Nothing has been sent to this endpoint. Use Test to send a sample event.",
              )}
            />
          }
        >
          {(deliveries) => (
            <div className="max-h-[60vh] overflow-y-auto">
              <SimpleTable
                caption={t(
                  "webhooks_page.deliveries_title",
                  "Delivery history",
                )}
                headers={[
                  t("webhooks_page.event", "Event"),
                  t("common.status", "Status"),
                  t("webhooks_page.attempt", "Attempt"),
                  t("webhooks_page.delivered_at", "Delivered"),
                ]}
                align={["left", "left", "right", "left"]}
                colClassName={["", "", "hidden sm:table-cell", ""]}
                rows={deliveries.map((d, i) => ({
                  key: `${d.event}-${d.created_at ?? ""}-${i}`,
                  cells: [
                    <span key="e" className="font-mono text-xs">
                      {d.event}
                    </span>,
                    <StatusBadge key="s" status={d.response_status} />,
                    <span key="a" className="tabular-nums">
                      {d.attempt}
                    </span>,
                    <span key="d" className="text-muted-foreground">
                      {formatWhen(
                        d.delivered_at ?? d.created_at,
                        formatDateTime,
                      )}
                    </span>,
                  ],
                }))}
              />
            </div>
          )}
        </QueryBoundary>
      </DialogContent>
    </Dialog>
  );
}

/**
 * A null status means the attempt never got a response at all (connection
 * refused, DNS failure, timeout) — which is a different thing from a 500, and
 * the most common reason a tenant's endpoint looks "broken".
 */
function StatusBadge({ status }: { status: number | null }) {
  const { t } = useT();

  if (status === null) {
    return (
      <Badge variant="destructive" className="text-[10px]">
        {t("webhooks_page.no_response", "No response")}
      </Badge>
    );
  }

  const ok = status >= 200 && status < 300;

  return (
    <Badge
      variant={ok ? "success" : "warning"}
      className="text-[10px] tabular-nums"
    >
      {status}
    </Badge>
  );
}

/**
 * A delivery attempt is an instant, so it renders in the tenant's zone. The
 * formatter is passed in rather than read here: this is a module-level helper,
 * and reaching for a hook inside one would either not work or quietly turn it
 * into a component.
 */
function formatWhen(
  value: string | null,
  formatDateTime: (value: string) => string,
): string {
  if (!value) return "—";

  return Number.isNaN(new Date(value).getTime()) ? "—" : formatDateTime(value);
}
