"use client";

import { useMemo, useState } from "react";
import {
  Briefcase,
  CalendarClock,
  ExternalLink,
  Loader2,
  Mail,
  MapPin,
  Pencil,
  Plus,
  Trash2,
  Users,
} from "lucide-react";
import { toast } from "sonner";
import { useT } from "@/lib/i18n/useT";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
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
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { branchesApi, departmentsApi } from "@/features/organization/api";
import {
  EMPLOYMENT_TYPES,
  useCreateVacancy,
  useDeleteVacancy,
  useUpdateVacancy,
  useVacancies,
  type EmploymentType,
  type Vacancy,
  type VacancyPayload,
  type VacancyStatus,
  type VacancyVisibility,
} from "@/features/vacancies/api";
import { apiErrorMessage } from "@/lib/api/error-message";
import { ErrorState } from "@/components/shared/error-state";

const NONE = "__none__";

type Filter = "open" | "draft" | "closed";

interface FormState {
  title: string;
  employment_type: EmploymentType;
  description: string;
  requirements: string;
  location: string;
  positions: string;
  visibility: VacancyVisibility;
  status: VacancyStatus;
  closes_on: string;
  apply_email: string;
  apply_url: string;
  department_id: string;
  branch_id: string;
}

const EMPTY_FORM: FormState = {
  title: "",
  employment_type: "full_time",
  description: "",
  requirements: "",
  location: "",
  positions: "1",
  visibility: "internal",
  status: "open",
  closes_on: "",
  apply_email: "",
  apply_url: "",
  department_id: NONE,
  branch_id: NONE,
};

const statusStyles: Record<string, string> = {
  open: "bg-success-soft text-success-on-soft",
  draft: "bg-muted text-muted-foreground",
  closed: "bg-warning-soft text-warning-on-soft",
};

/**
 * Open positions. Employees see what is advertised now - a place to look for
 * the next role inside the organisation before it is filled from outside.
 * Whoever holds `vacancy.manage` posts, edits, closes and reopens them, and
 * chooses which also appear on the public sign-in page.
 */
