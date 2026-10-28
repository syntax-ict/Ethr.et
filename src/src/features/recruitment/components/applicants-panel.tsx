"use client";

import { useState } from "react";
import {
  ArrowLeft,
  Loader2,
  Plus,
  TriangleAlert,
  UserPlus,
} from "lucide-react";
import { toast } from "sonner";
import { useT } from "@/lib/i18n/useT";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { apiErrorMessage } from "@/lib/api/error-message";
import { formatETB } from "@/lib/utils/currency";
import { Sheet } from "@/components/ui/sheet";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import {
  APPLICATION_SOURCES,
  NEXT_STAGES,
  useAddApplication,
  useApplication,
  useApplications,
  useApproveOffer,
  useEraseApplication,
  useHireApplication,
  useMoveApplication,
  useRecordApplicationEvent,
  type ApplicationStage,
  type JobApplication,
  type MovePayload,
  type NewApplication,
} from "../api";

const stageStyles: Record<ApplicationStage, string> = {
  applied: "bg-muted text-muted-foreground",
  screening: "bg-info-soft text-info-on-soft",
  interview: "bg-info-soft text-info-on-soft",
  offer: "bg-warning-soft text-warning-on-soft",
  hired: "bg-success-soft text-success-on-soft",
  rejected: "bg-muted text-muted-foreground",
  withdrawn: "bg-muted text-muted-foreground",
};

export function StageBadge({ stage }: { stage: ApplicationStage }) {
  const { t } = useT();

  return (
    <Badge
      variant="outline"
      className={`border-0 text-xs ${stageStyles[stage]}`}
    >
      {t(`recruitment.stage_${stage}`, stage)}
    </Badge>
  );
}

/**
 * Applicants for one vacancy, from first contact to hire. Every decision is a
 * person's: moves are buttons, an offer waits for someone other than whoever
 * made it, and hiring creates the employee record once.
 */
export function ApplicantsPanel({
  vacancy,
  onClose,
}: {
  vacancy: { public_id: string; title: string } | null;
  onClose: () => void;
}) {
  const { t } = useT();
  const [selected, setSelected] = useState<string | null>(null);
  const [adding, setAdding] = useState(false);

  const close = () => {
    setSelected(null);
    setAdding(false);
    onClose();
  };

  return (
    <Sheet
      open={vacancy !== null}
      onOpenChange={(open) => !open && close()}
      side="right"
      showTitle
      title={t("recruitment.panel_title", "Applicants: :title", {
        title: vacancy?.title ?? "",
      })}
      className="w-full max-w-full sm:w-[36rem] sm:max-w-[36rem]"
    >
      <div className="flex flex-col gap-4 p-6 pt-4">
        {vacancy && selected ? (
          <ApplicationDetail id={selected} onBack={() => setSelected(null)} />
        ) : vacancy && adding ? (
          <AddApplicantForm
            vacancyId={vacancy.public_id}
            onDone={(created) => {
              setAdding(false);
              if (created) setSelected(created);
            }}
          />
        ) : vacancy ? (
          <ApplicantList
            vacancyId={vacancy.public_id}
            onAdd={() => setAdding(true)}
            onOpen={setSelected}
          />
        ) : null}
      </div>
    </Sheet>
  );
}

function ApplicantList({
  vacancyId,
  onAdd,
  onOpen,
}: {
  vacancyId: string;
  onAdd: () => void;
  onOpen: (id: string) => void;
}) {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  const query = useApplications(vacancyId);

  return (
    <>
      <div className="flex justify-end">
        <Button size="sm" onClick={onAdd}>
          <Plus className="mr-1 h-4 w-4" aria-hidden="true" />
          {t("recruitment.add", "Add applicant")}
        </Button>
      </div>
      <QueryBoundary
        query={query}
        isEmpty={(rows) => rows.length === 0}
        loading={
          <div className="space-y-2">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-14 w-full" />
            ))}
          </div>
        }
        empty={
          <EmptyState
            title={t("recruitment.empty_title", "No applicants yet")}
            description={t(
              "recruitment.empty_description",
              "Record people as they apply, by e-mail, in person or by referral.",
            )}
          />
        }
        error={
          <ErrorState
            title={t("recruitment.load_failed", "Could not load applicants")}
            onRetry={() => query.refetch()}
          />
        }
      >
        {(rows) => (
          <ul className="divide-y divide-border rounded-md border border-border">
            {rows.map((row) => (
              <li key={row.public_id}>
                <button
                  type="button"
                  onClick={() => onOpen(row.public_id)}
                  className="flex w-full items-center justify-between gap-3 px-3 py-2.5 text-left hover:bg-muted/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                  <span className="min-w-0">
                    <span className="block truncate text-sm font-medium text-foreground">
                      {row.name}
                    </span>
                    <span className="block text-xs text-muted-foreground">
                      {t(`recruitment.source_${row.source}`, row.source)}
                      {row.created_at ? ` · ${formatDate(row.created_at)}` : ""}
                    </span>
                  </span>
                  <StageBadge stage={row.stage} />
                </button>
              </li>
            ))}
          </ul>
        )}
      </QueryBoundary>
    </>
  );
}

