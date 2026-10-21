"use client";

import { useState } from "react";
import { Archive, Download, Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
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
import { formatDateTime } from "@/lib/utils/date";

type BadgeVariant = "info" | "success" | "warning" | "neutral";

const STATUS_VARIANT: Record<string, BadgeVariant> = {
  queued: "info",
  running: "info",
  ready: "success",
  failed: "warning",
  expired: "neutral",
};

function formatSize(bytes: number | null): string {
  if (bytes === null) return "";
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export default function DataExportPage() {
  const { t } = useT();
  const exports = useDataExports();
  const request = useRequestDataExport();
  const [downloading, setDownloading] = useState<string | null>(null);

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
            <Button onClick={requestExport} disabled={request.isPending}>
              {request.isPending ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : (
                <Archive className="mr-2 h-4 w-4" />
              )}
              {t("data_export.request", "Request export")}
            </Button>
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
                { days: exports.data?.expires_days ?? 7 },
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
              empty={
                <p className="py-6 text-center text-sm text-muted-foreground">
                  {t("data_export.none", "No exports yet.")}
                </p>
              }
            >
              {(list) => (
                <ul className="divide-y divide-border">
                  {list.data.map((exp) => (
                    <li
                      key={exp.public_id}
                      className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                      <div className="space-y-1">
                        <div className="flex items-center gap-2">
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
                        {exp.status === "ready" && (
                          <p className="text-xs text-muted-foreground">
                            {t(
                              "data_export.ready_detail",
                              ":size, :files files, available until :date",
                              {
                                size: formatSize(exp.size_bytes),
                                files: exp.file_count ?? 0,
                                date: exp.expires_at
                                  ? formatDateTime(exp.expires_at)
                                  : "",
                              },
                            )}
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
                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                          ) : (
                            <Download className="mr-2 h-4 w-4" />
                          )}
                          {t("data_export.download", "Download")}
                        </Button>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </QueryBoundary>
          </CardContent>
        </Card>
      </div>
    </RoleGate>
  );
}
