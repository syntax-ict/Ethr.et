"use client";

import { useState } from "react";
import { useHydrated } from "@/lib/hooks/useHydrated";
import Link from "next/link";
import {
  Award,
  BarChart3,
  Copy,
  EyeOff,
  Frown,
  HeartHandshake,
  Laugh,
  Loader2,
  Lock,
  Meh,
  MessageSquareWarning,
  Send,
  ShieldCheck,
  Smile,
  SmilePlus,
} from "lucide-react";
import { toast } from "sonner";
import { useT } from "@/lib/i18n/useT";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { apiErrorDetail, apiErrorMessage } from "@/lib/api/error-message";
import { cn } from "@/lib/utils";
import { PageActions } from "@/components/shared/page-actions";
import { EmptyState } from "@/components/shared/empty-state";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { useDirectory } from "@/features/directory/api";
import {
  CONCERN_CATEGORIES,
  PULSE_TAGS,
  RECOGNITION_VALUES,
  useAnswerPulse,
  useConcern,
  useConcernMessage,
  useMyConcerns,
  usePulse,
  useRaiseConcern,
  useRecognise,
  useRecognitions,
  useTrackConcern,
  useTrackReply,
  type Concern,
  type ConcernCategory,
  type PulseTag,
  type RecognitionValue,
} from "@/features/voice/api";
import { ConcernStatusBadge, ConcernThread } from "@/features/voice/components";
import { ErrorState } from "@/components/shared/error-state";

type Tab = "pulse" | "speak-up" | "recognition";

const FACES = [
  { score: 1, icon: Frown },
  { score: 2, icon: Meh },
  { score: 3, icon: Smile },
  { score: 4, icon: SmilePlus },
  { score: 5, icon: Laugh },
] as const;

/**
 * Employee voice: say how work is going, raise what worries you - safely,
 * anonymously if you wish - and thank colleagues. Built so that speaking up
 * costs nothing: the pulse is anonymous by construction, and an anonymous
 * concern stores no name at all.
 */
export default function VoicePage() {
  const { t } = useT();
  const { can } = usePermissions();
  const [chosen, setTab] = useState<Tab | null>(null);
  // Notifications link to ?tab=…, read once hydrated: the page is
  // prerendered without a query string (static export).
  const hydrated = useHydrated();
  const requested = hydrated
    ? new URLSearchParams(window.location.search).get("tab")
    : null;
  const tab: Tab =
    chosen ??
    (requested === "speak-up" || requested === "recognition"
      ? requested
      : "pulse");

  return (
    <div className="space-y-6">
      <PageActions
        actions={
          can.manageVoice && (
            <Button asChild variant="outline">
              <Link href="/voice/insights">
                <BarChart3 className="mr-2 h-4 w-4" />
                {t("voice.insights", "Insights")}
              </Link>
            </Button>
          )
        }
      />

      <Tabs value={tab} onValueChange={(v) => setTab(v as Tab)}>
        <TabsList variant="underline">
          <TabsTrigger value="pulse">
            {t("voice.tab_pulse", "Weekly pulse")}
          </TabsTrigger>
          <TabsTrigger value="speak-up">
            {t("voice.tab_speak_up", "Speak up")}
          </TabsTrigger>
          <TabsTrigger value="recognition">
            {t("voice.tab_recognition", "Recognition")}
          </TabsTrigger>
        </TabsList>
        <TabsContent value="pulse" className="mt-6">
          <PulseCard />
        </TabsContent>
        <TabsContent value="speak-up" className="mt-6">
          <SpeakUp />
        </TabsContent>
        <TabsContent value="recognition" className="mt-6">
          <RecognitionWall />
        </TabsContent>
      </Tabs>
    </div>
  );
}

function PrivacyNote({ children }: { children: React.ReactNode }) {
  return (
    <p className="flex items-start gap-2 rounded-lg bg-muted/60 p-3 text-xs text-muted-foreground">
      <ShieldCheck
        className="mt-0.5 h-4 w-4 shrink-0 text-primary"
        aria-hidden="true"
      />
      <span>{children}</span>
    </p>
  );
}

// ── Pulse ──