function AddApplicantForm({
  vacancyId,
  onDone,
}: {
  vacancyId: string;
  onDone: (createdId: string | null) => void;
}) {
  const { t } = useT();
  const add = useAddApplication();
  const [form, setForm] = useState<NewApplication>({
    name: "",
    email: "",
    phone: "",
    source: "email",
    cover_note: "",
  });

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    add.mutate(
      {
        vacancyId,
        payload: {
          ...form,
          email: form.email || null,
          phone: form.phone || null,
          cover_note: form.cover_note || null,
        },
      },
      {
        onSuccess: (created) => {
          toast.success(t("recruitment.added", "Applicant recorded"));
          onDone(created.public_id);
        },
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t("recruitment.save_failed", "Could not save"),
            ),
          ),
      },
    );
  };

  return (
    <form onSubmit={submit} className="space-y-4">
      <BackButton onClick={() => onDone(null)} />
      <h3 className="text-sm font-semibold text-foreground">
        {t("recruitment.add", "Add applicant")}
      </h3>
      <div className="space-y-1.5">
        <Label htmlFor="applicant-name">
          {t("recruitment.name", "Full name")}
        </Label>
        <Input
          id="applicant-name"
          required
          maxLength={150}
          value={form.name}
          onChange={(e) => setForm({ ...form, name: e.target.value })}
        />
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="applicant-email">
            {t("recruitment.email", "E-mail")}
          </Label>
          <Input
            id="applicant-email"
            type="email"
            maxLength={150}
            value={form.email ?? ""}
            onChange={(e) => setForm({ ...form, email: e.target.value })}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="applicant-phone">
            {t("recruitment.phone", "Phone")}
          </Label>
          <Input
            id="applicant-phone"
            type="tel"
            maxLength={20}
            value={form.phone ?? ""}
            onChange={(e) => setForm({ ...form, phone: e.target.value })}
          />
        </div>
      </div>
      <div className="space-y-1.5">
        <Label htmlFor="applicant-source">
          {t("recruitment.source", "How they applied")}
        </Label>
        <Select
          value={form.source}
          onValueChange={(source) =>
            setForm({ ...form, source: source as NewApplication["source"] })
          }
        >
          <SelectTrigger id="applicant-source">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {APPLICATION_SOURCES.map((source) => (
              <SelectItem key={source} value={source}>
                {t(`recruitment.source_${source}`, source)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <div className="space-y-1.5">
        <Label htmlFor="applicant-note">
          {t("recruitment.cover_note", "What they sent")}
        </Label>
        <Textarea
          id="applicant-note"
          rows={4}
          maxLength={5000}
          value={form.cover_note ?? ""}
          onChange={(e) => setForm({ ...form, cover_note: e.target.value })}
        />
        <p className="text-xs text-muted-foreground">
          {t(
            "recruitment.minimum_data",
            "Record only what the selection needs. Do not record health, religion, ethnicity or political views.",
          )}
        </p>
      </div>
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={() => onDone(null)}>
          {t("common.cancel", "Cancel")}
        </Button>
        <Button
          type="submit"
          disabled={add.isPending || form.name.trim() === ""}
        >
          {add.isPending && <Loader2 className="mr-1 h-4 w-4 animate-spin" />}
          {t("common.save", "Save")}
        </Button>
      </div>
    </form>
  );
}

function BackButton({ onClick }: { onClick: () => void }) {
  const { t } = useT();

  return (
    <Button
      type="button"
      variant="ghost"
      size="sm"
      className="-ml-2 self-start"
      onClick={onClick}
    >
      <ArrowLeft className="mr-1 h-4 w-4" aria-hidden="true" />
      {t("recruitment.back", "All applicants")}
    </Button>
  );
}

function ApplicationDetail({ id, onBack }: { id: string; onBack: () => void }) {
  const { t } = useT();
  const query = useApplication(id);

  return (
    <>
      <BackButton onClick={onBack} />
      <QueryBoundary
        query={query}
        loading={<Skeleton className="h-64 w-full" />}
        error={
          <ErrorState
            title={t("recruitment.load_failed", "Could not load applicants")}
            onRetry={() => query.refetch()}
          />
        }
      >
        {(application) => <ApplicationBody application={application} />}
      </QueryBoundary>
    </>
  );
}

function ApplicationBody({ application }: { application: JobApplication }) {
  const { t } = useT();
  const { formatDate, formatDateTime } = useDateFormatters();
  const { can } = usePermissions();
  const approve = useApproveOffer();
  const erase = useEraseApplication();
  const [moving, setMoving] = useState<MovePayload["stage"] | null>(null);
  const [hiring, setHiring] = useState(false);
  const [confirmErase, setConfirmErase] = useState(false);

  const offer = application.offer;
  const duplicates = application.possible_duplicates ?? [];
  const closable =
    application.stage === "rejected" || application.stage === "withdrawn";

  const fail = (err: unknown) =>
    toast.error(
      apiErrorMessage(err, t("recruitment.save_failed", "Could not save")),
    );

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <h3 className="text-base font-semibold text-foreground">
            {application.name}
          </h3>
          <p className="text-xs text-muted-foreground">
            {[application.email, application.phone].filter(Boolean).join(" · ")}
          </p>
        </div>
        <StageBadge stage={application.stage} />
      </div>

      {duplicates.length > 0 && (
        <div
          role="status"
          className="flex gap-2 rounded-md bg-warning-soft p-3 text-sm text-warning-on-soft"
        >
          <TriangleAlert
            className="mt-0.5 h-4 w-4 shrink-0"
            aria-hidden="true"
          />
          <div>
            <p className="font-medium">
              {t("recruitment.duplicates_title", "Same e-mail or phone found")}
            </p>
            <ul className="mt-1 space-y-0.5 text-xs">
              {duplicates.map((d) => (
                <li key={`${d.kind}-${d.public_id}`}>
                  {d.kind === "employee"
                    ? t("recruitment.duplicate_employee", ":name, employee", {
                        name: d.name,
                      })
                    : t(
                        "recruitment.duplicate_application",
                        ":name, applied for :vacancy",
                        {
                          name: d.name,
                          vacancy: d.vacancy ?? "",
                        },
                      )}
                </li>
              ))}
            </ul>
            <p className="mt-1 text-xs">
              {t(
                "recruitment.duplicates_hint",
                "This may or may not be the same person. Nothing is merged.",
              )}
            </p>
          </div>
        </div>
      )}

      {application.cover_note && (
        <p className="whitespace-pre-line rounded-md bg-muted/50 p-3 text-sm text-foreground">
          {application.cover_note}
        </p>
      )}

      {offer && (
        <section className="space-y-1 rounded-md border border-border p-3 text-sm">
          <h4 className="font-medium text-foreground">
            {t("recruitment.offer", "Offer")}
          </h4>
          <p className="text-muted-foreground">
            {t("recruitment.offer_start", "Starts :date", {
              date: formatDate(offer.start_date),
            })}
            {offer.salary_cents !== null &&
              ` · ${formatETB(offer.salary_cents)}`}
          </p>
          {offer.notes && (
            <p className="text-muted-foreground">{offer.notes}</p>
          )}
          <p className="text-xs text-muted-foreground">
            {offer.approved_at
              ? t(
                  "recruitment.offer_approved_by",
                  "Approved by :name on :date",
                  {
                    name: String(offer.approved_by ?? ""),
                    date: formatDate(offer.approved_at),
                  },
                )
              : t(
                  "recruitment.offer_pending",
                  "Waiting for approval by someone other than :name",
                  {
                    name: String(offer.made_by ?? ""),
                  },
                )}
          </p>
        </section>
      )}

      {application.employee && (
        <p className="rounded-md bg-success-soft p-3 text-sm text-success-on-soft">
          {t("recruitment.hired_as", "Hired as :name (:code)", {
            name: application.employee.name,
            code: application.employee.employee_code ?? "",
          })}
        </p>
      )}

      {!application.erased && (
        <div className="flex flex-wrap gap-2">
          {NEXT_STAGES[application.stage].map((stage) => (
            <Button
              key={stage}
              size="sm"
              variant={
                stage === "rejected" || stage === "withdrawn"
                  ? "outline"
                  : "default"
              }
              onClick={() => setMoving(stage)}
            >
              {t(`recruitment.move_${stage}`, stage)}
            </Button>
          ))}
          {application.stage === "offer" &&
            offer &&
            !offer.approved_at &&
            can.approveOffers &&
            !offer.made_by_me && (
              <Button
                size="sm"
                disabled={approve.isPending}
                onClick={() =>
                  approve.mutate(application.public_id, {
                    onSuccess: () =>
                      toast.success(
                        t("recruitment.offer_approved", "Offer approved"),
                      ),
                    onError: fail,
                  })
                }
              >
                {t("recruitment.approve_offer", "Approve offer")}
              </Button>
            )}
          {application.stage === "offer" &&
            offer?.approved_at &&
            can.manageEmployees && (
              <Button size="sm" onClick={() => setHiring(true)}>
                <UserPlus className="mr-1 h-4 w-4" aria-hidden="true" />
                {t("recruitment.hire", "Hire")}
              </Button>
            )}
          {closable && (
            <Button
              size="sm"
              variant="outline"
              onClick={() => setConfirmErase(true)}
            >
              {t("recruitment.erase", "Erase personal details")}
            </Button>
          )}
        </div>
      )}

      {moving && (
        <MoveForm
          application={application}
          stage={moving}
          onDone={() => setMoving(null)}
        />
      )}
      {hiring && offer && (
        <HireForm application={application} onDone={() => setHiring(false)} />
      )}
      {!application.erased && application.stage !== "hired" && (
        <RecordForm applicationId={application.public_id} />
      )}

      <section>
        <h4 className="mb-2 text-sm font-medium text-foreground">
          {t("recruitment.history", "History")}
        </h4>
        <ol className="space-y-2 border-l border-border pl-4">
          {[...(application.events ?? [])].reverse().map((event) => (
            <li key={event.public_id} className="text-sm">
              <p className="text-foreground">
                {event.type === "stage_changed" && event.to_stage
                  ? t("recruitment.event_moved", "Moved to :stage", {
                      stage: t(
                        `recruitment.stage_${event.to_stage}`,
                        event.to_stage,
                      ),
                    })
                  : t(`recruitment.event_${event.type}`, event.type)}
                {event.score !== null &&
                  ` · ${t("recruitment.score_of", ":score / 100", { score: event.score })}`}
              </p>
              {event.scheduled_at && (
                <p className="text-xs text-muted-foreground">
                  {formatDateTime(event.scheduled_at)}
                </p>
              )}
              {event.note && (
                <p className="whitespace-pre-line text-muted-foreground">
                  {event.note}
                </p>
              )}
              <p className="text-xs text-muted-foreground">
                {[
                  String(event.actor ?? ""),
                  event.created_at ? formatDateTime(event.created_at) : "",
                ]
                  .filter(Boolean)
                  .join(" · ")}
              </p>
            </li>
          ))}
        </ol>
      </section>

      <ConfirmDialog
        open={confirmErase}
        onOpenChange={setConfirmErase}
        variant="destructive"
        title={t("recruitment.erase_title", "Erase this applicant's details?")}
        description={t(
          "recruitment.erase_description",
          "Their name, contact details and every note are removed for good. The stages and dates stay, so the selection record still adds up.",
        )}
        confirmLabel={t("recruitment.erase", "Erase personal details")}
        loading={erase.isPending}
        onConfirm={() =>
          erase.mutate(application.public_id, {
            onSuccess: () => {
              setConfirmErase(false);
              toast.success(t("recruitment.erased", "Personal details erased"));
            },
            onError: fail,
          })
        }
      />
    </div>
  );
}

function MoveForm({
  application,
  stage,
  onDone,
}: {
  application: JobApplication;
  stage: MovePayload["stage"];
  onDone: () => void;
}) {
  const { t } = useT();
  const { can } = usePermissions();
  const move = useMoveApplication();
  const closing = stage === "rejected" || stage === "withdrawn";
  const [note, setNote] = useState("");
  const [startDate, setStartDate] = useState("");
  const [salary, setSalary] = useState("");
  const [offerNotes, setOfferNotes] = useState("");

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    const payload: MovePayload = { stage, note: note || null };
    if (stage === "offer") {
      payload.offer_start_date = startDate;
      if (salary !== "")
        payload.offer_salary_cents = Math.round(Number(salary) * 100);
      if (offerNotes !== "") payload.offer_notes = offerNotes;
    }
    move.mutate(
      { id: application.public_id, payload },
      {
        onSuccess: () => {
          toast.success(t("recruitment.moved", "Stage updated"));
          onDone();
        },
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t("recruitment.save_failed", "Could not save"),
            ),
          ),
      },
    );
  };

  return (
    <form
      onSubmit={submit}
      className="space-y-3 rounded-md border border-border p-3"
    >
      <h4 className="text-sm font-medium text-foreground">
        {t(`recruitment.move_${stage}`, stage)}
      </h4>
      {stage === "offer" && (
        <>
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="offer-start">
                {t("recruitment.start_date", "Start date")}
              </Label>
              <Input
                id="offer-start"
                type="date"
                required
                value={startDate}
                onChange={(e) => setStartDate(e.target.value)}
              />
            </div>
            {can.updateEmployeeFinancial && (
              <div className="space-y-1.5">
                <Label htmlFor="offer-salary">
                  {t("recruitment.salary", "Monthly salary (ETB)")}
                </Label>
                <Input
                  id="offer-salary"
                  type="number"
                  min={0}
                  step="0.01"
                  value={salary}
                  onChange={(e) => setSalary(e.target.value)}
                />
              </div>
            )}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="offer-notes">
              {t("recruitment.offer_terms", "Terms")}
            </Label>
            <Textarea
              id="offer-notes"
              rows={2}
              maxLength={2000}
              value={offerNotes}
              onChange={(e) => setOfferNotes(e.target.value)}
            />
          </div>
        </>
      )}
      <div className="space-y-1.5">
        <Label htmlFor="move-note">
          {closing
            ? t("recruitment.reason", "Reason")
            : t("recruitment.note_optional", "Note (optional)")}
        </Label>
        <Textarea
          id="move-note"
          rows={2}
          maxLength={500}
          required={closing}
          value={note}
          onChange={(e) => setNote(e.target.value)}
        />
      </div>
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" size="sm" onClick={onDone}>
          {t("common.cancel", "Cancel")}
        </Button>
        <Button type="submit" size="sm" disabled={move.isPending}>
          {move.isPending && <Loader2 className="mr-1 h-4 w-4 animate-spin" />}
          {t("common.save", "Save")}
        </Button>
      </div>
    </form>
  );
}

