"use client";

import { useState } from "react";
import Link from "next/link";
import { Smartphone } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { PaginationControls } from "@/components/shared/pagination-controls";
import { ReasonDialog } from "@/components/shared/reason-dialog";
import { RoleGate } from "@/components/shared/role-gate";
import {
  useAttendanceDevices,
  useDecideDevice,
  useExemptFromPhone,
  useUnregisteredEmployees,
  type AttendanceDeviceInfo,
  type UnregisteredEmployee,
} from "@/features/attendance/api";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";

const FILTERS = ["pending", "missing", "active", "closed"] as const;
type Filter = (typeof FILTERS)[number];

/** Who an exemption or a refusal is about. */
interface Person {
  publicId: string;
  name: string;
}

/**
 * The phones people punch with (registered attendance devices): how many
 * people have one, who still has none, phones waiting for approval, and
 * revoking a lost one. Refusing or revoking asks why, and the employee is
 * told; a waiting replacement shows the phone it would stop.
 */
export default function AttendanceDevicesPage() {
  const { t } = useT();
  const [filter, setFilter] = useState<Filter>("pending");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [refusing, setRefusing] = useState<{
    device: AttendanceDeviceInfo;
    action: "reject" | "revoke";
  } | null>(null);
  const [exempting, setExempting] = useState<Person | null>(null);

  const listingPeople = filter === "missing";
  const devices = useAttendanceDevices({
    status: listingPeople ? "pending" : filter,
    search: search || undefined,
    page: listingPeople ? 1 : page,
  });
  const people = useUnregisteredEmployees(
    { search: search || undefined, page },
    listingPeople,
  );
  const decide = useDecideDevice();
  const coverage = devices.data?.coverage;

  const filterLabel: Record<Filter, string> = {
    pending: t("devices.filter_pending", "Waiting"),
    missing: t("devices.filter_missing", "Not registered"),
    active: t("devices.filter_active", "Registered"),
    closed: t("devices.filter_closed", "Revoked or refused"),
  };

  function choose(next: Filter) {
    setFilter(next);
    setPage(1);
  }

  function act(
    device: AttendanceDeviceInfo,
    action: "approve" | "reject" | "revoke",
    reason?: string,
  ) {
    decide.mutate(
      { publicId: device.public_id, action, reason },
      {
        onSuccess: () => {
          setRefusing(null);
          toast.success(
            action === "approve"
              ? t("devices.approved", "Phone approved.")
              : action === "reject"
                ? t("devices.rejected", "Request rejected.")
                : t("devices.revoked", "Phone revoked."),
          );
        },
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t("devices.failed", "Could not change the phone."),
            ),
          ),
      },
    );
  }

  const active = listingPeople ? people : devices;

  return (
    <RoleGate anyPermission={["manageAttendance"]}>
      <div className="space-y-4">
        <p className="max-w-2xl text-sm text-muted-foreground">
          {t(
            "devices.page_description",
            "The phone each person punches with. A phone registered away from work, or replacing another, waits here for approval. Revoke a phone that is lost or sold.",
          )}
        </p>

        {coverage && (
          <div className="grid gap-3 sm:grid-cols-3">
            <Stat
              label={t("devices.coverage_registered", "People with a phone")}
              value={`${coverage.registered} / ${coverage.employees}`}
            />
            <Stat
              label={t("devices.coverage_pending", "Waiting for approval")}
              value={String(coverage.pending)}
              onClick={() => choose("pending")}
            />
            <Stat
              label={t("devices.coverage_missing", "Still to register")}
              value={String(coverage.missing)}
              hint={
                coverage.checked === 0
                  ? t(
                      "devices.coverage_not_checked",
                      "Phones are not checked yet. Turn it on in attendance settings.",
                    )
                  : t(
                      "devices.coverage_checked",
                      "Of :count people whose phone is checked",
                      { count: coverage.checked },
                    )
              }
              onClick={() => choose("missing")}
            />
          </div>
        )}

        <div className="flex flex-wrap items-center gap-2">
          {FILTERS.map((f) => (
            <Button
              key={f}
              size="sm"
              variant={filter === f ? "default" : "outline"}
              onClick={() => choose(f)}
            >
              {filterLabel[f]}
            </Button>
          ))}
          <Input
            className="ml-auto w-full sm:w-64"
            placeholder={t("devices.search", "Search by name or code")}
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
          />
        </div>

        {active.isError ? (
          <ErrorState onRetry={() => active.refetch()} />
        ) : active.isLoading ? (
          <Skeleton className="h-40" />
        ) : listingPeople ? (
          (people.data?.data ?? []).length === 0 ? (
            <EmptyState
              icon={Smartphone}
              title={t("devices.empty_missing", "Everyone checked has a phone")}
            />
          ) : (
            <Card>
              <CardContent className="divide-y divide-border p-0">
                {(people.data?.data ?? []).map((person) => (
                  <UnregisteredRow
                    key={person.public_id}
                    person={person}
                    onExempt={() =>
                      setExempting({
                        publicId: person.public_id,
                        name: person.name,
                      })
                    }
                  />
                ))}
              </CardContent>
            </Card>
          )
        ) : (devices.data?.data ?? []).length === 0 ? (
          <EmptyState
            icon={Smartphone}
            title={t("devices.empty", "No phones here")}
          />
        ) : (
          <Card>
            <CardContent className="divide-y divide-border p-0">
              {(devices.data?.data ?? []).map((d) => (
                <DeviceRow
                  key={d.public_id}
                  device={d}
                  busy={decide.isPending}
                  onApprove={() => act(d, "approve")}
                  onReject={() => setRefusing({ device: d, action: "reject" })}
                  onRevoke={() => setRefusing({ device: d, action: "revoke" })}
                  onExempt={
                    d.employee
                      ? () =>
                          setExempting({
                            publicId: d.employee!.public_id,
                            name: d.employee!.name,
                          })
                      : undefined
                  }
                />
              ))}
            </CardContent>
          </Card>
        )}

        <PaginationControls meta={active.data?.meta} onPageChange={setPage} />

        <ReasonDialog
          open={refusing !== null}
          onOpenChange={(open) => !open && setRefusing(null)}
          title={
            refusing?.action === "revoke"
              ? t("devices.revoke_title", "Revoke this phone")
              : t("devices.reject_title", "Refuse this phone")
          }
          description={
            refusing?.action === "revoke"
              ? t(
                  "devices.revoke_description",
                  ":name can no longer punch with this phone until they register one again. They are told why.",
                  { name: refusing?.device.employee?.name ?? "" },
                )
              : t(
                  "devices.reject_description",
                  ":name keeps the phone they have, if any. They are told why.",
                  { name: refusing?.device.employee?.name ?? "" },
                )
          }
          label={t("devices.reason", "Reason")}
          placeholder={t(
            "devices.reason_placeholder",
            "For example: reported lost, or not their own phone",
          )}
          maxLength={300}
          confirmLabel={
            refusing?.action === "revoke"
              ? t("devices.revoke", "Revoke")
              : t("devices.reject", "Reject")
          }
          loading={decide.isPending}
          onConfirm={(reason) =>
            refusing && act(refusing.device, refusing.action, reason)
          }
        />

        <ExemptDialog person={exempting} onClose={() => setExempting(null)} />
      </div>
    </RoleGate>
  );
}

