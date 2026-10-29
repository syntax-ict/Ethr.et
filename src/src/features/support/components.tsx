"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Info, LifeBuoy, Loader2, Send } from "lucide-react";
import { toast } from "sonner";
import { useT } from "@/lib/i18n/useT";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { apiErrorMessage } from "@/lib/api/error-message";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  SUPPORT_CATEGORIES,
  type NewSupportRequest,
  type SupportCategory,
  type SupportContext,
  type SupportHandler,
  type SupportMessage,
  type SupportRequest,
  type SupportStatus,
  useCreateSupportRequest,
} from "@/features/support/api";
import { collectSupportContext } from "@/features/support/context";

const statusStyles: Record<SupportStatus, string> = {
  open: "bg-info-soft text-info-on-soft",
  answered: "bg-warning-soft text-warning-on-soft",
  resolved: "bg-success-soft text-success-on-soft",
};

export function SupportStatusBadge({ status }: { status: SupportStatus }) {
  const { t } = useT();
  return (
    <Badge
      variant="outline"
      className={cn("shrink-0 border-0 text-[10px]", statusStyles[status])}
    >
      {t(`support.status.${status}`, status)}
    </Badge>
  );
}

/** Who holds a request now: the organisation's support people, or ETHR. */
export function SupportHandlerLabel({ handler }: { handler: SupportHandler }) {
  const { t } = useT();
  return (
    <span className="text-xs text-muted-foreground">
      {handler === "ethr"
        ? t("support.with_ethr", "With ETHR support")
        : t("support.with_organisation", "With your support team")}
    </span>
  );
}

/**
 * What the person's screen knew when they asked. Shown to everyone who can
 * see the request, the person included, so nothing is collected that they
 * cannot read back.
 */
