"use client";

import { useState } from "react";
import { EyeOff, Loader2, Send } from "lucide-react";
import { toast } from "sonner";
import { useT } from "@/lib/i18n/useT";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import {
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import type { Concern, ConcernStatus } from "@/features/voice/api";

const statusStyles: Record<ConcernStatus, string> = {
  new: "bg-info-soft text-info-on-soft",
  in_review: "bg-warning-soft text-warning-on-soft",
  resolved: "bg-success-soft text-success-on-soft",
  closed: "bg-muted text-muted-foreground",
};

export function ConcernStatusBadge({ status }: { status: ConcernStatus }) {
  const { t } = useT();
  return (
    <Badge
      variant="outline"
      className={cn("shrink-0 border-0 text-[10px]", statusStyles[status])}
    >
      {t(`voice.status.${status}`, status)}
    </Badge>
  );
}

/**
 * A concern and its conversation, for its reporter or for HR. The reporter
 * sees replies as "HR"; HR sees which colleague answered. An anonymous
 * reporter is never named to anyone.
 */
export function ConcernThread({
  concern,
  viewer,
  onReply,
  sending,
}: {
  concern: Concern;
  viewer: "reporter" | "hr";
  onReply: (body: string) => Promise<unknown>;
  sending: boolean;
}) {
  const { t } = useT();
  const { formatDateTime } = useDateFormatters();
  const [body, setBody] = useState("");
  const closed = concern.status === "closed";

  async function send() {
    try {
      await onReply(body.trim());
      setBody("");
    } catch {
      toast.error(t("voice.send_failed", "Could not send"));
    }
  }

  return (
    <div className="space-y-4">
      <DialogHeader>
        <DialogTitle className="pr-6">{concern.subject}</DialogTitle>
        <DialogDescription asChild>
          <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
            <span>
              {t(`voice.category.${concern.category}`, concern.category)}
            </span>
            <ConcernStatusBadge status={concern.status} />
            {viewer === "hr" &&
              (concern.anonymous ? (
                <span className="inline-flex items-center gap-1 text-xs">
                  <EyeOff className="h-3.5 w-3.5" aria-hidden="true" />
                  {t("voice.anonymous_reporter", "Anonymous")}
                </span>
              ) : (
                concern.reporter_name && (
                  <span className="text-xs">
                    {t("voice.raised_by", "Raised by :name", {
                      name: concern.reporter_name,
                    })}
                  </span>
                )
              ))}
          </div>
        </DialogDescription>
      </DialogHeader>

      <div className="rounded-lg bg-muted/50 p-3 text-sm whitespace-pre-line">
        {concern.description}
      </div>

      {concern.resolution && (
        <div className="rounded-lg border border-success/30 bg-success-soft/40 p-3 text-sm">
          <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-success-on-soft">
            {t("voice.resolution", "Resolution")}
          </p>
          <p className="whitespace-pre-line">{concern.resolution}</p>
        </div>
      )}

      <ul
        className="space-y-3"
        aria-label={t("voice.conversation", "Conversation")}
      >
        {(concern.messages ?? []).map((m) => {
          const mine =
            (viewer === "reporter" && m.author === "reporter") ||
            (viewer === "hr" && m.author === "hr");
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
                  {m.author === "hr"
                    ? (m.author_name ?? t("voice.hr", "HR"))
                    : concern.anonymous
                      ? t("voice.anonymous_reporter", "Anonymous")
                      : (concern.reporter_name ?? t("voice.you", "You"))}
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

      {!closed && (
        <div className="space-y-2">
          <Textarea
            aria-label={t("voice.reply", "Reply")}
            value={body}
            maxLength={3000}
            rows={3}
            onChange={(e) => setBody(e.target.value)}
            placeholder={t("voice.reply_placeholder", "Write a reply…")}
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
              {t("voice.send_reply", "Send reply")}
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
