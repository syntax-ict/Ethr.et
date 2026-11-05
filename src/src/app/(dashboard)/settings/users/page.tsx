"use client";

import { useState } from "react";
import {
  UserPlus,
  Loader2,
  Pencil,
  Trash2,
  Send,
  AtSign,
  ShieldOff,
} from "lucide-react";
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
import { PageActions } from "@/components/shared/page-actions";
import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { FormField } from "@/components/patterns/FormField";
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
  useResetUserMfa,
  useSendInvitations,
  type TenantUser,
  type UpdateUserPayload,
  type UserRole,
  type UserStatus,
} from "@/features/users/api";
import { SearchInput } from "@/components/shared/search-input";
import { PaginationControls } from "@/components/shared/pagination-controls";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { OwnershipCard } from "@/features/settings/components/ownership-card";
import { useQueryFilters } from "@/lib/hooks/useQueryFilters";
import { FilterBar } from "@/components/shared/filter-bar";
import { InvitationProgress } from "@/features/users/components/invitation-progress";
import { InvitationRemindersSetting } from "@/features/users/components/invitation-reminders-setting";
import { rules, fieldMessage } from "@/lib/forms/rules";
import { statusBadgeClass, statusTone } from "@/lib/utils/status-colors";

const ROLES = [
  "tenant_admin",
  "hr_admin",
  "finance_admin",
  "dept_admin",
  "supervisor",
  "employee",
] as const;

// What an admin may set; `invited` is written only by an invitation.
type EditableStatus = NonNullable<UpdateUserPayload["status"]>;

const STATUSES: EditableStatus[] = ["active", "inactive", "suspended"];

// The shared registry has no `invited` (it would fall to neutral); an account
// waiting on its invitation reads as information, as it did before.
function userStatusClass(status: UserStatus | string): string {
  return status === "invited"
    ? `${statusTone("info").badge} border-0`
    : statusBadgeClass(status);
}

/** What became of an invited person's last invitation, in a few words. */
function invitationLine(
  u: TenantUser,
  t: ReturnType<typeof useT>["t"],
): string {
  if (!u.email && u.last_invitation?.channel !== "sms") {
    return t("users_page.invitation.in_person", "Activate in person");
  }

  switch (u.last_invitation?.status) {
    case "queued":
      return t("users_page.invitation.queued", "Invitation on its way");
    case "sent":
      return u.last_invitation.reminder
        ? t("users_page.invitation.reminder_sent", "Reminder sent")
        : t("users_page.invitation.sent", "Invitation sent");
    case "failed":
      return t("users_page.invitation.failed", "Invitation could not be sent");
    default:
      return t("users_page.invitation.none", "No invitation sent yet");
  }
}

/** How a row names its person: employee name, display name, then email. */
function personName(u: {
  employee?: { name?: string | null } | null;
  display_name?: string | null;
  email?: string | null;
}): string {
  return u.employee?.name ?? u.display_name ?? u.email ?? "";
}