export default function VacanciesPage() {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  const { can } = usePermissions();
  const { data, isLoading, isError, refetch } = useVacancies();
  const updateVacancy = useUpdateVacancy();
  const deleteVacancy = useDeleteVacancy();

  const [filter, setFilter] = useState<Filter>("open");
  const [editing, setEditing] = useState<Vacancy | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [viewing, setViewing] = useState<Vacancy | null>(null);
  const [pendingDelete, setPendingDelete] = useState<Vacancy | null>(null);

  const vacancies = useMemo(() => data ?? [], [data]);
  const counts = useMemo(
    () => ({
      open: vacancies.filter((v) => v.status === "open").length,
      draft: vacancies.filter((v) => v.status === "draft").length,
      closed: vacancies.filter((v) => v.status === "closed").length,
    }),
    [vacancies],
  );
  const shown = can.manageVacancies
    ? vacancies.filter((v) => v.status === filter)
    : vacancies;

  function setStatus(v: Vacancy, status: VacancyStatus) {
    updateVacancy.mutate(
      { publicId: v.public_id, payload: { status } },
      {
        onSuccess: () =>
          toast.success(
            status === "closed"
              ? t("vacancies.closed_toast", "Vacancy closed")
              : t("vacancies.opened_toast", "Vacancy is open"),
          ),
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t("vacancies.save_failed", "Failed to save vacancy"),
            ),
          ),
      },
    );
  }

  function remove(v: Vacancy) {
    deleteVacancy.mutate(v.public_id, {
      onSuccess: () => {
        setPendingDelete(null);
        toast.success(t("vacancies.deleted", "Vacancy deleted"));
      },
      onError: (err) =>
        toast.error(
          apiErrorMessage(
            err,
            t("vacancies.delete_failed", "Failed to delete vacancy"),
          ),
        ),
    });
  }

  const filters: Array<{ value: Filter; label: string }> = [
    { value: "open", label: t("vacancies.status_open", "Open") },
    { value: "draft", label: t("vacancies.status_draft", "Draft") },
    { value: "closed", label: t("vacancies.status_closed", "Closed") },
  ];

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("vacancies.title", "Vacancies")}
        description={t(
          "vacancies.description",
          "Open positions in the organisation",
        )}
        actions={
          can.manageVacancies && (
            <Button
              onClick={() => {
                setEditing(null);
                setFormOpen(true);
              }}
            >
              <Plus className="mr-2 h-4 w-4" />
              {t("vacancies.new", "Post vacancy")}
            </Button>
          )
        }
      />

      {can.manageVacancies && (
        <div
          role="tablist"
          className="inline-flex rounded-lg border bg-muted/40 p-0.5"
        >
          {filters.map((f) => (
            <button
              key={f.value}
              type="button"
              role="tab"
              aria-selected={filter === f.value}
              onClick={() => setFilter(f.value)}
              className={cn(
                "rounded-md px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                filter === f.value
                  ? "bg-background text-foreground shadow-xs"
                  : "text-muted-foreground hover:text-foreground",
              )}
            >
              {f.label}
              <span className="ml-1.5 tabular-nums text-xs text-muted-foreground">
                {counts[f.value]}
              </span>
            </button>
          ))}
        </div>
      )}

      {isLoading ? (
        <div className="grid gap-3 md:grid-cols-2">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-32" />
          ))}
        </div>
      ) : isError ? (
        <ErrorState onRetry={() => refetch()} />
      ) : shown.length === 0 ? (
        <EmptyState
          icon={Briefcase}
          title={t("vacancies.empty_title", "No open positions")}
          description={
            can.manageVacancies
              ? t(
                  "vacancies.empty_manage",
                  "Post a vacancy so employees - and, if you choose, applicants - can see it.",
                )
              : t(
                  "vacancies.empty_desc",
                  "When a position opens, it appears here first.",
                )
          }
        />
      ) : (
        <div className="grid gap-3 md:grid-cols-2">
          {shown.map((v) => (
            <Card
              key={v.public_id}
              className="transition-shadow hover:shadow-sm"
            >
              <CardContent className="flex h-full flex-col p-4">
                <button
                  type="button"
                  onClick={() => setViewing(v)}
                  className="text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded-sm"
                >
                  <div className="flex flex-wrap items-center gap-2">
                    <h3 className="text-sm font-semibold text-foreground">
                      {v.title}
                    </h3>
                    {can.manageVacancies && (
                      <Badge
                        variant="outline"
                        className={`border-0 text-[10px] ${statusStyles[v.status] ?? ""}`}
                      >
                        {t(`vacancies.status_${v.status}`, v.status)}
                      </Badge>
                    )}
                  </div>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {[
                      t(
                        `vacancies.type_${v.employment_type}`,
                        v.employment_type,
                      ),
                      v.department?.name,
                      v.location ?? v.branch?.name,
                    ]
                      .filter(Boolean)
                      .join(" · ")}
                  </p>
                  <p className="mt-2 line-clamp-2 text-sm text-muted-foreground">
                    {v.description}
                  </p>
                </button>
                <div className="mt-auto flex flex-wrap items-center justify-between gap-2 pt-3">
                  <p className="flex items-center gap-1 text-xs text-muted-foreground">
                    <CalendarClock className="h-3.5 w-3.5" aria-hidden="true" />
                    {v.closes_on
                      ? t("vacancies.closes_on", "Closes :date", {
                          date: formatDate(v.closes_on),
                        })
                      : t("vacancies.open_until_filled", "Open until filled")}
                  </p>
                  {can.manageVacancies && (
                    <div className="flex gap-1">
                      {v.status === "open" ? (
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setStatus(v, "closed")}
                        >
                          {t("vacancies.close", "Close")}
                        </Button>
                      ) : (
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setStatus(v, "open")}
                        >
                          {v.status === "draft"
                            ? t("vacancies.publish", "Publish")
                            : t("vacancies.reopen", "Reopen")}
                        </Button>
                      )}
                      <Button
                        variant="ghost"
                        size="sm"
                        aria-label={t("common.edit", "Edit")}
                        onClick={() => {
                          setEditing(v);
                          setFormOpen(true);
                        }}
                      >
                        <Pencil className="h-4 w-4 text-muted-foreground" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        aria-label={t("common.delete", "Delete")}
                        onClick={() => setPendingDelete(v)}
                      >
                        <Trash2 className="h-4 w-4 text-muted-foreground" />
                      </Button>
                    </div>
                  )}
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      <VacancyDetail vacancy={viewing} onClose={() => setViewing(null)} />

      {formOpen && (
        <VacancyForm
          vacancy={editing}
          onClose={() => {
            setFormOpen(false);
            setEditing(null);
          }}
        />
      )}

      <ConfirmDialog
        open={pendingDelete !== null}
        onOpenChange={(open) => {
          if (!open) setPendingDelete(null);
        }}
        title={t("vacancies.confirm_delete_title", "Delete vacancy?")}
        description={t(
          "vacancies.confirm_delete_desc",
          "It disappears for employees and from your sign-in page. Close it instead to keep a record.",
        )}
        confirmLabel={t("common.delete", "Delete")}
        variant="destructive"
        loading={deleteVacancy.isPending}
        onConfirm={() => pendingDelete && remove(pendingDelete)}
      />
    </div>
  );
}