function DeviceRow({
  device: d,
  busy,
  onApprove,
  onReject,
  onRevoke,
  onExempt,
}: {
  device: AttendanceDeviceInfo;
  busy: boolean;
  onApprove: () => void;
  onReject: () => void;
  onRevoke: () => void;
  onExempt?: () => void;
}) {
  const { t } = useT();
  const { formatDateTime, timeAgo } = useDateFormatters();
  const phone = (label: string | null) =>
    label ?? t("devices.unnamed", "Phone");

  return (
    <div className="flex flex-wrap items-start justify-between gap-3 p-4">
      <div className="min-w-0 space-y-1">
        <p className="text-sm font-medium">
          {d.employee ? (
            <Link
              href={`/employees/${d.employee.public_id}`}
              className="hover:underline"
            >
              {d.employee.name}
            </Link>
          ) : (
            t("devices.unknown_person", "Unknown")
          )}
          {d.employee?.employee_code ? ` (${d.employee.employee_code})` : ""}
        </p>
        <p className="text-xs text-muted-foreground">
          {phone(d.label)}
          {d.created_at
            ? ` · ${t("devices.registered_on", "registered :when", { when: formatDateTime(d.created_at) })}`
            : ""}
          {d.status === "active"
            ? ` · ${
                d.last_used_at
                  ? t("devices.last_used", "last punched :when", {
                      when: timeAgo(d.last_used_at),
                    })
                  : t("devices.never_used", "not used to punch yet")
              }`
            : ""}
          {d.status !== "active" && d.status !== "pending" && d.revoked_at
            ? ` · ${t("devices.closed_on", "closed :when", { when: formatDateTime(d.revoked_at) })}`
            : ""}
        </p>
        <div className="flex flex-wrap gap-1">
          {d.status === "pending" && !d.enrolled_inside_geofence && (
            <Badge variant="warning">
              {t("devices.away_from_work", "Registered away from work")}
            </Badge>
          )}
          {d.synced_passkey && (
            <Badge
              variant="neutral"
              title={t(
                "devices.synced_hint",
                "The passkey is copied to the person's other devices through their Apple or Google account, so it can sign from those too.",
              )}
            >
              {t("devices.synced", "Synced passkey")}
            </Badge>
          )}
          {d.status === "revoked" && (
            <Badge variant="neutral">
              {t("devices.status_revoked", "Revoked")}
            </Badge>
          )}
          {d.status === "rejected" && (
            <Badge variant="neutral">
              {t("devices.status_rejected", "Refused")}
            </Badge>
          )}
        </div>
        {d.status === "pending" && d.replaces && (
          <p className="rounded-md border border-border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
            {t(
              "devices.replaces",
              "Replaces :phone, :used. Approving stops that phone.",
              {
                phone: phone(d.replaces.label),
                used: d.replaces.last_used_at
                  ? t("devices.last_used", "last punched :when", {
                      when: timeAgo(d.replaces.last_used_at),
                    })
                  : t("devices.never_used", "not used to punch yet"),
              },
            )}
          </p>
        )}
      </div>
      <div className="flex flex-wrap gap-2">
        {d.status === "pending" ? (
          <>
            <Button size="sm" disabled={busy} onClick={onApprove}>
              {t("devices.approve", "Approve")}
            </Button>
            <Button
              size="sm"
              variant="outline"
              disabled={busy}
              onClick={onReject}
            >
              {t("devices.reject", "Reject")}
            </Button>
          </>
        ) : d.status === "active" ? (
          <>
            {onExempt && (
              <Button size="sm" variant="ghost" onClick={onExempt}>
                {t("devices.exempt", "Punch without a phone")}
              </Button>
            )}
            <Button
              size="sm"
              variant="outline"
              disabled={busy}
              onClick={onRevoke}
            >
              {t("devices.revoke", "Revoke")}
            </Button>
          </>
        ) : null}
      </div>
    </div>
  );
}