export default function UsersSettingsPage() {
  const { t } = useT();
  // Every page, and the API's search: it pages at 25 ordered by email, and the
  // screen read only the first page, so accounts past the 25th could not be
  // edited, re-invited or removed (audit N68).
  const [page, setPage] = useState(1);
  const filters = useQueryFilters({ q: "", status: "" });
  const search = filters.values.q;
  const notActivatedOnly = filters.values.status === "invited";
  const usersQuery = useUsers({
    page,
    search: search || undefined,
    status: notActivatedOnly ? "invited" : undefined,
  });
  const invite = useInviteUser();
  const update = useUpdateUser();
  const remove = useDeleteUser();
  const resend = useResendInvite();
  const resetMfa = useResetUserMfa();
  const sendInvitations = useSendInvitations();
  const { can } = usePermissions();
  // The bulk send in progress on this screen, if any.
  const [batch, setBatch] = useState<string | null>(null);

  async function resendToEveryone() {
    try {
      const result = await sendInvitations.mutateAsync({
        scope: "not_activated",
      });
      if (result.batch) {
        setBatch(result.batch);
      } else {
        toast.info(
          t(
            "users_page.nothing_to_send",
            "No one is waiting for an invitation by email.",
          ),
        );
      }
    } catch (e) {
      toastError(e, t("common.error", "Something went wrong"));
    }
  }

  const [inviteOpen, setInviteOpen] = useState(false);
  const [editUser, setEditUser] = useState<TenantUser | null>(null);
  const [deleteUser, setDeleteUser] = useState<TenantUser | null>(null);
  const [mfaUser, setMfaUser] = useState<TenantUser | null>(null);

  const [form, setForm] = useState({
    email: "",
    username: "",
    role: "employee" as UserRole,
  });
  // Shown under the address on submit. The send button used to be disabled
  // until something was typed, which said nothing about why.
  const [emailError, setEmailError] = useState<string | undefined>();
  // `status` is null for an invitee until the admin picks one: the form used
  // to open them as `active` and send it back, so any edit activated the
  // account and took the Resend invite action with it.
  const [editForm, setEditForm] = useState<{
    username: string;
    role: UserRole;
    status: EditableStatus | null;
  }>({ username: "", role: "employee", status: "active" });

  function openEdit(u: TenantUser) {
    setEditForm({
      username: u.username ?? "",
      role: u.role,
      status: u.status === "invited" ? null : u.status,
    });
    setEditUser(u);
  }

  // Each invitation starts blank. The form was cleared only after a successful
  // send, so a cancelled one reopened with the old address in it, and typing
  // appended to it (production QA 2026-10-11, P14).
  function openInvite() {
    setForm({ email: "", username: "", role: "employee" });
    setEmailError(undefined);
    setInviteOpen(true);
  }

  async function submitInvite() {
    const parsed = rules.email().safeParse(form.email);
    if (!parsed.success) {
      const code = parsed.error.issues[0]?.message;
      setEmailError(
        code === "validation.required"
          ? t("users_page.email_required", "Enter an email address")
          : fieldMessage(t, code),
      );
      return;
    }
    setEmailError(undefined);
    try {
      await invite.mutateAsync({
        email: parsed.data,
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
          ...(editForm.status !== null && editForm.status !== editUser.status
            ? { status: editForm.status }
            : {}),
        },
      });
      toast.success(t("users_page.updated", "User updated"));
      setEditUser(null);
    } catch (e) {
      toastError(e, t("users_page.update_failed", "Could not update user"));
    }
  }

  const users = usersQuery.data?.data ?? [];
  const filtered = filters.activeCount > 0;

  function clearFilters() {
    filters.clear();
    setPage(1);
  }

  return (
    <RoleGate anyPermission={["viewUsers"]}>
      <div className="space-y-6">
        <OwnershipCard />
        <PageActions
          actions={
            <>
              {can.inviteUsers && (
                <Button
                  variant="outline"
                  onClick={resendToEveryone}
                  disabled={sendInvitations.isPending}
                >
                  <Send className="mr-2 h-4 w-4" />
                  {t(
                    "users_page.resend_all",
                    "Resend to everyone not activated",
                  )}
                </Button>
              )}
              <Button onClick={openInvite}>
                <UserPlus className="mr-2 h-4 w-4" />
                {t("users_page.invite", "Invite user")}
              </Button>
            </>
          }
        />

        {batch && <InvitationProgress batch={batch} onRetried={setBatch} />}

        <InvitationRemindersSetting />

        <FilterBar activeCount={filters.activeCount} onClear={clearFilters}>
          <div className="w-full max-w-sm">
            <SearchInput
              value={search}
              onChange={(value) => {
                filters.set({ q: value });
                setPage(1);
              }}
              placeholder={t("users_page.search", "Search by name or email")}
            />
          </div>
          <Button
            variant={notActivatedOnly ? "default" : "outline"}
            size="sm"
            aria-pressed={notActivatedOnly}
            onClick={() => {
              filters.set({ status: notActivatedOnly ? "" : "invited" });
              setPage(1);
            }}
          >
            {t("users_page.not_activated", "Not activated")}
          </Button>
        </FilterBar>

        {usersQuery.isLoading ? (
          <div className="space-y-2">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-16 w-full" />
            ))}
          </div>
        ) : usersQuery.isError ? (
          <ErrorState
            title={t("users_page.load_failed", "Could not load users")}
            onRetry={() => usersQuery.refetch()}
          />
        ) : users.length === 0 && filtered ? (
          <EmptyState
            title={t("users_page.no_matches", "No users match")}
            description={t(
              "users_page.no_matches_desc",
              "Change the search or clear the filter.",
            )}
            action={
              <Button variant="outline" onClick={clearFilters}>
                {t("common.clear_filters", "Clear filters")}
              </Button>
            }
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
                mobileCards
                caption={t("users_page.title", "Users & access")}
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
                        {u.employee?.name ?? u.display_name ?? u.email}
                      </span>
                      <span className="block text-xs text-muted-foreground">
                        {u.email}
                        {u.mfa_enabled && (
                          <Badge
                            variant="outline"
                            className="ml-2 text-caption"
                          >
                            {t("users_page.mfa_on", "2FA")}
                          </Badge>
                        )}
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
                    <div key="s">
                      <Badge
                        variant="outline"
                        className={userStatusClass(u.status)}
                      >
                        {t(`status.${u.status}`, u.status)}
                      </Badge>
                      {u.status === "invited" && (
                        <span className="mt-1 block text-xs text-muted-foreground">
                          {invitationLine(u, t)}
                        </span>
                      )}
                    </div>,
                  ],
                  actions: (
                    <div className="flex justify-end gap-1">
                      {u.status === "invited" && (
                        <Button
                          variant="ghost"
                          size="icon"
                          className="h-8 w-8"
                          title={t("users_page.resend", "Resend invite")}
                          aria-label={t(
                            "users_page.resend_named",
                            "Resend the invitation to :name",
                            { name: personName(u) },
                          )}
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
                      {u.mfa_enabled && can.resetUserMfa && (
                        <Button
                          variant="ghost"
                          size="icon"
                          className="h-8 w-8"
                          title={t(
                            "users_page.reset_mfa",
                            "Reset two-factor authentication",
                          )}
                          aria-label={t(
                            "users_page.reset_mfa_named",
                            "Reset two-factor authentication for :name",
                            { name: personName(u) },
                          )}
                          onClick={() => setMfaUser(u)}
                        >
                          <ShieldOff className="h-4 w-4" />
                        </Button>
                      )}
                      <Button
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8"
                        title={t("common.edit", "Edit")}
                        aria-label={t("users_page.edit_named", "Edit :name", {
                          name: personName(u),
                        })}
                        onClick={() => openEdit(u)}
                      >
                        <Pencil className="h-4 w-4" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 text-destructive"
                        title={t("common.delete", "Delete")}
                        aria-label={t(
                          "users_page.delete_named",
                          "Remove :name",
                          { name: personName(u) },
                        )}
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

        <PaginationControls
          meta={usersQuery.data?.meta}
          onPageChange={setPage}
          disabled={usersQuery.isFetching}
        />

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
              <FormField
                id="invite-email"
                label={t("common.email", "Email")}
                required
                error={emailError}
              >
                <Input
                  type="email"
                  value={form.email}
                  onChange={(e) => {
                    setForm({ ...form, email: e.target.value });
                    if (emailError) setEmailError(undefined);
                  }}
                  className="mt-1"
                  placeholder="name@company.et"
                />
              </FormField>
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
                    "Letters, numbers, dot, dash, underscore. Works once sign-in with a username is turned on.",
                  )}
                </p>
              </div>
              <div>
                <Label htmlFor="invite-role">{t("common.role", "Role")}</Label>
                <Select
                  value={form.role}
                  onValueChange={(v) =>
                    setForm({ ...form, role: v as UserRole })
                  }
                >
                  <SelectTrigger id="invite-role" className="mt-1">
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
              <Button onClick={submitInvite} disabled={invite.isPending}>
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
                <Label htmlFor="edit-role">{t("common.role", "Role")}</Label>
                <Select
                  value={editForm.role}
                  onValueChange={(v) =>
                    setEditForm({ ...editForm, role: v as UserRole })
                  }
                >
                  <SelectTrigger id="edit-role" className="mt-1">
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
                <Label htmlFor="edit-status">
                  {t("common.status", "Status")}
                </Label>
                <Select
                  value={editForm.status ?? ""}
                  onValueChange={(v) =>
                    setEditForm({ ...editForm, status: v as EditableStatus })
                  }
                >
                  <SelectTrigger id="edit-status" className="mt-1">
                    <SelectValue placeholder={t("status.invited", "Invited")} />
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

        {/* Reset two-factor: the way back from a lost authenticator */}
        <Dialog open={!!mfaUser} onOpenChange={(o) => !o && setMfaUser(null)}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>
                {t("users_page.reset_mfa", "Reset two-factor authentication")}
              </DialogTitle>
              <DialogDescription>
                {t(
                  "users_page.reset_mfa_desc",
                  "Use this when someone has lost the phone with their authenticator app. They sign in with their password and set it up again, and they are emailed that it was reset.",
                )}
              </DialogDescription>
            </DialogHeader>
            <p className="text-sm text-foreground">{mfaUser?.email}</p>
            <DialogFooter>
              <Button variant="outline" onClick={() => setMfaUser(null)}>
                {t("common.cancel", "Cancel")}
              </Button>
              <Button
                variant="destructive"
                disabled={resetMfa.isPending}
                onClick={async () => {
                  if (!mfaUser) return;
                  try {
                    await resetMfa.mutateAsync(mfaUser.public_id);
                    toast.success(
                      t(
                        "users_page.mfa_reset_done",
                        "Two-factor authentication reset",
                      ),
                    );
                    setMfaUser(null);
                  } catch (e) {
                    toastError(e, t("common.error", "Something went wrong"));
                  }
                }}
              >
                {resetMfa.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                {t("users_page.reset_mfa_confirm", "Reset")}
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