function VacancyDetail({
  vacancy,
  onClose,
}: {
  vacancy: Vacancy | null;
  onClose: () => void;
}) {
  const { t } = useT();
  const { formatDate } = useDateFormatters();

  return (
    <Dialog open={vacancy !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-lg">
        {vacancy && (
          <>
            <DialogHeader>
              <DialogTitle>{vacancy.title}</DialogTitle>
              <DialogDescription>
                {[
                  t(
                    `vacancies.type_${vacancy.employment_type}`,
                    vacancy.employment_type,
                  ),
                  vacancy.department?.name,
                ]
                  .filter(Boolean)
                  .join(" · ")}
              </DialogDescription>
            </DialogHeader>
            <div className="space-y-4 text-sm">
              <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                {(vacancy.location || vacancy.branch) && (
                  <span className="inline-flex items-center gap-1">
                    <MapPin className="h-3.5 w-3.5" aria-hidden="true" />
                    {vacancy.location ?? vacancy.branch?.name}
                  </span>
                )}
                <span className="inline-flex items-center gap-1">
                  <Users className="h-3.5 w-3.5" aria-hidden="true" />
                  {t("vacancies.positions_count", ":count position(s)", {
                    count: vacancy.positions,
                  })}
                </span>
                <span className="inline-flex items-center gap-1">
                  <CalendarClock className="h-3.5 w-3.5" aria-hidden="true" />
                  {vacancy.closes_on
                    ? t("vacancies.closes_on", "Closes :date", {
                        date: formatDate(vacancy.closes_on),
                      })
                    : t("vacancies.open_until_filled", "Open until filled")}
                </span>
              </div>
              <p className="whitespace-pre-line">{vacancy.description}</p>
              {vacancy.requirements && (
                <div>
                  <h3 className="mb-1 font-semibold">
                    {t("vacancies.requirements", "Requirements")}
                  </h3>
                  <p className="whitespace-pre-line text-muted-foreground">
                    {vacancy.requirements}
                  </p>
                </div>
              )}
              {(vacancy.apply_url || vacancy.apply_email) && (
                <div className="flex flex-wrap gap-2 border-t pt-4">
                  {vacancy.apply_url && (
                    <Button asChild size="sm">
                      <a
                        href={vacancy.apply_url}
                        target="_blank"
                        rel="noopener noreferrer nofollow"
                      >
                        <ExternalLink className="mr-2 h-3.5 w-3.5" />
                        {t("vacancies.apply_online", "Apply online")}
                      </a>
                    </Button>
                  )}
                  {vacancy.apply_email && (
                    <Button asChild size="sm" variant="outline">
                      <a
                        href={`mailto:${vacancy.apply_email}?subject=${encodeURIComponent(vacancy.title)}`}
                      >
                        <Mail className="mr-2 h-3.5 w-3.5" />
                        {t("vacancies.apply_email", "Apply by e-mail")}
                      </a>
                    </Button>
                  )}
                </div>
              )}
            </div>
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}

function fromVacancy(v: Vacancy): FormState {
  return {
    title: v.title,
    employment_type: v.employment_type as EmploymentType,
    description: v.description,
    requirements: v.requirements ?? "",
    location: v.location ?? "",
    positions: String(v.positions),
    visibility: v.visibility as VacancyVisibility,
    status: v.status as VacancyStatus,
    closes_on: v.closes_on ?? "",
    apply_email: v.apply_email ?? "",
    apply_url: v.apply_url ?? "",
    department_id: v.department?.public_id ?? NONE,
    branch_id: v.branch?.public_id ?? NONE,
  };
}

function VacancyForm({
  vacancy,
  onClose,
}: {
  vacancy: Vacancy | null;
  onClose: () => void;
}) {
  const { t } = useT();
  const createVacancy = useCreateVacancy();
  const updateVacancy = useUpdateVacancy();
  const { data: departments } = departmentsApi.useList();
  const { data: branches } = branchesApi.useList();
  const [form, setForm] = useState<FormState>(
    vacancy ? fromVacancy(vacancy) : EMPTY_FORM,
  );
  const [errors, setErrors] = useState<Record<string, string>>({});
  const pending = createVacancy.isPending || updateVacancy.isPending;

  function set<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((prev) => ({ ...prev, [key]: value }));
  }

  const blank = (v: string) => (v.trim() === "" ? null : v.trim());

  function submit() {
    setErrors({});
    const payload: VacancyPayload = {
      title: form.title.trim(),
      employment_type: form.employment_type,
      description: form.description.trim(),
      requirements: blank(form.requirements),
      location: blank(form.location),
      positions: Math.max(1, Number.parseInt(form.positions, 10) || 1),
      status: form.status,
      closes_on: blank(form.closes_on),
      apply_email: blank(form.apply_email),
      apply_url: blank(form.apply_url),
      department_id: form.department_id === NONE ? null : form.department_id,
      branch_id: form.branch_id === NONE ? null : form.branch_id,
    };
    const options = {
      onSuccess: () => {
        toast.success(t("vacancies.saved", "Vacancy saved"));
        onClose();
      },
      onError: (error: unknown) => {
        const fieldErrors = (
          error as {
            response?: { data?: { errors?: Record<string, string[]> } };
          }
        ).response?.data?.errors;
        if (fieldErrors) {
          setErrors(
            Object.fromEntries(
              Object.entries(fieldErrors).map(([k, v]) => [k, v[0] ?? ""]),
            ),
          );
        }
        toast.error(t("vacancies.save_failed", "Failed to save vacancy"));
      },
    };

    if (vacancy) {
      updateVacancy.mutate({ publicId: vacancy.public_id, payload }, options);
    } else {
      createVacancy.mutate(payload, options);
    }
  }

  const fieldError = (key: string) =>
    errors[key] ? (
      <p className="mt-1 text-xs text-destructive" role="alert">
        {errors[key]}
      </p>
    ) : null;

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>
            {vacancy
              ? t("vacancies.edit", "Edit vacancy")
              : t("vacancies.new", "Post vacancy")}
          </DialogTitle>
        </DialogHeader>
        <form
          onSubmit={(e) => {
            e.preventDefault();
            submit();
          }}
          className="space-y-4"
        >
          <div>
            <Label htmlFor="vacancy-title">{t("common.title", "Title")}</Label>
            <Input
              id="vacancy-title"
              value={form.title}
              maxLength={150}
              required
              onChange={(e) => set("title", e.target.value)}
              className="mt-1"
            />
            {fieldError("title")}
          </div>

          <div className="grid gap-4 sm:grid-cols-3">
            <div>
              <Label htmlFor="vacancy-type">
                {t("vacancies.employment_type", "Employment type")}
              </Label>
              <Select
                value={form.employment_type}
                onValueChange={(v) =>
                  set("employment_type", v as EmploymentType)
                }
              >
                <SelectTrigger id="vacancy-type" className="mt-1">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {EMPLOYMENT_TYPES.map((type) => (
                    <SelectItem key={type} value={type}>
                      {t(`vacancies.type_${type}`, type)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label htmlFor="vacancy-department">
                {t("common.department", "Department")}
              </Label>
              <Select
                value={form.department_id}
                onValueChange={(v) => set("department_id", v)}
              >
                <SelectTrigger id="vacancy-department" className="mt-1">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={NONE}>
                    {t("vacancies.any", "Not specified")}
                  </SelectItem>
                  {departments?.data.map((d) => (
                    <SelectItem key={d.public_id} value={d.public_id}>
                      {d.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {fieldError("department_id")}
            </div>
            <div>
              <Label htmlFor="vacancy-branch">
                {t("common.branch", "Branch")}
              </Label>
              <Select
                value={form.branch_id}
                onValueChange={(v) => set("branch_id", v)}
              >
                <SelectTrigger id="vacancy-branch" className="mt-1">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={NONE}>
                    {t("vacancies.any", "Not specified")}
                  </SelectItem>
                  {branches?.data.map((b) => (
                    <SelectItem key={b.public_id} value={b.public_id}>
                      {b.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {fieldError("branch_id")}
            </div>
          </div>

          <div>
            <Label htmlFor="vacancy-description">
              {t("vacancies.role_description", "About the role")}
            </Label>
            <Textarea
              id="vacancy-description"
              value={form.description}
              maxLength={5000}
              required
              rows={5}
              onChange={(e) => set("description", e.target.value)}
              className="mt-1"
            />
            {fieldError("description")}
          </div>
          <div>
            <Label htmlFor="vacancy-requirements">
              {t("vacancies.requirements", "Requirements")}
            </Label>
            <Textarea
              id="vacancy-requirements"
              value={form.requirements}
              maxLength={5000}
              rows={3}
              onChange={(e) => set("requirements", e.target.value)}
              className="mt-1"
            />
          </div>

          <div className="grid gap-4 sm:grid-cols-3">
            <div>
              <Label htmlFor="vacancy-location">
                {t("vacancies.location", "Location")}
              </Label>
              <Input
                id="vacancy-location"
                value={form.location}
                maxLength={120}
                onChange={(e) => set("location", e.target.value)}
                className="mt-1"
              />
            </div>
            <div>
              <Label htmlFor="vacancy-positions">
                {t("vacancies.positions", "Positions")}
              </Label>
              <Input
                id="vacancy-positions"
                type="number"
                min={1}
                max={999}
                value={form.positions}
                onChange={(e) => set("positions", e.target.value)}
                className="mt-1"
              />
              {fieldError("positions")}
            </div>
            <div>
              <Label htmlFor="vacancy-closes">
                {t("vacancies.closes_label", "Applications close")}
              </Label>
              <Input
                id="vacancy-closes"
                type="date"
                value={form.closes_on}
                onChange={(e) => set("closes_on", e.target.value)}
                className="mt-1"
              />
              {fieldError("closes_on")}
            </div>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <Label htmlFor="vacancy-apply-email">
                {t("vacancies.apply_email_label", "Apply by e-mail to")}
              </Label>
              <Input
                id="vacancy-apply-email"
                type="email"
                value={form.apply_email}
                onChange={(e) => set("apply_email", e.target.value)}
                className="mt-1"
              />
              {fieldError("apply_email")}
            </div>
            <div>
              <Label htmlFor="vacancy-apply-url">
                {t("vacancies.apply_url_label", "Or apply online at (https)")}
              </Label>
              <Input
                id="vacancy-apply-url"
                type="url"
                inputMode="url"
                pattern="https://.*"
                value={form.apply_url}
                onChange={(e) => set("apply_url", e.target.value)}
                className="mt-1"
              />
              {fieldError("apply_url")}
            </div>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <Label htmlFor="vacancy-status">
                {t("common.status", "Status")}
              </Label>
              <Select
                value={form.status}
                onValueChange={(v) => set("status", v as VacancyStatus)}
              >
                <SelectTrigger id="vacancy-status" className="mt-1">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="draft">
                    {t("vacancies.status_draft", "Draft")}
                  </SelectItem>
                  <SelectItem value="open">
                    {t("vacancies.status_open", "Open")}
                  </SelectItem>
                  <SelectItem value="closed">
                    {t("vacancies.status_closed", "Closed")}
                  </SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              {t("common.cancel", "Cancel")}
            </Button>
            <Button type="submit" disabled={pending}>
              {pending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              {t("vacancies.save", "Save vacancy")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
