"use client";

import { useState } from "react";
import { UserPlus, Loader2, Pencil, Trash2, Send, AtSign } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { SimpleTable } from "@/components/shared/simple-table";
import { RoleGate } from "@/components/shared/role-gate";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import { toastError } from "@/lib/errors";
import {
  useUsers,
  useInviteUser,
  useUpdateUser,
  useDeleteUser,
  useResendInvite,
  type TenantUser,
  type UserStatus,
} from "@/features/users/api";

const ROLES = [
  "tenant_admin",
  "hr_admin",
  "finance_admin",
  "dept_admin",
  "supervisor",
  "employee",
] as const;

const STATUSES: UserStatus[] = ["active", "inactive", "suspended"];

function statusTone(status: UserStatus | string): string {
  switch (status) {
    case "active":
      return "var(--color-status-success, #059669)";
    case "invited":
      return "var(--color-status-info, #0284C7)";
    case "suspended":
      return "var(--color-status-error, #DC2626)";
    default:
      return "var(--color-text-secondary, #64748B)";
  }
}

export default function UsersSettingsPage() {
  const { t } = useT();
  const usersQuery = useUsers();
  const invite = useInviteUser();
  const update = useUpdateUser();
  const remove = useDeleteUser();
  const resend = useResendInvite();

  const [inviteOpen, setInviteOpen] = useState(false);
  const [editUser, setEditUser] = useState<TenantUser | null>(null);
  const [deleteUser, setDeleteUser] = useState<TenantUser | null>(null);

  const [form, setForm] = useState({
    email: "",
    username: "",
    role: "employee",
  });
  const [editForm, setEditForm] = useState({
    username: "",
    role: "employee",
    status: "active" as UserStatus,
  });

  function openEdit(u: TenantUser) {
    setEditForm({
      username: u.username ?? "",
      role: u.role,
      status: (u.status === "invited" ? "active" : u.status) as UserStatus,
    });
    setEditUser(u);
  }

  async function submitInvite() {
    try {
      await invite.mutateAsync({
        email: form.email,
        username: form.username || undefined,
        role: form.role,
      });
      toast.success(t("users_page.invited", "Invitation sent"));
      setInviteOpen(false);
      setForm({ email: "", username: "", role: "employee" });
    } catch (e) {
      toastError(e, t("users_page.invite_failed", "Could not invite user"));
    }
  }

  async function submitEdit() {
    if (!editUser) return;
    try {
      await update.mutateAsync({
        publicId: editUser.public_id,
        payload: {
          username: editForm.username || null,
          role: editForm.role,
          status: editForm.status,
        },
      });
      toast.success(t("users_page.updated", "User updated"));
      setEditUser(null);
    } catch (e) {
      toastError(e, t("users_page.update_failed", "Could not update user"));
    }
  }

  const users = usersQuery.data?.data ?? [];

  return (
    <RoleGate minRole="tenant_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("users_page.title", "Users & Access")}
          description={t(
            "users_page.description",
            "Invite people, set their role, and choose the handle they log in with.",
          )}
          actions={
            <Button onClick={() => setInviteOpen(true)}>
              <UserPlus className="mr-2 h-4 w-4" />
              {t("users_page.invite", "Invite user")}
            </Button>
          }
        />

        {usersQuery.isLoading ? (
          <div className="space-y-2">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-16 w-full" />
            ))}
          </div>
        ) : usersQuery.isError ? (
          <EmptyState
            title={t("users_page.load_failed", "Could not load users")}
            description={t("common.try_again", "Try again")}
          />
        ) : users.length === 0 ? (
          <EmptyState
            title={t("users_page.empty", "No users yet")}
            description={t(
              "users_page.empty_desc",
              "Invite your first teammate to get started.",
            )}
          />
        ) : (
          <Card>
            <CardContent className="p-0">
              <SimpleTable
                caption={t("users_page.title", "Users & Access")}
                headers={[
                  t("users_page.user", "User"),
                  t("users_page.username", "Username"),
                  t("common.role", "Role"),
                  t("common.status", "Status"),
                ]}
                rows={users.map((u) => ({
                  key: u.public_id,
                  cells: [
                    <div key="u">
                      <span className="block font-medium text-foreground">
                        {u.employee?.name ?? u.email}
                      </span>
                      <span className="block text-xs text-muted-foreground">
                        {u.email}
                      </span>
                    </div>,
                    u.username ? (
                      <span key="un" className="font-mono text-xs">
                        {u.username}
                      </span>
                    ) : (
                      <span key="un" className="text-xs text-muted-foreground">
                        —
                      </span>
                    ),
                    <span key="r" className="text-muted-foreground">
                      {t(`role.${u.role}`, u.role.replace(/_/g, " "))}
                    </span>,
                    <Badge
                      key="s"
                      variant="outline"
                      style={{ color: statusTone(u.status) }}
                    >
                      {t(`status.${u.status}`, u.status)}
                    </Badge>,
                  ],
                  actions: (
                    <div className="flex justify-end gap-1">
                      {u.status === "invited" && (
                        <Button
                          variant="ghost"
                          size="icon"
                          className="h-8 w-8"
                          title={t("users_page.resend", "Resend invite")}
                          onClick={async () => {
                            try {
                              await resend.mutateAsync(u.public_id);
                              toast.success(
                                t("users_page.resent", "Invitation resent"),
                              );
                            } catch (e) {
                              toastError(
                                e,
                                t("common.error", "Something went wrong"),
                              );
                            }
                          }}
                        >
                          <Send className="h-4 w-4" />
                        </Button>
                      )}
                      <Button
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8"
                        title={t("common.edit", "Edit")}
                        onClick={() => openEdit(u)}
                      >
                        <Pencil className="h-4 w-4" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 text-destructive"
                        title={t("common.delete", "Delete")}
                        onClick={() => setDeleteUser(u)}
                      >
                        <Trash2 className="h-4 w-4" />
                      </Button>
                    </div>
                  ),
                }))}
              />
            </CardContent>
          </Card>
        )}

        {/* Invite */}
        <Dialog open={inviteOpen} onOpenChange={setInviteOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>{t("users_page.invite", "Invite user")}</DialogTitle>
              <DialogDescription>
                {t(
                  "users_page.invite_desc",
                  "They receive an activation link to set their own password.",
                )}
              </DialogDescription>
            </DialogHeader>

            <div className="space-y-3">
              <div>
                <Label htmlFor="invite-email">
                  {t("common.email", "Email")}
                </Label>
                <Input
                  id="invite-email"
                  type="email"
                  value={form.email}
                  onChange={(e) => setForm({ ...form, email: e.target.value })}
                  className="mt-1"
                  placeholder="name@company.et"
                />
              </div>
              <div>
                <Label htmlFor="invite-username">
                  {t("users_page.username", "Username")}{" "}
                  <span className="text-muted-foreground">
                    ({t("common.optional", "optional")})
                  </span>
                </Label>
                <Input
                  id="invite-username"
                  value={form.username}
                  onChange={(e) =>
                    setForm({ ...form, username: e.target.value })
                  }
                  className="mt-1 font-mono"
                  placeholder="abebe.k"
                />
                <p className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
                  <AtSign className="h-3 w-3" />
                  {t(
                    "users_page.username_hint",
                    "Letters, numbers, dot, dash, underscore. Usable once Username login is enabled.",
                  )}
                </p>
              </div>
              <div>
                <Label>{t("common.role", "Role")}</Label>
                <Select
                  value={form.role}
                  onValueChange={(v) => setForm({ ...form, role: v })}
                >
                  <SelectTrigger className="mt-1">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {ROLES.map((r) => (
                      <SelectItem key={r} value={r}>
                        {t(`role.${r}`, r.replace(/_/g, " "))}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            <DialogFooter>
              <Button variant="outline" onClick={() => setInviteOpen(false)}>
                {t("common.cancel", "Cancel")}
              </Button>
              <Button
                onClick={submitInvite}
                disabled={invite.isPending || !form.email}
              >
                {invite.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                {t("users_page.send_invite", "Send invitation")}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

        {/* Edit */}
        <Dialog open={!!editUser} onOpenChange={(o) => !o && setEditUser(null)}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>{t("users_page.edit", "Edit user")}</DialogTitle>
              <DialogDescription>{editUser?.email}</DialogDescription>
            </DialogHeader>

            <div className="space-y-3">
              <div>
                <Label htmlFor="edit-username">
                  {t("users_page.username", "Username")}
                </Label>
                <Input
                  id="edit-username"
                  value={editForm.username}
                  onChange={(e) =>
                    setEditForm({ ...editForm, username: e.target.value })
                  }
                  className="mt-1 font-mono"
                  placeholder="abebe.k"
                />
              </div>
              <div>
                <Label>{t("common.role", "Role")}</Label>
                <Select
                  value={editForm.role}
                  onValueChange={(v) => setEditForm({ ...editForm, role: v })}
                >
                  <SelectTrigger className="mt-1">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {ROLES.map((r) => (
                      <SelectItem key={r} value={r}>
                        {t(`role.${r}`, r.replace(/_/g, " "))}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div>
                <Label>{t("common.status", "Status")}</Label>
                <Select
                  value={editForm.status}
                  onValueChange={(v) =>
                    setEditForm({ ...editForm, status: v as UserStatus })
                  }
                >
                  <SelectTrigger className="mt-1">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {STATUSES.map((s) => (
                      <SelectItem key={s} value={s}>
                        {t(`status.${s}`, s)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            <DialogFooter>
              <Button variant="outline" onClick={() => setEditUser(null)}>
                {t("common.cancel", "Cancel")}
              </Button>
              <Button onClick={submitEdit} disabled={update.isPending}>
                {update.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                {t("common.save", "Save")}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

        {/* Delete */}
        <Dialog
          open={!!deleteUser}
          onOpenChange={(o) => !o && setDeleteUser(null)}
        >
          <DialogContent>
            <DialogHeader>
              <DialogTitle>{t("users_page.delete", "Remove user")}</DialogTitle>
              <DialogDescription>
                {t(
                  "users_page.delete_desc",
                  "This revokes their access. Employee records are not deleted.",
                )}
              </DialogDescription>
            </DialogHeader>
            <p className="text-sm text-foreground">{deleteUser?.email}</p>
            <DialogFooter>
              <Button variant="outline" onClick={() => setDeleteUser(null)}>
                {t("common.cancel", "Cancel")}
              </Button>
              <Button
                variant="destructive"
                disabled={remove.isPending}
                onClick={async () => {
                  if (!deleteUser) return;
                  try {
                    await remove.mutateAsync(deleteUser.public_id);
                    toast.success(t("users_page.deleted", "User removed"));
                    setDeleteUser(null);
                  } catch (e) {
                    toastError(e, t("common.error", "Something went wrong"));
                  }
                }}
              >
                {remove.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                {t("common.delete", "Delete")}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </RoleGate>
  );
}
