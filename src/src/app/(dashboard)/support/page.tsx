"use client";

import { Suspense, useState } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { CheckCircle2, Inbox, CircleHelp, Loader2, Send } from "lucide-react";
import { toast } from "sonner";
import { useT } from "@/lib/i18n/useT";
import { useQueryTab } from "@/lib/hooks/useQueryTab";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { apiErrorMessage } from "@/lib/api/error-message";
import { PageActions } from "@/components/shared/page-actions";
import { EmptyState } from "@/components/shared/empty-state";
import { PaginationControls } from "@/components/shared/pagination-controls";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
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
  useEscalateSupportRequest,
  useMySupportRequests,
  useResolveSupportRequest,
  useSupportReply,
  useSupportRequest,
  useSupportRequests,
} from "@/features/support/api";
import {
  openGetHelp,
  SupportContextPanel,
  SupportHandlerLabel,
  SupportStatusBadge,
  SupportThread,
} from "@/features/support/components";
import { RequestList, StatusFilter } from "@/features/support/list";

const TABS = ["mine", "organisation"] as const;

/**
 * Help requests: the person's own, and, for the organisation's support
 * people, everyone's. A notification links here with `?request=` to open
 * one directly.
 */
export default function SupportPage() {
  return (
    <Suspense fallback={<Skeleton className="h-64" />}>
      <SupportPageInner />
    </Suspense>
  );
}

function SupportPageInner() {
  const { t } = useT();
  const { can } = usePermissions();
  const [tab, setTab] = useQueryTab(TABS, "mine");
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

  return (
    <div className="space-y-6">
      <PageActions
        actions={
          <Button onClick={openGetHelp}>
            <CircleHelp className="mr-2 h-4 w-4" />
            {t("support.get_help", "Get help")}
          </Button>
        }
      />

      {can.manageSupport ? (
        <Tabs value={tab} onValueChange={setTab}>
          <TabsList variant="underline">
            <TabsTrigger value="mine">
              {t("support.tab_mine", "My requests")}
            </TabsTrigger>
            <TabsTrigger value="organisation">
              {t("support.tab_organisation", "Organisation requests")}
            </TabsTrigger>
          </TabsList>
          <TabsContent value="mine" className="mt-6">
            <MyRequests onOpen={setOpen} />
          </TabsContent>
          <TabsContent value="organisation" className="mt-6">
            <OrganisationRequests onOpen={setOpen} />
          </TabsContent>
        </Tabs>
      ) : (
        <MyRequests onOpen={setOpen} />
      )}

      <RequestDialog publicId={open} onClose={close} />
    </div>
  );
}

function MyRequests({ onOpen }: { onOpen: (id: string) => void }) {
  const { t } = useT();
  const [page, setPage] = useState(1);
  const query = useMySupportRequests(page);

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <CircleHelp className="h-4 w-4 text-primary" aria-hidden="true" />
          {t("support.tab_mine", "My requests")}
        </CardTitle>
      </CardHeader>
      <CardContent>
        <RequestList
          query={query}
          onOpen={onOpen}
          empty={
            <EmptyState
              icon={CircleHelp}
              title={t("support.none_mine", "You have not asked for help yet")}
              description={t(
                "support.none_mine_desc",
                "When something does not work, ask here. The answer comes back to this page and to your email.",
              )}
              action={
                <Button onClick={openGetHelp}>
                  {t("support.get_help", "Get help")}
                </Button>
              }
            />
          }
        />
        <PaginationControls meta={query.data?.meta} onPageChange={setPage} />
      </CardContent>
    </Card>
  );
}

function OrganisationRequests({ onOpen }: { onOpen: (id: string) => void }) {
  const { t } = useT();
  const [status, setStatus] = useState<string>("open");
  const [page, setPage] = useState(1);
  const query = useSupportRequests(status, page);
  const counts = query.data?.counts ?? {};

  const filters = [
    ...SUPPORT_STATUSES.map((s) => ({
      value: s as string,
      label: t(`support.status.${s}`, s),
      count: counts[s],
    })),
    { value: "all", label: t("support.filter_all", "All"), count: undefined },
  ];

  return (
    <Card>
      <CardHeader className="flex-row flex-wrap items-center justify-between gap-3 space-y-0">
        <CardTitle className="flex items-center gap-2 text-base">
          <Inbox className="h-4 w-4 text-primary" aria-hidden="true" />
          {t("support.tab_organisation", "Organisation requests")}
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
          onOpen={onOpen}
          showRequester
          empty={
            <p className="py-6 text-center text-sm text-muted-foreground">
              {t("support.none_here", "No requests here.")}
            </p>
          }
        />
        <PaginationControls meta={query.data?.meta} onPageChange={setPage} />
      </CardContent>
    </Card>
  );
}

