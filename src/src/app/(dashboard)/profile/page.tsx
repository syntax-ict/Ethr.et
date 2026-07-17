"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import {
  User,
  Mail,
  Phone,
  Shield,
  Briefcase,
  Building2,
  Calendar,
  Edit,
  Save,
  X,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { PageHeader } from "@/components/shared/page-header";
import { useCurrentUser, useCurrentTenant } from "@/features/auth/api";
import {
  useMyProfile,
  useUpdateProfile,
} from "@/features/dashboard/profile-api";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

const editSchema = z.object({
  phone: z.string().max(20).optional(),
  emergency_contact_name: z.string().max(255).optional(),
  emergency_contact_phone: z.string().max(20).optional(),
  emergency_contact_relationship: z.string().max(100).optional(),
});
type EditForm = z.infer<typeof editSchema>;

export default function ProfilePage() {
  const { t } = useT();
  const [editing, setEditing] = useState(false);
  const { data: user, isLoading: userLoading } = useCurrentUser();
  const { data: tenant } = useCurrentTenant();
  const { data: profile, isLoading: profileLoading } = useMyProfile();
  const updateProfile = useUpdateProfile();

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<EditForm>({
    resolver: zodResolver(editSchema),
    defaultValues: {
      phone: profile?.employee?.phone ?? profile?.user.phone ?? "",
    },
  });

  const employee = profile?.employee;

  async function onSave(values: EditForm) {
    try {
      const result = await updateProfile.mutateAsync(values);
      if (result.pending_approval) {
        toast.warning(
          t("profile.pending_approval", "Changes are pending HR approval."),
        );
      } else {
        toast.success(t("profile.updated", "Profile updated successfully."));
      }
      setEditing(false);
    } catch {
      toast.error(
        t(
          "profile.update_failed",
          "Failed to update profile. Please try again.",
        ),
      );
    }
  }

  if (userLoading || profileLoading || !user) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  const displayName = employee?.name ?? user.email.split("@")[0];
  const initials = displayName.slice(0, 2).toUpperCase();

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("profile.title", "My Profile")}
        description={t(
          "profile.description",
          "View and update your personal information",
        )}
      />

      <Card>
        <CardContent className="p-6">
          <div className="flex flex-col items-center gap-4 sm:flex-row sm:items-start">
            <Avatar className="h-20 w-20">
              <AvatarFallback className="text-xl font-semibold">
                {initials}
              </AvatarFallback>
            </Avatar>
            <div className="flex-1 text-center sm:text-left">
              <h2 className="text-xl font-bold text-foreground">
                {displayName}
              </h2>
              <p className="text-sm text-muted-foreground">{user.email}</p>
              <div className="mt-2 flex flex-wrap items-center justify-center gap-2 sm:justify-start">
                <Badge variant="outline" className="capitalize">
                  {user.role?.replace(/_/g, " ")}
                </Badge>
                <Badge variant="outline" className="capitalize">
                  {user.status}
                </Badge>
                {user.mfa_enabled && (
                  <Badge className="bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300 border-0">
                    {t("profile.mfa_enabled", "MFA Enabled")}
                  </Badge>
                )}
              </div>
            </div>
            {!editing && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => {
                  reset({ phone: employee?.phone ?? user.phone ?? "" });
                  setEditing(true);
                }}
              >
                <Edit className="mr-1 h-3 w-3" />
                {t("common.edit", "Edit")}
              </Button>
            )}
          </div>
        </CardContent>
      </Card>

      {editing ? (
        <form onSubmit={handleSubmit(onSave)}>
          <Card>
            <CardHeader>
              <CardTitle className="text-base">
                {t("profile.edit_title", "Edit Profile")}
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <Label htmlFor="phone">
                    {t("profile.phone_number", "Phone Number")}
                  </Label>
                  <Input
                    id="phone"
                    placeholder="+251 9XX XXX XXX"
                    {...register("phone")}
                  />
                  {errors.phone && (
                    <p className="text-xs text-destructive">
                      {errors.phone.message}
                    </p>
                  )}
                </div>
              </div>

              <div className="grid gap-4 sm:grid-cols-3">
                <div className="space-y-2">
                  <Label htmlFor="ec_name">
                    {t("profile.ec_name", "Emergency Contact Name")}
                  </Label>
                  <Input
                    id="ec_name"
                    placeholder="Full name"
                    {...register("emergency_contact_name")}
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="ec_phone">
                    {t("profile.ec_phone", "Emergency Contact Phone")}
                  </Label>
                  <Input
                    id="ec_phone"
                    placeholder="+251 9XX XXX XXX"
                    {...register("emergency_contact_phone")}
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="ec_rel">
                    {t("employee.emergency.relationship", "Relationship")}
                  </Label>
                  <Input
                    id="ec_rel"
                    placeholder="e.g. Spouse, Parent"
                    {...register("emergency_contact_relationship")}
                  />
                </div>
              </div>

              <p className="text-xs text-muted-foreground">
                {t(
                  "profile.hr_approval_note",
                  "Changes to name or bank details require HR approval before taking effect.",
                )}
              </p>

              <div className="flex gap-2">
                <Button
                  type="submit"
                  size="sm"
                  disabled={updateProfile.isPending}
                >
                  <Save className="mr-1 h-3 w-3" />
                  {updateProfile.isPending
                    ? t("profile.saving", "Saving...")
                    : t("profile.save_changes", "Save Changes")}
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => setEditing(false)}
                >
                  <X className="mr-1 h-3 w-3" />
                  {t("common.cancel", "Cancel")}
                </Button>
              </div>
            </CardContent>
          </Card>
        </form>
      ) : (
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
              {(user.phone ?? employee?.phone) && (
                <InfoRow
                  icon={Phone}
                  label={t("common.phone", "Phone")}
                  value={user.phone ?? employee?.phone ?? ""}
                />
              )}
              <InfoRow
                icon={Shield}
                label={t("profile.role", "Role")}
                value={user.role?.replace(/_/g, " ") ?? ""}
                className="capitalize"
              />
              <InfoRow
                icon={Calendar}
                label={t("profile.locale", "Locale")}
                value={user.locale ?? "en"}
              />
              {user.last_login_at && (
                <InfoRow
                  icon={Calendar}
                  label={t("profile.last_login", "Last Login")}
                  value={new Date(user.last_login_at).toLocaleString()}
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
              </CardContent>
            </Card>
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
    <div className="flex items-center justify-between rounded-lg border p-3">
      <div className="flex items-center gap-2">
        <Icon className="h-4 w-4 text-muted-foreground" />
        <span className="text-sm text-muted-foreground">{label}</span>
      </div>
      <span
        className={`text-sm font-medium text-foreground ${className ?? ""}`}
      >
        {value}
      </span>
    </div>
  );
}
