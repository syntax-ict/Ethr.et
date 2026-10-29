"use client";

import { Suspense, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { Building2, CheckCircle2, Inbox, Loader2 } from "lucide-react";
import { toast } from "sonner";
import { useT } from "@/lib/i18n/useT";
import { apiErrorMessage } from "@/lib/api/error-message";
import { PaginationControls } from "@/components/shared/pagination-controls";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  SUPPORT_STATUSES,
  type SupportRequest,
  useConsoleResolveSupportRequest,
  useConsoleSupportReply,
  useConsoleSupportRequest,
  useConsoleSupportRequests,
} from "@/features/support/api";
import {
  SupportContextPanel,
  SupportStatusBadge,
  SupportThread,
} from "@/features/support/components";
import { RequestList, StatusFilter } from "@/features/support/list";

/**
 * ETHR's support inbox: every request an organisation passed on, with the
 * organisation, the person, what their screen knew and the note their
 * colleague wrote. Open requests wait for ETHR; answered ones wait for the
 * person who asked. Notifications link here with `?request=`.
 */
export default function ConsoleSupportPage() {
  return (
    <Suspense fallback={<Skeleton className="h-64" />}>
      <ConsoleSupportInner />
    </Suspense>
  );
}

function ConsoleSupportInner() {
  const { t } = useT();
  const [status, setStatus] = useState<string>("open");
  const [page, setPage] = useState(1);
  const query = useConsoleSupportRequests(status, page);
  const counts = query.data?.counts ?? {};
  const searchParams = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();
  const [picked, setPicked] = useState<string | null>(null);

  // A notification links here with ?request=; a click on the list wins.
  const linked = searchParams.get("request");
  const open = picked ?? linked;
  const setOpen = setPicked;

  function close() {
    setPicked(null);
    if (linked) router.replace(pathname);
  }

  const filters = [
    ...SUPPORT_STATUSES.map((s) => ({
      value: s as string,
      label: t(`support.console_status.${s}`, s),
      count: counts[s],
    })),
    { value: "all", label: t("support.filter_all", "All"), count: undefined },
  ];

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader className="flex-row flex-wrap items-center justify-between gap-3 space-y-0">
          <CardTitle className="flex items-center gap-2 text-base">
            <Inbox className="h-4 w-4 text-primary" aria-hidden="true" />
            {t("support.console_title", "Requests passed to ETHR")}
          </CardTitle>
          <StatusFilter
            filters={filters}
            value={status}
            onChange={(v) => {
              setStatus(v);
              setPage(1);
            }}
          />
        </CardHeader>
        <CardContent>
          <RequestList
            query={query}
            onOpen={setOpen}
            showRequester
            showOrganisation
            empty={
              <p className="py-6 text-center text-sm text-muted-foreground">
                {status === "open"
                  ? t(
                      "support.console_none_open",
                      "Nothing is waiting for ETHR.",
                    )
                  : t("support.none_here", "No requests here.")}
              </p>
            }
          />
          <PaginationControls meta={query.data?.meta} onPageChange={setPage} />
        </CardContent>
      </Card>

      <ConsoleRequestDialog publicId={open} onClose={close} />
    </div>
  );
}

function ConsoleRequestDialog({
  publicId,
  onClose,
}: {
  publicId: string | null;
  onClose: () => void;
}) {
  const { t } = useT();
  const { data, isError } = useConsoleSupportRequest(publicId);

  return (
    <Dialog open={publicId !== null} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
        {isError ? (
          <>
            <DialogTitle>
              {t("support.not_found", "Request not found")}
            </DialogTitle>
            <DialogDescription>
              {t(
                "support.console_not_found_desc",
                "Only requests an organisation passed to ETHR are here.",
              )}
            </DialogDescription>
          </>
        ) : !data ? (
          <>
            <DialogTitle className="sr-only">
              {t("common.loading", "Loading")}
            </DialogTitle>
            <Skeleton className="h-48" />
          </>
        ) : (
          <ConsoleRequestDetail request={data} />
        )}
      </DialogContent>
    </Dialog>
  );
}

function ConsoleRequestDetail({ request }: { request: SupportRequest }) {
  const { t } = useT();
  const reply = useConsoleSupportReply();
  const resolve = useConsoleResolveSupportRequest();

  function doResolve() {
    resolve.mutate(request.public_id, {
      onSuccess: () =>
        toast.success(t("support.resolved_toast", "Marked resolved")),
      onError: (err) =>
        toast.error(
          apiErrorMessage(err, t("support.send_failed", "Could not send")),
        ),
    });
  }

  return (
    <div className="space-y-5">
      <DialogHeader>
        <DialogTitle className="pr-6">
          {t(`support.category.${request.category}`, request.category)}
        </DialogTitle>
        <DialogDescription asChild>
          <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
            <SupportStatusBadge status={request.status} />
            {request.organisation && (
              <Link
                href={`/admin/tenants/${request.organisation.public_id}`}
                className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
              >
                <Building2 className="h-3.5 w-3.5" aria-hidden="true" />
                {request.organisation.name}
              </Link>
            )}
          </div>
        </DialogDescription>
      </DialogHeader>

      <div className="grid gap-4 md:grid-cols-[1fr_16rem]">
        <div className="space-y-4">
          {request.escalated_by_name && (
            <p className="rounded-lg border border-info/30 bg-info-soft/40 p-3 text-xs">
              {t("support.passed_by", "Passed to ETHR support by :name.", {
                name: request.escalated_by_name,
              })}
              {request.escalation_note && (
                <span className="mt-1 block whitespace-pre-line">
                  {request.escalation_note}
                </span>
              )}
            </p>
          )}
          <SupportThread
            request={request}
            viewer="ethr"
            sending={reply.isPending}
            onReply={(body) =>
              reply.mutateAsync({ publicId: request.public_id, body })
            }
          />
        </div>

        <aside className="space-y-3">
          {request.requester && (
            <section className="space-y-1 rounded-lg border p-3 text-xs">
              <p className="font-semibold uppercase tracking-wide text-muted-foreground">
                {t("support.asked_by_title", "Asked by")}
              </p>
              <p className="text-sm font-medium">{request.requester.name}</p>
              {request.requester.email && (
                <p className="break-all text-muted-foreground">
                  {request.requester.email}
                </p>
              )}
              {request.requester.role && (
                <p className="text-muted-foreground">
                  {t(`role.${request.requester.role}`, request.requester.role)}
                </p>
              )}
            </section>
          )}
          <SupportContextPanel context={request.context} />
          {request.status !== "resolved" && (
            <Button
              size="sm"
              variant="outline"
              className="w-full"
              onClick={doResolve}
              disabled={resolve.isPending}
            >
              {resolve.isPending ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : (
                <CheckCircle2 className="mr-2 h-4 w-4" />
              )}
              {t("support.mark_resolved", "Mark resolved")}
            </Button>
          )}
        </aside>
      </div>
    </div>
  );
}
