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
  type PermissionsByModule,
} from "@/features/roles/api";
import { toast } from "sonner";
import { toastError } from "@/lib/errors";

const MODULE_LABELS: Record<string, string> = {
  org: "Organization",
  employee: "Employees",
  attendance: "Attendance",
  shift: "Shifts",
  device: "Devices",
  correction: "Corrections",
  holiday: "Holidays",
  leave: "Leave",
  payroll: "Payroll",
  report: "Reports",
  announcement: "Announcements",
  profile: "Profile",
  dashboard: "Dashboard",
  apikey: "API Keys",
  webhook: "Webhooks",
  billing: "Billing",
  settings: "Settings",
};

export default function RolesPage() {
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
          title="Custom Roles"
          description="Create and manage custom permission roles for your organization"
          actions={
            <Button onClick={openCreate}>
              <Plus className="mr-2 h-4 w-4" /> Create Role
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
            title="No custom roles"
            description="Create custom roles to assign specific permissions to users beyond the default role hierarchy"
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
  const deleteRole = useDeleteCustomRole();

  function handleDelete() {
    if (!confirm(`Delete role "${role.name}"?`)) return;
    deleteRole.mutate(role.public_id, {
      onSuccess: () => toast.success(`Role "${role.name}" deleted`),
      onError: (err) => toastError(err, "Failed to delete role"),
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
                Inactive
              </Badge>
            )}
          </div>
          {role.description && (
            <p className="mt-0.5 text-xs text-muted-foreground">
              {role.description}
            </p>
          )}
          <div className="mt-1.5 flex items-center gap-3 text-xs text-muted-foreground">
            <span>{role.permissions.length} permissions</span>
            <span className="flex items-center gap-1">
              <Users className="h-3 w-3" />
              {role.users_count ?? 0} users
            </span>
          </div>
        </div>
        <div className="flex items-center gap-1">
          <Button
            variant="ghost"
            size="sm"
            onClick={() => onEdit(role)}
            aria-label={`Edit ${role.name}`}
          >
            <Pencil className="h-3.5 w-3.5" />
          </Button>
          <Button
            variant="ghost"
            size="sm"
            className="text-destructive hover:text-destructive"
            onClick={handleDelete}
            disabled={deleteRole.isPending}
            aria-label={`Delete ${role.name}`}
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
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [selected, setSelected] = useState<Set<string>>(new Set());

  const { data: permsByModule, isLoading: permsLoading } = usePermissions();

  const isEditing = !!editingRole;
  const createRole = useCreateCustomRole();
  const updateRole = useUpdateCustomRole(editingRole?.public_id ?? "");

  const resetForm = useCallback(() => {
    if (editingRole) {
      setName(editingRole.name);
      setDescription(editingRole.description);
      setSelected(new Set(editingRole.permissions));
    } else {
      setName("");
      setDescription("");
      setSelected(new Set());
    }
  }, [editingRole]);

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

  const togglePermission = useCallback((permName: string) => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(permName)) {
        next.delete(permName);
      } else {
        next.add(permName);
      }
      return next;
    });
  }, []);

  const toggleModule = useCallback(
    (module: string) => {
      if (!permsByModule) return;
      const modulePerms = permsByModule[module] ?? [];
      const allSelected = modulePerms.every((p) => selected.has(p.name));
      setSelected((prev) => {
        const next = new Set(prev);
        for (const p of modulePerms) {
          if (allSelected) {
            next.delete(p.name);
          } else {
            next.add(p.name);
          }
        }
        return next;
      });
    },
    [permsByModule, selected],
  );

  const moduleOrder = useMemo(() => {
    if (!permsByModule) return [];
    return Object.keys(permsByModule).sort((a, b) => {
      const la = MODULE_LABELS[a] ?? a;
      const lb = MODULE_LABELS[b] ?? b;
      return la.localeCompare(lb);
    });
  }, [permsByModule]);

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const permissions = Array.from(selected);
    if (permissions.length === 0) {
      toast.error("Select at least one permission");
      return;
    }

    if (isEditing) {
      updateRole.mutate(
        { name, description, permissions },
        {
          onSuccess: () => {
            toast.success(`Role "${name}" updated`);
            onClose();
          },
          onError: (err) => toastError(err, "Failed to update role"),
        },
      );
    } else {
      createRole.mutate(
        { name, description, permissions },
        {
          onSuccess: () => {
            toast.success(`Role "${name}" created`);
            onClose();
          },
          onError: (err) => toastError(err, "Failed to create role"),
        },
      );
    }
  }

  const isPending = createRole.isPending || updateRole.isPending;

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="max-w-2xl max-h-[85vh] overflow-hidden flex flex-col">
        <DialogHeader>
          <DialogTitle>{isEditing ? "Edit Role" : "Create Role"}</DialogTitle>
          <DialogDescription>
            {isEditing
              ? "Update role details and permissions"
              : "Define a new role with specific permissions"}
          </DialogDescription>
        </DialogHeader>
        <form
          onSubmit={handleSubmit}
          className="flex flex-1 flex-col overflow-hidden"
        >
          <div className="space-y-4 px-1">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <Label htmlFor="role_name">Name</Label>
                <Input
                  id="role_name"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder="e.g. Junior HR"
                  required
                  className="mt-1"
                />
              </div>
              <div>
                <Label htmlFor="role_desc">Description</Label>
                <Input
                  id="role_desc"
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  placeholder="Optional description"
                  className="mt-1"
                />
              </div>
            </div>

            <div>
              <Label>
                Permissions{" "}
                <span className="text-muted-foreground">
                  ({selected.size} selected)
                </span>
              </Label>
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
                          {MODULE_LABELS[module] ?? module}
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
              Cancel
            </Button>
            <Button type="submit" disabled={isPending || selected.size === 0}>
              {isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              {isEditing ? "Update Role" : "Create Role"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