function PulseCard() {
  const { t } = useT();
  const { data, isLoading, isError, refetch } = usePulse();
  const answer = useAnswerPulse();
  const [score, setScore] = useState<number | null>(null);
  const [tags, setTags] = useState<PulseTag[]>([]);
  const [comment, setComment] = useState("");

  const faceLabel = (s: number) =>
    [
      t("voice.score_1", "Very hard"),
      t("voice.score_2", "Hard"),
      t("voice.score_3", "Okay"),
      t("voice.score_4", "Good"),
      t("voice.score_5", "Great"),
    ][s - 1];

  if (isLoading) return <Skeleton className="h-72 max-w-2xl" />;
  // A failed load drew the question as if unanswered.
  if (isError)
    return <ErrorState onRetry={() => refetch()} className="max-w-2xl" />;

  if (data?.answered) {
    return (
      <Card className="max-w-2xl">
        <CardContent className="flex flex-col items-center p-10 text-center">
          <HeartHandshake
            className="h-10 w-10 text-primary"
            aria-hidden="true"
          />
          <h2 className="mt-4 text-lg font-semibold">
            {t("voice.pulse_thanks", "Thank you - you answered this week.")}
          </h2>
          <p className="mt-1 text-sm text-muted-foreground">
            {t(
              "voice.pulse_next",
              "The next pulse opens on Monday. Something on your mind before then? Use Speak up.",
            )}
          </p>
        </CardContent>
      </Card>
    );
  }

  function toggleTag(tag: PulseTag) {
    setTags((prev) =>
      prev.includes(tag)
        ? prev.filter((x) => x !== tag)
        : prev.length >= 5
          ? prev
          : [...prev, tag],
    );
  }

  function submit() {
    if (score === null) return;
    answer.mutate(
      { score, tags, comment: comment.trim() || null },
      {
        onSuccess: () =>
          toast.success(t("voice.pulse_sent", "Thank you for answering")),
        onError: (err) =>
          toast.error(
            apiErrorDetail(err) ?? t("voice.send_failed", "Could not send"),
          ),
      },
    );
  }

  return (
    <Card className="max-w-2xl">
      <CardHeader>
        <CardTitle className="text-base">
          {t("voice.pulse_question", "How was work for you this week?")}
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-6">
        <div
          role="radiogroup"
          aria-label={t(
            "voice.pulse_question",
            "How was work for you this week?",
          )}
          className="grid grid-cols-5 gap-2"
        >
          {FACES.map(({ score: s, icon: Icon }) => (
            <button
              key={s}
              type="button"
              role="radio"
              aria-checked={score === s}
              onClick={() => setScore(s)}
              className={cn(
                "flex flex-col items-center gap-1.5 rounded-xl border p-3 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                score === s
                  ? "border-primary bg-primary/10 text-primary"
                  : "text-muted-foreground hover:bg-muted",
              )}
            >
              <Icon className="h-7 w-7" aria-hidden="true" />
              {faceLabel(s)}
            </button>
          ))}
        </div>

        <div>
          <p className="text-sm font-medium">
            {t(
              "voice.pulse_tags",
              "What is it mostly about? (optional, up to 5)",
            )}
          </p>
          <div className="mt-2 flex flex-wrap gap-2">
            {PULSE_TAGS.map((tag) => (
              <button
                key={tag}
                type="button"
                aria-pressed={tags.includes(tag)}
                onClick={() => toggleTag(tag)}
                className={cn(
                  "rounded-full border px-3 py-1 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                  tags.includes(tag)
                    ? "border-primary bg-primary text-primary-foreground"
                    : "text-muted-foreground hover:bg-muted",
                )}
              >
                {t(`voice.tag.${tag}`, tag)}
              </button>
            ))}
          </div>
        </div>

        <div>
          <Label htmlFor="pulse-comment">
            {t(
              "voice.pulse_comment",
              "Anything you want HR to know? (optional)",
            )}
          </Label>
          <Textarea
            id="pulse-comment"
            value={comment}
            maxLength={500}
            rows={3}
            onChange={(e) => setComment(e.target.value)}
            className="mt-1"
          />
        </div>

        <PrivacyNote>
          {t(
            "voice.pulse_privacy",
            "Anonymous by design: your name is never stored with your answer, and HR only sees results for groups of 5 or more.",
          )}
        </PrivacyNote>

        <div className="flex justify-end">
          <Button
            onClick={submit}
            disabled={score === null || answer.isPending}
          >
            {answer.isPending ? (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            ) : (
              <Send className="mr-2 h-4 w-4" />
            )}
            {t("voice.pulse_submit", "Send anonymously")}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

// ── Speak up ──

function SpeakUp() {
  const { t } = useT();
  const raise = useRaiseConcern();
  const { data: mine, isLoading, isError, refetch } = useMyConcerns();
  const [category, setCategory] = useState<ConcernCategory>("safety");
  const [subject, setSubject] = useState("");
  const [description, setDescription] = useState("");
  const [anonymous, setAnonymous] = useState(true);
  const [code, setCode] = useState<string | null>(null);
  const [open, setOpen] = useState<string | null>(null);

  function submit() {
    raise.mutate(
      {
        category,
        subject: subject.trim(),
        description: description.trim(),
        anonymous,
      },
      {
        onSuccess: (concern) => {
          setSubject("");
          setDescription("");
          if (concern.tracking_code) {
            setCode(concern.tracking_code);
          } else {
            toast.success(t("voice.concern_sent", "Sent. HR will reply here."));
          }
        },
        onError: (err) =>
          toast.error(
            apiErrorDetail(err) ?? t("voice.send_failed", "Could not send"),
          ),
      },
    );
  }

  return (
    <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,24rem)]">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-base">
            <MessageSquareWarning
              className="h-4 w-4 text-primary"
              aria-hidden="true"
            />
            {t("voice.speak_up_title", "Raise a concern")}
          </CardTitle>
          <p className="text-sm text-muted-foreground">
            {t(
              "voice.speak_up_intro",
              "Unsafe work, harassment, unfair treatment, a problem with a manager - tell HR. Raising a concern in good faith is always protected.",
            )}
          </p>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <Label htmlFor="concern-category">
                {t("voice.category_label", "Category")}
              </Label>
              <Select
                value={category}
                onValueChange={(v) => setCategory(v as ConcernCategory)}
              >
                <SelectTrigger id="concern-category" className="mt-1">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {CONCERN_CATEGORIES.map((c) => (
                    <SelectItem key={c} value={c}>
                      {t(`voice.category.${c}`, c)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label htmlFor="concern-subject">
                {t("voice.subject", "Subject")}
              </Label>
              <Input
                id="concern-subject"
                value={subject}
                maxLength={150}
                onChange={(e) => setSubject(e.target.value)}
                className="mt-1"
              />
            </div>
          </div>
          <div>
            <Label htmlFor="concern-description">
              {t("voice.what_happened", "What happened?")}
            </Label>
            <Textarea
              id="concern-description"
              value={description}
              maxLength={5000}
              rows={6}
              onChange={(e) => setDescription(e.target.value)}
              className="mt-1"
              placeholder={t(
                "voice.what_happened_hint",
                "When and where, who was involved, and what you would like to happen.",
              )}
            />
          </div>
          <div className="flex items-start justify-between gap-4 rounded-lg border p-3">
            <div>
              <Label
                htmlFor="concern-anonymous"
                className="flex items-center gap-1.5"
              >
                <EyeOff className="h-4 w-4" aria-hidden="true" />
                {t("voice.anonymous", "Send anonymously")}
              </Label>
              <p className="mt-0.5 text-xs text-muted-foreground">
                {anonymous
                  ? t(
                      "voice.anonymous_on",
                      "Your name is not stored. You get a private code to read HR's replies and answer them.",
                    )
                  : t(
                      "voice.anonymous_off",
                      "HR sees your name and can reply to you directly. Only HR can see this concern.",
                    )}
              </p>
            </div>
            <Switch
              id="concern-anonymous"
              checked={anonymous}
              onCheckedChange={setAnonymous}
            />
          </div>
          <div className="flex justify-end">
            <Button
              onClick={submit}
              disabled={
                raise.isPending ||
                subject.trim() === "" ||
                description.trim() === ""
              }
            >
              {raise.isPending ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : (
                <Send className="mr-2 h-4 w-4" />
              )}
              {t("voice.send_concern", "Send to HR")}
            </Button>
          </div>
        </CardContent>
      </Card>

      <div className="space-y-6">
        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              {t("voice.my_concerns", "My concerns")}
            </CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <Skeleton className="h-20" />
            ) : isError ? (
              <ErrorState onRetry={() => refetch()} />
            ) : !mine?.data.length ? (
              <p className="text-sm text-muted-foreground">
                {t(
                  "voice.no_named_concerns",
                  "Concerns you raise with your name appear here.",
                )}
              </p>
            ) : (
              <ul className="divide-y">
                {mine.data.map((c) => (
                  <li key={c.public_id}>
                    <button
                      type="button"
                      onClick={() => setOpen(c.public_id)}
                      className="flex w-full items-center justify-between gap-3 py-2.5 text-left text-sm hover:text-primary"
                    >
                      <span className="min-w-0 truncate">{c.subject}</span>
                      <ConcernStatusBadge status={c.status} />
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
        <TrackByCode />
      </div>

      <Dialog open={code !== null} onOpenChange={(o) => !o && setCode(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("voice.code_title", "Keep this code")}</DialogTitle>
            <DialogDescription>
              {t(
                "voice.code_desc",
                "Your concern was sent without your name. This code is the only way to read HR's replies and answer them - it is shown once and cannot be recovered.",
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="flex items-center gap-2 rounded-lg border bg-muted/40 p-3">
            <Lock
              className="h-4 w-4 text-muted-foreground"
              aria-hidden="true"
            />
            <code
              className="flex-1 font-mono text-lg tracking-widest"
              data-testid="tracking-code"
            >
              {code}
            </code>
            <Button
              size="sm"
              variant="outline"
              onClick={() => {
                if (!code) return;
                navigator.clipboard
                  .writeText(code)
                  .then(() =>
                    toast.success(t("voice.code_copied", "Code copied")),
                  )
                  .catch(() => undefined);
              }}
            >
              <Copy className="mr-1.5 h-3.5 w-3.5" />
              {t("voice.copy", "Copy")}
            </Button>
          </div>
          <DialogFooter>
            <Button onClick={() => setCode(null)}>
              {t("voice.code_saved", "I have saved it")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <NamedConcernDialog publicId={open} onClose={() => setOpen(null)} />
    </div>
  );
}

function NamedConcernDialog({
  publicId,
  onClose,
}: {
  publicId: string | null;
  onClose: () => void;
}) {
  const { data } = useConcern(publicId);
  const reply = useConcernMessage();

  return (
    <Dialog open={publicId !== null} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-xl">
        {data ? (
          <ConcernThread
            concern={data}
            viewer="reporter"
            sending={reply.isPending}
            onReply={(body) =>
              reply.mutateAsync({ publicId: data.public_id, body })
            }
          />
        ) : (
          <>
            <DialogTitle className="sr-only">…</DialogTitle>
            <Skeleton className="h-40" />
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}

function TrackByCode() {
  const { t } = useT();
  const track = useTrackConcern();
  const reply = useTrackReply();
  const [code, setCode] = useState("");
  const [concern, setConcern] = useState<Concern | null>(null);

  function open() {
    track.mutate(code.trim(), {
      onSuccess: setConcern,
      onError: (err) =>
        toast.error(
          apiErrorMessage(
            err,
            t("voice.code_not_found", "No concern matches that code"),
          ),
        ),
    });
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">
          {t("voice.track_title", "Follow an anonymous concern")}
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="flex gap-2">
          <Input
            aria-label={t("voice.code_label", "Your code")}
            placeholder="XXXX-XXXX-XXXX-XXXX"
            value={code}
            onChange={(e) => setCode(e.target.value)}
            className="font-mono uppercase"
            autoComplete="off"
          />
          <Button
            variant="outline"
            onClick={open}
            disabled={code.trim().length < 16 || track.isPending}
          >
            {t("voice.open", "Open")}
          </Button>
        </div>
      </CardContent>
      <Dialog
        open={concern !== null}
        onOpenChange={(o) => !o && setConcern(null)}
      >
        <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-xl">
          {concern && (
            <ConcernThread
              concern={concern}
              viewer="reporter"
              sending={reply.isPending}
              onReply={async (body) => {
                setConcern(
                  await reply.mutateAsync({ code: code.trim(), body }),
                );
              }}
            />
          )}
        </DialogContent>
      </Dialog>
    </Card>
  );
}

// ── Recognition ──

function RecognitionWall() {
  const { t } = useT();
  const { timeAgo } = useDateFormatters();
  const { data, isLoading, isError, refetch } = useRecognitions();
  const recognise = useRecognise();
  const [search, setSearch] = useState("");
  const [to, setTo] = useState<{ public_id: string; name: string } | null>(
    null,
  );
  const [value, setValue] = useState<RecognitionValue>("teamwork");
  const [message, setMessage] = useState("");
  const people = useDirectory(
    { search, per_page: 6 },
    { enabled: search.trim().length >= 2 && to === null },
  );

  function send() {
    if (!to) return;
    recognise.mutate(
      { to_employee_public_id: to.public_id, value, message: message.trim() },
      {
        onSuccess: () => {
          toast.success(t("voice.recognition_sent", "Thank you sent"));
          setTo(null);
          setSearch("");
          setMessage("");
        },
        onError: (err) =>
          toast.error(
            apiErrorDetail(err) ?? t("voice.send_failed", "Could not send"),
          ),
      },
    );
  }

  return (
    <div className="grid gap-6 xl:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-base">
            <Award className="h-4 w-4 text-primary" aria-hidden="true" />
            {t("voice.recognise_title", "Thank a colleague")}
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div>
            <Label htmlFor="recognise-who">{t("voice.who", "Who")}</Label>
            {to ? (
              <div className="mt-1 flex items-center justify-between rounded-md border px-3 py-2 text-sm">
                <span className="font-medium">{to.name}</span>
                <button
                  type="button"
                  onClick={() => setTo(null)}
                  className="text-xs text-muted-foreground hover:text-foreground"
                >
                  {t("voice.change", "Change")}
                </button>
              </div>
            ) : (
              <>
                <Input
                  id="recognise-who"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder={t("voice.search_colleague", "Search by name")}
                  className="mt-1"
                  autoComplete="off"
                />
                {(people.data?.data ?? []).length > 0 && (
                  <ul className="mt-1 divide-y rounded-md border">
                    {people.data?.data.map((p) => (
                      <li key={p.public_id}>
                        <button
                          type="button"
                          onClick={() =>
                            setTo({ public_id: p.public_id, name: p.name })
                          }
                          className="w-full px-3 py-2 text-left text-sm hover:bg-muted"
                        >
                          {p.name}
                          {p.department && (
                            <span className="ml-1.5 text-xs text-muted-foreground">
                              {p.department}
                            </span>
                          )}
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
              </>
            )}
          </div>
          <div>
            <p className="text-sm font-medium">{t("voice.for", "For")}</p>
            <div className="mt-2 flex flex-wrap gap-2">
              {RECOGNITION_VALUES.map((v) => (
                <button
                  key={v}
                  type="button"
                  aria-pressed={value === v}
                  onClick={() => setValue(v)}
                  className={cn(
                    "rounded-full border px-3 py-1 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                    value === v
                      ? "border-primary bg-primary text-primary-foreground"
                      : "text-muted-foreground hover:bg-muted",
                  )}
                >
                  {t(`voice.value.${v}`, v)}
                </button>
              ))}
            </div>
          </div>
          <div>
            <Label htmlFor="recognise-message">
              {t("voice.message", "Message")}
            </Label>
            <Textarea
              id="recognise-message"
              value={message}
              maxLength={500}
              rows={3}
              onChange={(e) => setMessage(e.target.value)}
              className="mt-1"
            />
          </div>
          <div className="flex justify-end">
            <Button
              onClick={send}
              disabled={!to || message.trim() === "" || recognise.isPending}
            >
              {recognise.isPending ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : (
                <Send className="mr-2 h-4 w-4" />
              )}
              {t("voice.send_thanks", "Send thanks")}
            </Button>
          </div>
        </CardContent>
      </Card>

      <div className="space-y-3">
        {isLoading ? (
          <Skeleton className="h-40" />
        ) : isError ? (
          <ErrorState onRetry={() => refetch()} />
        ) : !data?.data.length ? (
          <EmptyState
            icon={Award}
            title={t("voice.wall_empty_title", "No thanks yet")}
            description={t(
              "voice.wall_empty_desc",
              "Be the first to thank a colleague for something they did.",
            )}
          />
        ) : (
          data.data.map((r) => (
            <Card key={r.public_id}>
              <CardContent className="p-4">
                <div className="flex flex-wrap items-center gap-2 text-sm">
                  <span className="font-semibold">{r.from?.name}</span>
                  <span className="text-muted-foreground">
                    {t("voice.thanked", "thanked")}
                  </span>
                  <span className="font-semibold">{r.to?.name}</span>
                  <Badge variant="outline" className="text-[10px]">
                    {t(`voice.value.${r.value}`, r.value)}
                  </Badge>
                </div>
                <p className="mt-2 whitespace-pre-line text-sm text-foreground">
                  {r.message}
                </p>
                {r.created_at && (
                  <p className="mt-2 text-xs text-muted-foreground">
                    {timeAgo(r.created_at)}
                  </p>
                )}
              </CardContent>
            </Card>
          ))
        )}
      </div>
    </div>
  );
}