function UnregisteredRow({
  person,
  onExempt,
}: {
  person: UnregisteredEmployee;
  onExempt: () => void;
}) {
  const { t } = useT();

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 p-4">
      <div className="min-w-0 space-y-1">
        <p className="text-sm font-medium">
          <Link
            href={`/employees/${person.public_id}`}
            className="hover:underline"
          >
            {person.name}
          </Link>
          {person.employee_code ? ` (${person.employee_code})` : ""}
        </p>
        <p className="text-xs text-muted-foreground">
          {person.branch ?? t("devices.no_branch", "No branch")}
        </p>
        {person.has_pending_phone && (
          <Badge variant="info">
            {t("devices.has_pending", "Phone waiting for approval")}
          </Badge>
        )}
      </div>
      <Button size="sm" variant="outline" onClick={onExempt}>
        {t("devices.exempt", "Punch without a phone")}
      </Button>
    </div>
  );
}

/** A date seven days from today, the usual wait for a replacement phone. */
function inAWeek(): string {
  const date = new Date();
  date.setDate(date.getDate() + 7);
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function ExemptDialog({
  person,
  onClose,
}: {
  person: Person | null;
  onClose: () => void;
}) {
  const { t } = useT();
  const exempt = useExemptFromPhone();
  const [until, setUntil] = useState(inAWeek);

  // A fresh week each time the dialog opens for someone.
  const [openFor, setOpenFor] = useState<string | null>(null);
  if ((person?.publicId ?? null) !== openFor) {
    setOpenFor(person?.publicId ?? null);
    if (person) setUntil(inAWeek());
  }

  return (
    <Dialog open={person !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {t("devices.exempt_title", "Let :name punch without a phone", {
              name: person?.name ?? "",
            })}
          </DialogTitle>
          <DialogDescription>
            {t(
              "device_exemption.hint",
              "Until this date, for a phone that cannot hold a passkey or is broken. Their punches are still sent for review.",
            )}
          </DialogDescription>
        </DialogHeader>
        <Input
          type="date"
          aria-label={t("devices.exempt_until", "Until")}
          value={until}
          onChange={(e) => setUntil(e.target.value)}
        />
        <DialogFooter>
          <Button
            variant="outline"
            onClick={onClose}
            disabled={exempt.isPending}
          >
            {t("common.cancel", "Cancel")}
          </Button>
          <Button
            disabled={exempt.isPending || until === "" || person === null}
            onClick={() =>
              person &&
              exempt.mutate(
                { publicId: person.publicId, until },
                {
                  onSuccess: () => {
                    toast.success(t("devices.exempted", "Saved."));
                    onClose();
                  },
                  onError: (err) =>
                    toast.error(
                      apiErrorMessage(
                        err,
                        t(
                          "device_exemption.failed",
                          "Couldn't change the exemption",
                        ),
                      ),
                    ),
                },
              )
            }
          >
            {t("device_exemption.save", "Save")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function Stat({
  label,
  value,
  hint,
  onClick,
}: {
  label: string;
  value: string;
  hint?: string;
  onClick?: () => void;
}) {
  const body = (
    <CardContent className="p-4">
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className="text-xl font-semibold tabular-nums">{value}</p>
      {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
    </CardContent>
  );

  return (
    <Card>
      {onClick ? (
        <button
          type="button"
          onClick={onClick}
          className="w-full rounded-[inherit] text-left transition-colors hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          {body}
        </button>
      ) : (
        body
      )}
    </Card>
  );
}
