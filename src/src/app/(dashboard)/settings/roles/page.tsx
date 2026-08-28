"use client";

import { useCallback, useMemo, useState } from "react";
import {
  ShieldCheck,
  Plus,
  Loader2,
  Pencil,
  Trash2,
  Users,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  DialogDescription,
} from "@/components/ui/dialog";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { RoleGate } from "@/components/shared/role-gate";
import {
  useCustomRoles,
  usePermissions,
  useCreateCustomRole,
  useUpdateCustomRole,
  useDeleteCustomRole,
  type CustomRole,
} from "@/features/roles/api";
import { FormField } from "@/components/patterns/FormField";
import { FormErrorSummary } from "@/components/patterns/FormErrorSummary";
import { useT } from "@/lib/i18n/useT";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { rules, fieldMessage } from "@/lib/forms/rules";
import { toast } from "sonner";
import { toastError } from "@/lib/errors";
import { z } from "zod";

const roleSchema = z.object({
  name: rules.requiredText(255),
  description: rules.text(500),
  // A role granting nothing can be assigned and locks its holder out of every
  // screen. The server rejects it; this says so against the permission tree
  // instead of in a toast that names no control.
  permissions: z.array(z.string()).min(1, "roles_page.select_at_least_one"),
});
type RoleValues = z.infer<typeof roleSchema>;

const MODULE_LABEL_KEYS: Record<string, string> = {
  org: "roles_page.module_org",
  employee: "roles_page.module_employee",
  attendance: "roles_page.module_attendance",
  shift: "roles_page.module_shift",
  device: "roles_page.module_device",
  correction: "roles_page.module_correction",
  holiday: "roles_page.module_holiday",
  leave: "roles_page.module_leave",
  payroll: "roles_page.module_payroll",
  report: "roles_page.module_report",
  announcement: "roles_page.module_announcement",
  profile: "roles_page.module_profile",
  dashboard: "roles_page.module_dashboard",
  apikey: "roles_page.module_apikey",
  webhook: "roles_page.module_webhook",
  billing: "roles_page.module_billing",
  settings: "roles_page.module_settings",
};

export default function RolesPage() {
  const { t } = useT();
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editingRole, setEditingRole] = useState<CustomRole | null>(null);

  const { data, isLoading } = useCustomRoles({ per_page: 100 });
  const roles = data?.data ?? [];

  function openCreate() {
    setEditingRole(null);
    setDialogOpen(true);
  }

  function openEdit(role: CustomRole) {
    setEditingRole(role);
    setDialogOpen(true);
  }

  function handleClose() {
    setDialogOpen(false);
    setEditingRole(null);
  }

  return (
    <RoleGate minRole="tenant_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("roles_page.title")}
          description={t("roles_page.description")}
          actions={
            <Button onClick={openCreate}>
              <Plus className="mr-2 h-4 w-4" /> {t("roles_page.create_role")}
            </Button>
          }
        />

        {isLoading ? (
          <div className="space-y-3">
            {Array.from({ length: 3 }).map((_, i) => (
              <Skeleton key={i} className="h-20 w-full" />
            ))}
          </div>
        ) : roles.length === 0 ? (
          <EmptyState
            icon={ShieldCheck}
            title={t("roles_page.no_roles")}
            description={t("roles_page.no_roles_desc")}
          />
        ) : (
          <div className="space-y-3">
            {roles.map((role) => (
              <RoleCard key={role.public_id} role={role} onEdit={openEdit} />
            ))}
          </div>
        )}

        <RoleDialog
          open={dialogOpen}
          onClose={handleClose}
          editingRole={editingRole}
        />
      </div>
    </RoleGate>
  );
}