function RequestDialog({
  publicId,
  onClose,
}: {
  publicId: string | null;
  onClose: () => void;
}) {
  const { t } = useT();
  const { data, isError } = useSupportRequest(publicId);

  return (
    <Dialog open={publicId !== null} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
        {isError ? (
          <>
            <DialogTitle>
              {t("support.not_found", "Request not found")}
            </DialogTitle>
            <DialogDescription>
              {t(
                "support.not_found_desc",
                "It may belong to someone else, or the link is wrong.",
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
          <RequestDetail request={data} />
        )}
      </DialogContent>
    </Dialog>
  );
}

function RequestDetail({ request }: { request: SupportRequest }) {
  const { t } = useT();
  const reply = useSupportReply();
  const resolve = useResolveSupportRequest();
  const escalate = useEscalateSupportRequest();
  const [passing, setPassing] = useState(false);
  const [note, setNote] = useState("");

  // The person who asked reads the request as theirs even when they also
  // hold support.manage: the server says which.
  const viewer = request.viewer === "requester" ? "requester" : "organisation";
  const staffView = viewer === "organisation";

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

  function doEscalate() {
    escalate.mutate(
      { publicId: request.public_id, note: note.trim() || null },
      {
        onSuccess: () => {
          setPassing(false);
          setNote("");
          toast.success(t("support.passed_toast", "Passed to ETHR support"));
        },
        onError: (err) =>
          toast.error(
            apiErrorMessage(err, t("support.send_failed", "Could not send")),
          ),
      },
    );
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
            <SupportHandlerLabel handler={request.handled_by} />
            {staffView && request.requester && (
              <span className="text-xs">
                {t("support.asked_by", "Asked by :name", {
                  name: request.requester.name,
                })}
              </span>
            )}
          </div>
        </DialogDescription>
      </DialogHeader>

      {staffView && request.escalated_at && (
        <p className="rounded-lg border border-info/30 bg-info-soft/40 p-3 text-xs">
          {t("support.passed_by", "Passed to ETHR support by :name.", {
            name: request.escalated_by_name ?? "",
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
        viewer={viewer}
        sending={reply.isPending}
        onReply={(body) =>
          reply.mutateAsync({ publicId: request.public_id, body })
        }
      />

      {/* Everyone reads it back, the person who asked included: nothing is
          collected that they cannot see. */}
      <SupportContextPanel context={request.context} />

      {passing && (
        <div className="space-y-2 rounded-lg border p-3">
          <Label htmlFor="support-note">
            {t(
              "support.pass_note",
              "Note for ETHR support (the person who asked does not see it)",
            )}
          </Label>
          <Textarea
            id="support-note"
            value={note}
            onChange={(e) => setNote(e.target.value)}
            rows={3}
            maxLength={3000}
          />
          <div className="flex justify-end gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => setPassing(false)}
            >
              {t("common.cancel", "Cancel")}
            </Button>
            <Button
              size="sm"
              onClick={doEscalate}
              disabled={escalate.isPending}
            >
              {escalate.isPending ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : (
                <Send className="mr-2 h-4 w-4" />
              )}
              {t("support.pass_send", "Pass to ETHR support")}
            </Button>
          </div>
        </div>
      )}

      <div className="flex flex-wrap justify-end gap-2 border-t pt-4">
        {staffView && request.handled_by === "organisation" && !passing && (
          <Button variant="outline" size="sm" onClick={() => setPassing(true)}>
            {t("support.pass_to_ethr", "Pass to ETHR support")}
          </Button>
        )}
        {request.status !== "resolved" && (
          <Button
            size="sm"
            variant="outline"
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
      </div>
    </div>
  );
}