function HireForm({
  application,
  onDone,
}: {
  application: JobApplication;
  onDone: () => void;
}) {
  const { t } = useT();
  const hire = useHireApplication();
  const [hireDate, setHireDate] = useState(application.offer?.start_date ?? "");
  const [probation, setProbation] = useState(false);
  const [probationEnd, setProbationEnd] = useState("");

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    hire.mutate(
      {
        id: application.public_id,
        payload: {
          hire_date: hireDate || null,
          status: probation ? "probation" : "hired",
          probation_end_date: probation ? probationEnd : null,
        },
      },
      {
        onSuccess: (employee) => {
          toast.success(
            t("recruitment.hired_toast", ":name is now an employee", {
              name: employee.name,
            }),
          );
          onDone();
        },
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t("recruitment.save_failed", "Could not save"),
            ),
          ),
      },
    );
  };

  return (
    <form
      onSubmit={submit}
      className="space-y-3 rounded-md border border-border p-3"
    >
      <h4 className="text-sm font-medium text-foreground">
        {t("recruitment.hire_title", "Create the employee record")}
      </h4>
      <div className="space-y-1.5">
        <Label htmlFor="hire-date">
          {t("recruitment.start_date", "Start date")}
        </Label>
        <Input
          id="hire-date"
          type="date"
          required
          value={hireDate}
          onChange={(e) => setHireDate(e.target.value)}
        />
      </div>
      <label className="flex items-center gap-2 text-sm text-foreground">
        <input
          type="checkbox"
          checked={probation}
          onChange={(e) => setProbation(e.target.checked)}
          className="h-4 w-4 rounded border-border"
        />
        {t("recruitment.on_probation", "Starts on probation")}
      </label>
      {probation && (
        <div className="space-y-1.5">
          <Label htmlFor="probation-end">
            {t("recruitment.probation_end", "Probation ends")}
          </Label>
          <Input
            id="probation-end"
            type="date"
            required
            value={probationEnd}
            onChange={(e) => setProbationEnd(e.target.value)}
          />
          <p className="text-xs text-muted-foreground">
            {t(
              "recruitment.probation_hint",
              "Probation applies only if agreed in writing, and its limit depends on the law that governs your organisation.",
            )}
          </p>
        </div>
      )}
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" size="sm" onClick={onDone}>
          {t("common.cancel", "Cancel")}
        </Button>
        <Button type="submit" size="sm" disabled={hire.isPending}>
          {hire.isPending && <Loader2 className="mr-1 h-4 w-4 animate-spin" />}
          {t("recruitment.hire", "Hire")}
        </Button>
      </div>
    </form>
  );
}