export function SupportContextPanel({
  context,
}: {
  context: SupportContext | null;
}) {
  const { t } = useT();
  const { formatDateTime } = useDateFormatters();
  if (!context) return null;

  const rows: Array<[string, string]> = [];
  if (context.page)
    rows.push([t("support.context.page", "Page"), context.page]);
  if (context.role)
    rows.push([
      t("support.context.role", "Role"),
      t(`role.${context.role}`, context.role),
    ]);
  if (context.locale)
    rows.push([t("support.context.language", "Language"), context.locale]);
  if (context.viewport)
    rows.push([t("support.context.screen", "Screen"), context.viewport]);
  if (context.online !== undefined && context.online !== null)
    rows.push([
      t("support.context.connection", "Connection"),
      context.online
        ? t("support.context.online", "Online")
        : t("support.context.offline", "Offline"),
    ]);
  if (context.pending_offline !== undefined && context.pending_offline !== null)
    rows.push([
      t("support.context.waiting", "Punches waiting to sync"),
      String(context.pending_offline),
    ]);
  if (context.release)
    rows.push([
      t("support.context.release", "Release"),
      context.release.slice(0, 9),
    ]);
  if (context.user_agent)
    rows.push([t("support.context.browser", "Browser"), context.user_agent]);

  const errors = context.recent_errors ?? [];

  return (
    <section
      aria-label={t("support.context.title", "Technical details")}
      className="space-y-2 rounded-lg border bg-muted/30 p-3 text-xs"
    >
      <p className="font-semibold uppercase tracking-wide text-muted-foreground">
        {t("support.context.title", "Technical details")}
      </p>
      {context.included === false && (
        <p className="text-muted-foreground">
          {t(
            "support.context.left_out",
            "The person chose not to include details from their screen.",
          )}
        </p>
      )}
      {rows.length > 0 && (
        <dl className="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-1">
          {rows.map(([label, value]) => (
            <div key={label} className="contents">
              <dt className="text-muted-foreground">{label}</dt>
              <dd className="min-w-0 break-words">{value}</dd>
            </div>
          ))}
        </dl>
      )}
      {context.included !== false && (
        <div>
          <p className="mb-1 text-muted-foreground">
            {t("support.context.failures", "Recent failed requests")}
          </p>
          {errors.length === 0 ? (
            <p>{t("support.context.no_failures", "None")}</p>
          ) : (
            <ul className="space-y-0.5 font-mono">
              {errors.map((e, i) => (
                <li key={i} className="break-all">
                  {e.status} {e.method} {e.path ?? ""}
                  {e.at ? ` · ${formatDateTime(e.at)}` : ""}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </section>
  );
}

/**
 * A request and its conversation. `viewer` is who is reading: the person who
 * asked sees ETHR's replies as "ETHR support"; staff see who answered.
 */
export function SupportThread({
  request,
  viewer,
  onReply,
  sending,
}: {
  request: SupportRequest;
  viewer: "requester" | "organisation" | "ethr";
  onReply: (body: string) => Promise<unknown>;
  sending: boolean;
}) {
  const { t } = useT();
  const { formatDateTime } = useDateFormatters();
  const [body, setBody] = useState("");

  async function send() {
    try {
      await onReply(body.trim());
      setBody("");
    } catch (err) {
      toast.error(
        apiErrorMessage(err, t("support.send_failed", "Could not send")),
      );
    }
  }

  function authorLabel(author: SupportMessage) {
    if (author.author === "ethr") {
      return author.author_name ?? t("support.ethr_support", "ETHR support");
    }
    if (author.author === "organisation") {
      return (
        author.author_name ??
        t("support.your_support_team", "Your support team")
      );
    }
    return viewer === "requester"
      ? t("support.you", "You")
      : (author.author_name ?? request.requester?.name ?? "");
  }

  return (
    <div className="space-y-4">
      <div className="rounded-lg bg-muted/50 p-3 text-sm whitespace-pre-line">
        {request.body}
      </div>

      <ul
        className="space-y-3"
        aria-label={t("support.conversation", "Conversation")}
      >
        {(request.messages ?? []).map((m) => {
          const mine = m.author === viewer;
          return (
            <li
              key={m.public_id}
              className={cn("flex", mine ? "justify-end" : "justify-start")}
            >
              <div
                className={cn(
                  "max-w-[85%] rounded-2xl px-3 py-2 text-sm",
                  mine ? "bg-primary text-primary-foreground" : "bg-muted",
                )}
              >
                <p className="mb-0.5 text-[11px] font-semibold opacity-80">
                  {authorLabel(m)}
                </p>
                <p className="whitespace-pre-line">{m.body}</p>
                {m.created_at && (
                  <p className="mt-1 text-[10px] opacity-70">
                    {formatDateTime(m.created_at)}
                  </p>
                )}
              </div>
            </li>
          );
        })}
      </ul>

      <div className="space-y-2">
        <Textarea
          aria-label={t("support.reply", "Reply")}
          value={body}
          maxLength={5000}
          rows={3}
          onChange={(e) => setBody(e.target.value)}
          placeholder={
            request.status === "resolved"
              ? t(
                  "support.reply_reopens",
                  "Write a reply to open this request again",
                )
              : t("support.reply_placeholder", "Write a reply")
          }
        />
        <div className="flex justify-end">
          <Button
            size="sm"
            onClick={send}
            disabled={sending || body.trim() === ""}
          >
            {sending ? (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            ) : (
              <Send className="mr-2 h-4 w-4" />
            )}
            {t("support.send_reply", "Send reply")}
          </Button>
        </div>
      </div>
    </div>
  );
}

const OPEN_EVENT = "ethr:get-help";

/** Opens the Get help dialog from anywhere: the header, a menu, a page. */
export function openGetHelp(): void {
  if (typeof window !== "undefined") {
    window.dispatchEvent(new CustomEvent(OPEN_EVENT));
  }
}

/**
 * The Get help dialog, mounted once in the header. It reads the screen's
 * details when it opens, so they describe the page the person was on, and
 * shows them before anything is sent.
 *
 * Support people (support.manage) choose who answers; everyone else asks
 * their organisation's support people, who pass it to ETHR when needed.
 */
export function GetHelpDialogHost() {
  const { t } = useT();
  const { can } = usePermissions();
  const create = useCreateSupportRequest();
  const [open, setOpen] = useState(false);
  const [category, setCategory] = useState<SupportCategory | "">("");
  const [body, setBody] = useState("");
  const [sendTo, setSendTo] = useState<SupportHandler>("organisation");
  const [includeContext, setIncludeContext] = useState(true);
  const [showDetails, setShowDetails] = useState(false);
  const [context, setContext] = useState<NewSupportRequest["context"]>();
  const [sentId, setSentId] = useState<string | null>(null);

  useEffect(() => {
    function onOpen() {
      setSentId(null);
      setOpen(true);
      void collectSupportContext().then(setContext);
    }
    window.addEventListener(OPEN_EVENT, onOpen);
    return () => window.removeEventListener(OPEN_EVENT, onOpen);
  }, []);

  function reset() {
    setCategory("");
    setBody("");
    setSendTo("organisation");
    setIncludeContext(true);
    setShowDetails(false);
  }

  function submit() {
    if (category === "" || body.trim().length < 3) return;
    create.mutate(
      {
        category,
        body: body.trim(),
        include_context: includeContext,
        ...(can.manageSupport ? { send_to: sendTo } : {}),
        ...(includeContext && context ? { context } : {}),
      },
      {
        onSuccess: (created) => {
          reset();
          setSentId(created.public_id);
        },
        onError: (err) =>
          toast.error(
            apiErrorMessage(err, t("support.send_failed", "Could not send")),
          ),
      },
    );
  }

  const failures = context?.recent_errors ?? [];

  return (
    <Dialog
      open={open}
      onOpenChange={(o) => {
        setOpen(o);
        if (!o) setSentId(null);
      }}
    >
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
        {sentId ? (
          <>
            <DialogHeader>
              <DialogTitle>
                {t("support.sent_title", "Request sent")}
              </DialogTitle>
              <DialogDescription>
                {t(
                  "support.sent_desc",
                  "You will get the answer here and by email. Replies stay together on your help requests page.",
                )}
              </DialogDescription>
            </DialogHeader>
            <DialogFooter>
              <Button variant="outline" onClick={() => setOpen(false)}>
                {t("common.close", "Close")}
              </Button>
              <Button asChild onClick={() => setOpen(false)}>
                <Link href={`/support?request=${sentId}`}>
                  {t("support.view_request", "View request")}
                </Link>
              </Button>
            </DialogFooter>
          </>
        ) : (
          <>
            <DialogHeader>
              <DialogTitle className="flex items-center gap-2">
                <LifeBuoy className="h-5 w-5 text-primary" aria-hidden="true" />
                {t("support.get_help", "Get help")}
              </DialogTitle>
              <DialogDescription>
                {can.manageSupport
                  ? t(
                      "support.intro_staff",
                      "Ask your colleagues on the support team, or ETHR support directly.",
                    )
                  : t(
                      "support.intro",
                      "Your organisation's support team answers first. They pass it to ETHR support when it needs us.",
                    )}
              </DialogDescription>
            </DialogHeader>

            <div className="space-y-4">
              {can.manageSupport && (
                <fieldset className="space-y-1.5">
                  <legend className="text-sm font-medium">
                    {t("support.who_answers", "Who should answer")}
                  </legend>
                  <div role="radiogroup" className="grid grid-cols-2 gap-2">
                    {(["organisation", "ethr"] as const).map((target) => (
                      <button
                        key={target}
                        type="button"
                        role="radio"
                        aria-checked={sendTo === target}
                        onClick={() => setSendTo(target)}
                        className={cn(
                          "rounded-lg border px-3 py-2 text-left text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                          sendTo === target
                            ? "border-primary bg-primary-soft text-primary-on-soft"
                            : "hover:bg-muted",
                        )}
                      >
                        {target === "ethr"
                          ? t("support.ethr_support", "ETHR support")
                          : t("support.our_support_team", "Our support team")}
                      </button>
                    ))}
                  </div>
                </fieldset>
              )}

              <div className="space-y-1.5">
                <Label htmlFor="support-category">
                  {t("support.topic", "Topic")}
                </Label>
                <Select
                  value={category}
                  onValueChange={(v) => setCategory(v as SupportCategory)}
                >
                  <SelectTrigger id="support-category">
                    <SelectValue
                      placeholder={t("support.choose_topic", "Choose a topic")}
                    />
                  </SelectTrigger>
                  <SelectContent>
                    {SUPPORT_CATEGORIES.map((c) => (
                      <SelectItem key={c} value={c}>
                        {t(`support.category.${c}`, c)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="support-body">
                  {t("support.what_happened", "What happened")}
                </Label>
                <Textarea
                  id="support-body"
                  value={body}
                  onChange={(e) => setBody(e.target.value)}
                  rows={4}
                  maxLength={5000}
                  placeholder={t(
                    "support.body_placeholder",
                    "Say what you were trying to do and what you saw.",
                  )}
                />
              </div>

              <div className="space-y-2 rounded-lg border p-3">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <Label htmlFor="support-include" className="text-sm">
                      {t(
                        "support.include_details",
                        "Include technical details",
                      )}
                    </Label>
                    <p className="text-xs text-muted-foreground">
                      {t(
                        "support.include_details_desc",
                        "The page you are on and what failed recently, so nobody has to ask.",
                      )}
                    </p>
                  </div>
                  <Switch
                    id="support-include"
                    checked={includeContext}
                    onCheckedChange={setIncludeContext}
                  />
                </div>
                {includeContext && (
                  <button
                    type="button"
                    onClick={() => setShowDetails((s) => !s)}
                    aria-expanded={showDetails}
                    className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                  >
                    <Info className="h-3.5 w-3.5" aria-hidden="true" />
                    {showDetails
                      ? t("support.hide_details", "Hide what is included")
                      : t("support.show_details", "See what is included")}
                  </button>
                )}
                {includeContext && showDetails && context && (
                  <ul className="space-y-0.5 text-xs text-muted-foreground">
                    <li>
                      {t("support.context.page", "Page")}: {context.page}
                    </li>
                    <li>
                      {t("support.context.language", "Language")}:{" "}
                      {context.locale}
                    </li>
                    <li>
                      {t("support.context.screen", "Screen")}:{" "}
                      {context.viewport}
                    </li>
                    <li>
                      {t("support.context.connection", "Connection")}:{" "}
                      {context.online
                        ? t("support.context.online", "Online")
                        : t("support.context.offline", "Offline")}
                    </li>
                    {context.pending_offline !== null && (
                      <li>
                        {t(
                          "support.context.waiting",
                          "Punches waiting to sync",
                        )}
                        : {context.pending_offline}
                      </li>
                    )}
                    <li>
                      {t("support.context.failures", "Recent failed requests")}:{" "}
                      {failures.length === 0
                        ? t("support.context.no_failures", "None")
                        : failures
                            .map(
                              (f) => `${f.status} ${f.method} ${f.path ?? ""}`,
                            )
                            .join(" · ")}
                    </li>
                    <li>
                      {t(
                        "support.context.also",
                        "Also your role, your browser and the ETHR release.",
                      )}
                    </li>
                  </ul>
                )}
              </div>
            </div>

            <DialogFooter>
              <Button variant="outline" onClick={() => setOpen(false)}>
                {t("common.cancel", "Cancel")}
              </Button>
              <Button
                onClick={submit}
                disabled={
                  create.isPending || category === "" || body.trim().length < 3
                }
              >
                {create.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                {t("support.send", "Send")}
              </Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}
