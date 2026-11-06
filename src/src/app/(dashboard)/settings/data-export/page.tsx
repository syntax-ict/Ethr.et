"use client";

import { useEffect, useState } from "react";
import { Archive, Download, Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageActions } from "@/components/shared/page-actions";
import { RoleGate } from "@/components/shared/role-gate";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  downloadDataExport,
  useDataExports,
  useRequestDataExport,
  type DataExport,
} from "@/features/data-export/api";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";

type BadgeVariant = "info" | "success" | "warning" | "neutral" | "destructive";

const STATUS_VARIANT: Record<string, BadgeVariant> = {
  queued: "info",
  running: "info",
  ready: "success",
  failed: "destructive",
  expired: "neutral",
};

// While an export is being built the list is polled every 10 seconds, so the
// row turns to "Ready" on its own. The hook polls more slowly on its own; this
// page is where someone sits and waits for it.
const ACTIVE_POLL_MS = 10_000;

function isInProgress(exp: DataExport): boolean {
  return exp.status === "queued" || exp.status === "running";
}

function formatSize(bytes: number | null): string {
  if (bytes === null) return "";
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export default function DataExportPage() {
  const { t } = useT();
  const { formatDateTime } = useDateFormatters();
  const exports = useDataExports();
  const request = useRequestDataExport();
  const [downloading, setDownloading] = useState<string | null>(null);

  const inProgress = exports.data?.data.some(isInProgress) ?? false;
  const expiresDays = exports.data?.expires_days ?? 7;
  const { refetch } = exports;

  useEffect(() => {
    if (!inProgress) return;
    const timer = setInterval(() => void refetch(), ACTIVE_POLL_MS);
    return () => clearInterval(timer);
  }, [inProgress, refetch]);

  const statusLabel = (status: string): string => {
    switch (status) {
      case "queued":
        return t("data_export.status_queued", "Waiting");
      case "running":
        return t("data_export.status_running", "Preparing");
      case "ready":
        return t("data_export.status_ready", "Ready");
      case "failed":
        return t("data_export.status_failed", "Failed");
      case "expired":
        return t("data_export.status_expired", "Expired");
      default:
        return status;
    }
  };

  /** Size, file count and expiry, whichever of them the server sent. */
  function readyDetail(exp: DataExport): string {
    const parts: string[] = [];
    const size = formatSize(exp.size_bytes);
    if (size) parts.push(size);
    if (exp.file_count !== null) {
      parts.push(
        exp.file_count === 1
          ? t("data_export.files_count_one", "1 file")
          : t("data_export.files_count", ":count files", {
              count: exp.file_count,
            }),
      );
    }
    if (exp.expires_at) {
      parts.push(
        t("data_export.available_until", "available until :date", {
          date: formatDateTime(exp.expires_at),
        }),
      );
    }
    return parts.join(", ");
  }

  function detail(exp: DataExport): string | null {
    switch (exp.status) {
      case "ready":
        return readyDetail(exp) || null;
      case "failed":
        return t(
          "data_export.failed_detail",
          "Something went wrong while preparing this export. Request a new one.",
        );
      case "expired":
        return t(
          "data_export.expired_detail",
          "This export was deleted after :days days.",
          { days: expiresDays },
        );
      default:
        return null;
    }
  }

  async function download(exp: DataExport) {
    setDownloading(exp.public_id);
    try {
      await downloadDataExport(exp);
    } catch (err) {
      toast.error(
        apiErrorMessage(
          err,
          t(
            "data_export.download_failed",
            "The export could not be downloaded.",
          ),
        ),
      );
    } finally {
      setDownloading(null);
    }
  }

  function requestExport() {
    request.mutate(undefined, {
      onSuccess: () =>
        toast.success(
          t(
            "data_export.requested",
            "Export requested. You will be notified when it is ready.",
          ),
        ),
      onError: (err) =>
        toast.error(
          apiErrorMessage(
            err,
            t(
              "data_export.request_failed",
              "The export could not be requested.",
            ),
          ),
        ),
    });
  }

  return (
    <RoleGate anyPermission={["manageSettings"]}>
      <div className="space-y-6">
        <PageActions
          actions={
            <div className="flex flex-col items-end gap-1">
              <Button
                onClick={requestExport}
                disabled={request.isPending || inProgress}
                aria-describedby={
                  inProgress ? "data-export-already-running" : undefined
                }
              >
                {request.isPending ? (
                  <Loader2
                    className="mr-2 h-4 w-4 animate-spin"
                    aria-hidden="true"
                  />
                ) : (
                  <Archive className="mr-2 h-4 w-4" aria-hidden="true" />
                )}
                {t("data_export.request", "Request export")}
              </Button>
              {inProgress && (
                <p
                  id="data-export-already-running"
                  className="text-caption text-muted-foreground"
                >
                  {t(
                    "data_export.already_running",
                    "An export is already being prepared.",
                  )}
                </p>
              )}
            </div>
          }
        />

        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              {t("data_export.whats_inside", "What the export contains")}
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-2 text-sm text-muted-foreground">
            <p>
              {t(
                "data_export.contents",
                "One archive with every record your organisation holds in ETHR, deleted records included, one file per table, together with the employee documents you uploaded and a README that explains the files.",
              )}
            </p>
            <p>
              {t(
                "data_export.excluded",
                "Passwords, two-factor secrets, keys, encrypted identity numbers (TIN, national ID, bank account numbers) and attendance photos are left out.",
              )}
            </p>
            <p>
              {t(
                "data_export.retention",
                "An export is prepared in the background and kept for :days days, then deleted. Each request and each download is recorded in the audit log.",
                { days: expiresDays },
              )}
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              {t("data_export.recent", "Recent exports")}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <QueryBoundary
              query={exports}
              loading={
                <div className="space-y-3 py-3">
                  {Array.from({ length: 3 }).map((_, i) => (
                    <Skeleton key={i} className="h-10 w-full" />
                  ))}
                </div>
              }
              // The list sits inside `{ data, expires_days }`; the default
              // check saw a non-empty object and never showed the empty state.
              isEmpty={(r) => r.data.length === 0}
              empty={
                <p className="py-6 text-center text-sm text-muted-foreground">
                  {t("data_export.none", "No exports yet.")}
                </p>
              }
            >
              {(list) => (
                <ul className="divide-y divide-border">
                  {list.data.map((exp) => {
                    const line = detail(exp);
                    return (
                      <li
                        key={exp.public_id}
                        className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between"
                      >
                        <div className="space-y-1">
                          <div className="flex flex-wrap items-center gap-2">
                            <Badge
                              variant={STATUS_VARIANT[exp.status] ?? "neutral"}
                            >
                              {statusLabel(exp.status)}
                            </Badge>
                            {exp.created_at && (
                              <span className="text-sm text-foreground">
                                {formatDateTime(exp.created_at)}
                              </span>
                            )}
                          </div>
                          {line && (
                            <p className="text-caption text-muted-foreground">
                              {line}
                            </p>
                          )}
                        </div>
                        {exp.status === "ready" && (
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() => download(exp)}
                            disabled={downloading === exp.public_id}
                          >
                            {downloading === exp.public_id ? (
                              <Loader2
                                className="mr-2 h-4 w-4 animate-spin"
                                aria-hidden="true"
                              />
                            ) : (
                              <Download
                                className="mr-2 h-4 w-4"
                                aria-hidden="true"
                              />
                            )}
                            {t("data_export.download", "Download")}
                          </Button>
                        )}
                      </li>
                    );
                  })}
                </ul>
              )}
            </QueryBoundary>
          </CardContent>
        </Card>
      </div>
    </RoleGate>
  );
}
