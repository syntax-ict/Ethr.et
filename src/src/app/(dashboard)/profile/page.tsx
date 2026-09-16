"use client";

import { useRef, useState } from "react";
import Link from "next/link";
import {
  User,
  Mail,
  Phone,
  Shield,
  Briefcase,
  Building2,
  Calendar,
  Clock,
  Camera,
  Trash2,
  Loader2,
  Pencil,
  BadgeCheck,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { EmployeeAvatar } from "@/components/shared/employee-avatar";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { useCurrentTenant } from "@/features/auth/api";
import {
  useMyProfile,
  useRemoveProfilePhoto,
  useUploadProfilePhoto,
  type ProfileResponse,
} from "@/features/profile/api";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

/** Matches the server rule on POST /profile/photo. */
const MAX_PHOTO_BYTES = 5 * 1024 * 1024;
const ACCEPTED_PHOTO_TYPES = ["image/jpeg", "image/png", "image/webp"];

export default function ProfilePage() {
  const { t } = useT();
  const query = useMyProfile();

  return (
    <QueryBoundary query={query}>
      {(profile) => <ProfileOverview profile={profile} t={t} />}
    </QueryBoundary>
  );
}

function ProfileOverview({
  profile,
  t,
}: {
  profile: ProfileResponse;
  t: (key: string, fallback?: string) => string;
}) {
  const { data: tenant } = useCurrentTenant();
  const { formatDateTime } = useDateFormatters();
  const employee = profile.employee;
  const user = profile.user;
  const pendingUpdates = profile.pending_updates ?? [];
  const displayName = employee?.name ?? user.email.split("@")[0];

  return (
    <div className="space-y-6">
      <Card>
        <CardContent className="p-6">
          <div className="flex flex-col items-center gap-4 sm:flex-row sm:items-start">
            <PhotoControl
              name={displayName}
              photoThumbUrl={employee?.photo_thumb_url}
              photoUrl={employee?.photo_url}
              canEdit={Boolean(employee)}
              t={t}
            />
            <div className="flex-1 text-center sm:text-left">
              <h2 className="text-xl font-bold text-foreground">
                {displayName}
              </h2>
              {employee?.name_am && (
                <p className="text-sm text-muted-foreground">
                  {employee.name_am}
                </p>
              )}
              <p className="text-sm text-muted-foreground">{user.email}</p>
              <div className="mt-2 flex flex-wrap items-center justify-center gap-2 sm:justify-start">
                <Badge variant="outline" className="capitalize">
                  {user.role?.replace(/_/g, " ")}
                </Badge>
                <Badge variant="outline" className="capitalize">
                  {user.status}
                </Badge>
                {employee?.employee_code && (
                  <Badge variant="outline" className="font-mono">
                    {employee.employee_code}
                  </Badge>
                )}
                {user.mfa_enabled && (
                  <Badge className="bg-success-soft text-success-on-soft border-0">
                    {t("profile.mfa_enabled", "MFA Enabled")}
                  </Badge>
                )}
              </div>
            </div>
            <Button variant="outline" size="sm" asChild>
              <Link href="/profile/personal">
                <Pencil className="mr-1 h-3 w-3" />
                {t("common.edit", "Edit")}
              </Link>
            </Button>
          </div>
        </CardContent>
      </Card>

      {pendingUpdates.length > 0 && (
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0">
            <CardTitle className="flex items-center gap-2 text-base">
              <Clock className="h-4 w-4 text-status-warning" />
              {t("profile.pending_updates_title", "Changes awaiting HR review")}
            </CardTitle>
            <Button variant="ghost" size="sm" asChild>
              <Link href="/profile/requests">
                {t("common.view_all", "View all")}
              </Link>
            </Button>
          </CardHeader>
          <CardContent className="space-y-3">
            {pendingUpdates.map((update) => (
              <div
                key={update.public_id}
                className="flex flex-col gap-1 rounded-md border border-border-default bg-surface-secondary p-3 sm:flex-row sm:items-center sm:justify-between"
              >
                <div>
                  <p className="text-sm font-medium capitalize text-foreground">
                    {update.field_name.replace(/_/g, " ")}
                  </p>
                  <p className="text-sm text-muted-foreground">
                    <span className="line-through">
                      {update.old_value ?? t("common.not_set", "Not set")}
                    </span>{" "}
                    → <span className="font-medium">{update.new_value}</span>
                  </p>
                </div>
                <Badge className="w-fit bg-warning-soft text-warning-on-soft border-0">
                  {t("profile.awaiting_review", "Awaiting review")}
                </Badge>
              </div>
            ))}
          </CardContent>
        </Card>
      )}

      <div className="grid gap-6 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              {t("profile.account_info", "Account Information")}
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <InfoRow
              icon={Mail}
              label={t("common.email", "Email")}
              value={user.email}
            />
            <InfoRow
              icon={Phone}
              label={t("common.phone", "Phone")}
              value={user.phone ?? employee?.phone ?? "—"}
            />
            <InfoRow
              icon={Shield}
              label={t("profile.role", "Role")}
              value={user.role?.replace(/_/g, " ") ?? "—"}
              className="capitalize"
            />
            <InfoRow
              icon={BadgeCheck}
              label={t("profile.two_factor", "Two-factor")}
              value={
                user.mfa_enabled
                  ? t("security_page.enabled", "Enabled")
                  : t("profile.not_enabled", "Not enabled")
              }
            />
            {user.last_login_at && (
              <InfoRow
                icon={Calendar}
                label={t("profile.last_login", "Last Login")}
                value={formatDateTime(user.last_login_at)}
              />
            )}
          </CardContent>
        </Card>

        {tenant && (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">
                {t("employee.detail.organization", "Organization")}
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <InfoRow
                icon={Building2}
                label={t("employee.detail.organization", "Organization")}
                value={tenant.name ?? ""}
              />
              <InfoRow
                icon={Building2}
                label={t("profile.subdomain", "Subdomain")}
                value={tenant.subdomain ?? ""}
              />
              <InfoRow
                icon={Shield}
                label={t("common.status", "Status")}
                value={tenant.status ?? ""}
                className="capitalize"
              />
            </CardContent>
          </Card>
        )}

        {employee && (
          <Card className="md:col-span-2">
            <CardHeader>
              <CardTitle className="text-base">
                {t("profile.employment_details", "Employment Details")}
              </CardTitle>
            </CardHeader>
            <CardContent className="grid gap-4 sm:grid-cols-2">
              <InfoRow
                icon={Calendar}
                label={t("employee.detail.hire_date", "Hire Date")}
                value={employee.hire_date ?? "—"}
              />
              <InfoRow
                icon={User}
                label={t("employee.detail.gender", "Gender")}
                value={employee.gender ?? "—"}
                className="capitalize"
              />
              <InfoRow
                icon={Building2}
                label={t("common.department", "Department")}
                value={employee.department ?? "—"}
              />
              <InfoRow
                icon={Briefcase}
                label={t("common.position", "Position")}
                value={employee.position ?? "—"}
              />
              <InfoRow
                icon={Building2}
                label={t("employee.detail.branch", "Branch")}
                value={employee.branch ?? "—"}
              />
              <InfoRow
                icon={Building2}
                label={t("profile.grade", "Grade")}
                value={employee.grade ?? "—"}
              />
              <InfoRow
                icon={User}
                label={t("profile.supervisor", "Supervisor")}
                value={employee.supervisor ?? "—"}
              />
              <InfoRow
                icon={BadgeCheck}
                label={t("common.status", "Status")}
                value={employee.status ?? "—"}
                className="capitalize"
              />
            </CardContent>
          </Card>
        )}
      </div>
    </div>
  );
}

/**
 * Avatar with upload and removal.
 *
 * The photo used to be a field on `PUT /profile` that no browser could ever
 * populate — multipart bodies do not survive a PUT — so there was no way for an
 * employee to change their own picture.
 */
function PhotoControl({
  name,
  photoThumbUrl,
  photoUrl,
  canEdit,
  t,
}: {
  name: string;
  photoThumbUrl?: string | null;
  photoUrl?: string | null;
  canEdit: boolean;
  t: (key: string, fallback?: string) => string;
}) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const upload = useUploadProfilePhoto();
  const remove = useRemoveProfilePhoto();
  const busy = upload.isPending || remove.isPending;

  async function onPick(file: File | undefined) {
    if (!file) return;

    if (!ACCEPTED_PHOTO_TYPES.includes(file.type)) {
      toast.error(
        t("profile.photo_type_error", "Choose a JPG, PNG or WebP image."),
      );
      return;
    }
    if (file.size > MAX_PHOTO_BYTES) {
      toast.error(t("profile.photo_size_error", "Photos must be under 5 MB."));
      return;
    }

    const objectUrl = URL.createObjectURL(file);
    setPreview(objectUrl);

    try {
      await upload.mutateAsync(file);
      toast.success(t("profile.photo_updated", "Photo updated."));
    } catch {
      toast.error(t("profile.photo_failed", "Couldn't upload that photo."));
      setPreview(null);
    } finally {
      URL.revokeObjectURL(objectUrl);
      if (inputRef.current) inputRef.current.value = "";
    }
  }

  async function onRemove() {
    try {
      await remove.mutateAsync();
      setPreview(null);
      toast.success(t("profile.photo_removed", "Photo removed."));
    } catch {
      toast.error(t("profile.photo_failed", "Couldn't upload that photo."));
    }
  }

  const hasPhoto = Boolean(preview ?? photoThumbUrl ?? photoUrl);

  return (
    <div className="flex flex-col items-center gap-2">
      <div className="relative">
        <EmployeeAvatar
          name={name}
          photoThumbUrl={preview ?? photoThumbUrl}
          photoUrl={preview ?? photoUrl}
          className="h-20 w-20 shadow-sm"
          fallbackClassName="bg-primary-soft text-xl font-semibold text-primary-on-soft"
        />
        {busy && (
          <div className="absolute inset-0 flex items-center justify-center rounded-full bg-surface-primary/70">
            <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
          </div>
        )}
      </div>

      {canEdit && (
        <div className="flex items-center gap-1">
          <input
            ref={inputRef}
            type="file"
            accept={ACCEPTED_PHOTO_TYPES.join(",")}
            className="sr-only"
            aria-label={t("profile.change_photo", "Change photo")}
            onChange={(e) => void onPick(e.target.files?.[0])}
          />
          <Button
            type="button"
            variant="ghost"
            size="sm"
            disabled={busy}
            onClick={() => inputRef.current?.click()}
          >
            <Camera className="mr-1 h-3 w-3" />
            {t("profile.change_photo", "Change photo")}
          </Button>
          {hasPhoto && (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              disabled={busy}
              aria-label={t("profile.remove_photo", "Remove photo")}
              onClick={() => void onRemove()}
            >
              <Trash2 className="h-3 w-3 text-destructive" />
            </Button>
          )}
        </div>
      )}
    </div>
  );
}

function InfoRow({
  icon: Icon,
  label,
  value,
  className,
}: {
  icon: React.ComponentType<{ className?: string }>;
  label: string;
  value: string;
  className?: string;
}) {
  return (
    <div className="flex items-center justify-between rounded-lg border border-border/60 px-4 py-3">
      <div className="flex items-center gap-2.5">
        <Icon className="h-4 w-4 text-muted-foreground/70" />
        <span className="text-sm text-muted-foreground">{label}</span>
      </div>
      <span
        className={`text-sm font-medium tabular-nums text-foreground ${className ?? ""}`}
      >
        {value}
      </span>
    </div>
  );
}