function RecordForm({ applicationId }: { applicationId: string }) {
  const { t } = useT();
  const record = useRecordApplicationEvent();
  const [type, setType] = useState<"note" | "interview">("note");
  const [note, setNote] = useState("");
  const [when, setWhen] = useState("");
  const [score, setScore] = useState("");

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    record.mutate(
      {
        id: applicationId,
        payload: {
          type,
          note,
          ...(type === "interview"
            ? {
                scheduled_at: new Date(when).toISOString(),
                ...(score !== "" ? { score: Number(score) } : {}),
              }
            : {}),
        },
      },
      {
        onSuccess: () => {
          setNote("");
          setWhen("");
          setScore("");
          toast.success(t("recruitment.recorded", "Added to the history"));
        },
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t("recruitment.save_failed", "Could not save"),
            ),
          ),
      },
    );
  };

  return (
    <form
      onSubmit={submit}
      className="space-y-3 rounded-md border border-border p-3"
    >
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h4 className="text-sm font-medium text-foreground">
          {t("recruitment.record_title", "Add to the history")}
        </h4>
        <Select
          value={type}
          onValueChange={(v) => setType(v as "note" | "interview")}
        >
          <SelectTrigger
            className="h-8 w-40"
            aria-label={t("recruitment.record_type", "Kind")}
          >
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="note">
              {t("recruitment.event_note", "Note")}
            </SelectItem>
            <SelectItem value="interview">
              {t("recruitment.event_interview", "Interview")}
            </SelectItem>
          </SelectContent>
        </Select>
      </div>
      {type === "interview" && (
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="interview-when">
              {t("recruitment.interview_when", "When")}
            </Label>
            <Input
              id="interview-when"
              type="datetime-local"
              required
              value={when}
              onChange={(e) => setWhen(e.target.value)}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="interview-score">
              {t("recruitment.score", "Panel score out of 100")}
            </Label>
            <Input
              id="interview-score"
              type="number"
              min={0}
              max={100}
              value={score}
              onChange={(e) => setScore(e.target.value)}
            />
          </div>
        </div>
      )}
      <Textarea
        aria-label={t("recruitment.note", "Note")}
        rows={2}
        maxLength={5000}
        required={type === "note"}
        value={note}
        onChange={(e) => setNote(e.target.value)}
      />
      <div className="flex justify-end">
        <Button type="submit" size="sm" disabled={record.isPending}>
          {record.isPending && (
            <Loader2 className="mr-1 h-4 w-4 animate-spin" />
          )}
          {t("recruitment.record", "Add")}
        </Button>
      </div>
    </form>
  );
}