function RoleCard({
  role,
  onEdit,
}: {
  role: CustomRole;
  onEdit: (role: CustomRole) => void;
}) {
  const { t } = useT();
  const deleteRole = useDeleteCustomRole();

  function handleDelete() {
    if (!confirm(`${t("roles_page.delete_confirm_prefix")} "${role.name}"?`))
      return;
    deleteRole.mutate(role.public_id, {
      onSuccess: () =>
        toast.success(
          `${t("roles_page.role_lc")} "${role.name}" ${t("roles_page.deleted")}`,
        ),
      onError: (err) => toastError(err, t("roles_page.delete_failed")),
    });
  }

  return (
    <Card>
      <CardContent className="flex items-center justify-between p-4">
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <h3 className="text-sm font-medium text-foreground">{role.name}</h3>
            {!role.is_active && (
              <Badge variant="secondary" className="text-xs">
                {t("roles_page.inactive")}
              </Badge>
            )}
          </div>
          {role.description && (
            <p className="mt-0.5 text-xs text-muted-foreground">
              {role.description}
            </p>
          )}
          <div className="mt-1.5 flex items-center gap-3 text-xs text-muted-foreground">
            <span>
              {role.permissions.length} {t("roles_page.permissions_lc")}
            </span>
            <span className="flex items-center gap-1">
              <Users className="h-3 w-3" />
              {role.users_count ?? 0} {t("roles_page.users_lc")}
            </span>
          </div>
        </div>
        <div className="flex items-center gap-1">
          <Button
            variant="ghost"
            size="sm"
            onClick={() => onEdit(role)}
            aria-label={`${t("common.edit")} ${role.name}`}
          >
            <Pencil className="h-3.5 w-3.5" />
          </Button>
          <Button
            variant="ghost"
            size="sm"
            className="text-destructive hover:text-destructive"
            onClick={handleDelete}
            disabled={deleteRole.isPending}
            aria-label={`${t("common.delete")} ${role.name}`}
          >
            <Trash2 className="h-3.5 w-3.5" />
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

function RoleDialog({
  open,
  onClose,
  editingRole,
}: {
  open: boolean;
  onClose: () => void;
  editingRole: CustomRole | null;
}) {
  const { t } = useT();

  const {
    register,
    submit,
    reset,
    watch,
    setValue,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<RoleValues>({
    schema: roleSchema,
    defaultValues: { name: "", description: "", permissions: [] },
  });

  // The permission tree toggles by name and asks "is this one on?" thousands of
  // times per render, so a Set stays the right structure for the UI. It is
  // derived from form state rather than being a second source of truth — the
  // array in the form is what validates and what gets submitted.
  const permissions = watch("permissions");
  const selected = useMemo(() => new Set(permissions), [permissions]);

  const setPermissions = useCallback(
    (next: Set<string>) => {
      setValue("permissions", Array.from(next), {
        shouldValidate: true,
        shouldDirty: true,
      });
    },
    [setValue],
  );

  const { data: permsByModule, isLoading: permsLoading } = usePermissions();

  const isEditing = !!editingRole;
  const createRole = useCreateCustomRole();
  const updateRole = useUpdateCustomRole(editingRole?.public_id ?? "");

  const resetForm = useCallback(() => {
    reset(
      editingRole
        ? {
            name: editingRole.name,
            description: editingRole.description,
            permissions: editingRole.permissions,
          }
        : { name: "", description: "", permissions: [] },
    );
  }, [editingRole, reset]);

  const handleOpenChange = useCallback(
    (isOpen: boolean) => {
      if (isOpen) {
        resetForm();
      } else {
        onClose();
      }
    },
    [resetForm, onClose],
  );

  const togglePermission = useCallback(
    (permName: string) => {
      const next = new Set(selected);
      if (next.has(permName)) {
        next.delete(permName);
      } else {
        next.add(permName);
      }
      setPermissions(next);
    },
    [selected, setPermissions],
  );

  const toggleModule = useCallback(
    (module: string) => {
      if (!permsByModule) return;
      const modulePerms = permsByModule[module] ?? [];
      const allSelected = modulePerms.every((p) => selected.has(p.name));
      const next = new Set(selected);
      for (const p of modulePerms) {
        if (allSelected) {
          next.delete(p.name);
        } else {
          next.add(p.name);
        }
      }
      setPermissions(next);
    },
    [permsByModule, selected, setPermissions],
  );

  const moduleOrder = useMemo(() => {
    if (!permsByModule) return [];
    return Object.keys(permsByModule).sort((a, b) => {
      const la = MODULE_LABEL_KEYS[a] ? t(MODULE_LABEL_KEYS[a]) : a;
      const lb = MODULE_LABEL_KEYS[b] ? t(MODULE_LABEL_KEYS[b]) : b;
      return la.localeCompare(lb);
    });
  }, [permsByModule, t]);

  // "Select at least one permission" used to be a toast fired from the submit
  // handler: it named no control, vanished after a few seconds, and left a
  // scrolled permission tree looking exactly as it did before. It is a schema
  // rule now, rendered against the Permissions group.
  async function onSubmit(values: RoleValues) {
    await (isEditing
      ? updateRole.mutateAsync(values)
      : createRole.mutateAsync(values));

    toast.success(
      `${t("roles_page.role_lc")} "${values.name}" ${
        isEditing ? t("roles_page.updated") : t("roles_page.created")
      }`,
    );
    onClose();
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="max-w-2xl max-h-[85vh] overflow-hidden flex flex-col">
        <DialogHeader>
          <DialogTitle>
            {isEditing
              ? t("roles_page.edit_role")
              : t("roles_page.create_role")}
          </DialogTitle>
          <DialogDescription>
            {isEditing
              ? t("roles_page.update_role_desc")
              : t("roles_page.define_role_desc")}
          </DialogDescription>
        </DialogHeader>
        <form
          onSubmit={submit(
            onSubmit,
            isEditing
              ? t("roles_page.update_failed")
              : t("roles_page.create_failed"),
          )}
          className="flex flex-1 flex-col overflow-hidden"
          noValidate
        >
          <div className="space-y-4 px-1">
            <FormErrorSummary message={rootError} />

            <div className="grid grid-cols-2 gap-4">
              <FormField
                id="role_name"
                label={t("common.name")}
                required
                error={fieldMessage(t, errors.name?.message)}
              >
                <Input
                  {...register("name")}
                  placeholder={t("roles_page.name_placeholder")}
                  className="mt-1"
                />
              </FormField>

              <FormField
                id="role_desc"
                label={t("roles_page.role_description")}
                error={fieldMessage(t, errors.description?.message)}
              >
                <Input
                  {...register("description")}
                  placeholder={t("leave_page.optional_description")}
                  className="mt-1"
                />
              </FormField>
            </div>

            <div>
              <Label id="role_permissions_label">
                {t("roles_page.permissions")}{" "}
                <span className="text-muted-foreground">
                  ({selected.size} {t("roles_page.selected")})
                </span>
              </Label>
              {errors.permissions?.message && (
                <p
                  id="role_permissions-error"
                  role="alert"
                  className="mt-1 text-xs font-medium text-destructive"
                >
                  {fieldMessage(t, errors.permissions.message)}
                </p>
              )}
            </div>
          </div>

          <div className="mt-2 flex-1 overflow-y-auto border rounded-md px-1">
            {permsLoading ? (
              <div className="space-y-2 p-3">
                {Array.from({ length: 5 }).map((_, i) => (
                  <Skeleton key={i} className="h-6 w-full" />
                ))}
              </div>
            ) : (
              <div className="divide-y">
                {moduleOrder.map((module) => {
                  const perms = permsByModule![module] ?? [];
                  const allChecked = perms.every((p) => selected.has(p.name));
                  const someChecked =
                    !allChecked && perms.some((p) => selected.has(p.name));

                  return (
                    <div key={module} className="py-3 px-3">
                      <div className="flex items-center gap-2">
                        <Checkbox
                          id={`module-${module}`}
                          checked={
                            allChecked
                              ? true
                              : someChecked
                                ? "indeterminate"
                                : false
                          }
                          onCheckedChange={() => toggleModule(module)}
                        />
                        <label
                          htmlFor={`module-${module}`}
                          className="text-sm font-medium cursor-pointer"
                        >
                          {MODULE_LABEL_KEYS[module]
                            ? t(MODULE_LABEL_KEYS[module])
                            : module}
                        </label>
                        <Badge variant="outline" className="ml-auto text-xs">
                          {perms.filter((p) => selected.has(p.name)).length}/
                          {perms.length}
                        </Badge>
                      </div>
                      <div className="mt-2 ml-6 grid gap-1.5 sm:grid-cols-2">
                        {perms.map((perm) => (
                          <div
                            key={perm.name}
                            className="flex items-start gap-2"
                          >
                            <Checkbox
                              id={`perm-${perm.name}`}
                              checked={selected.has(perm.name)}
                              onCheckedChange={() =>
                                togglePermission(perm.name)
                              }
                              className="mt-0.5"
                            />
                            <label
                              htmlFor={`perm-${perm.name}`}
                              className="cursor-pointer"
                            >
                              <span className="text-xs font-medium">
                                {perm.action}
                              </span>
                              {perm.description && (
                                <span className="block text-xs text-muted-foreground">
                                  {perm.description}
                                </span>
                              )}
                            </label>
                          </div>
                        ))}
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </div>

          <DialogFooter className="mt-4">
            <Button type="button" variant="outline" onClick={onClose}>
              {t("common.cancel")}
            </Button>
            {/* Not disabled on an empty selection any more: submitting now
                explains why, where a dead button did not. */}
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              {isEditing
                ? t("roles_page.update_role")
                : t("roles_page.create_role")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
