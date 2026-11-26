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
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { PageActions } from "@/components/shared/page-actions";
import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { FormField } from "@/components/patterns/FormField";
import { SimpleTable } from "@/components/shared/simple-table";
import { RoleGate } from "@/components/shared/role-gate";
import { useSaveStatus } from "@/components/shared/save-status";
import { useUnsavedChangesWarning } from "@/lib/hooks/useUnsavedChangesWarning";
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
  useApplyTitleAccess,
  useAssignableRoles,
  type TenantUser,
  type UpdateUserPayload,
  type UserRole,
  type UserStatus,
} from "@/features/users/api";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { SearchInput } from "@/components/shared/search-input";
import { PaginationControls } from "@/components/shared/pagination-controls";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useCurrentUser } from "@/features/auth/api";
import { OwnershipCard } from "@/features/settings/components/ownership-card";
import { useQueryFilters } from "@/lib/hooks/useQueryFilters";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
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

// A Radix Select cannot hold an empty value, so "no custom role" and "any
// custom role" each get a name of their own.
const NO_CUSTOM_ROLE = "__none__";
const ANY_CUSTOM_ROLE = "__any__";

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
  formatDate: (dateStr: string) => string,
): string {
  if (!u.email && u.last_invitation?.channel !== "sms") {
    return t("users_page.invitation.in_person", "Activate in person");
  }

  switch (u.last_invitation?.status) {
    case "queued": {
      const heldUntil = u.last_invitation.held_until;
      if (heldUntil && new Date(heldUntil).getTime() > Date.now()) {
        return t("users_page.invitation.held", "Invitation goes out on :date", {
          date: formatDate(heldUntil),
        });
      }
      return t("users_page.invitation.queued", "Invitation on its way");
    }
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
  const { formatDate } = useDateFormatters();
  // Every page, and the API's search: it pages at 25 ordered by email, and the
  // screen read only the first page, so accounts past the 25th could not be
  // edited, re-invited or removed (audit N68).
  const [page, setPage] = useState(1);
  const filters = useQueryFilters({
    q: "",
    status: "",
    title: "",
    custom_role: "",
  });
  const search = filters.values.q;
  const notActivatedOnly = filters.values.status === "invited";
  const differsOnly = filters.values.title === "held";
  const usersQuery = useUsers({
    page,
    search: search || undefined,
    status: notActivatedOnly ? "invited" : undefined,
    titleAccess: differsOnly ? "held" : undefined,
    customRole: filters.values.custom_role || undefined,
  });
  const applyTitle = useApplyTitleAccess();
  const invite = useInviteUser();
  const update = useUpdateUser();
  const remove = useDeleteUser();
  const resend = useResendInvite();
  const resetMfa = useResetUserMfa();
  const sendInvitations = useSendInvitations();
  const { can } = usePermissions();
  const me = useCurrentUser().data;
  // Whoever may edit accounts may give a custom role; the read is theirs, not
  // Settings > Roles, which only the organisation admin may open.
  const assignable = useAssignableRoles(can.updateUsers);
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
  const [confirmFollow, setConfirmFollow] = useState(false);
  // The server refuses a change to your own custom role, so the dialog does
  // not offer one on your own row.
  const ownRow = editUser !== null && me?.public_id === editUser.public_id;
  const customRoles = assignable.data ?? [];
  const roleFilter = filters.values.custom_role;

  // What a custom role reaches, said beside its name so a custom "Department
  // admin" is not read as the built-in one.
  const reachLabel = (scope: string) =>
    t(`roles_page.scope_${scope}`, scope.replace(/_/g, " "));

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
    follows: boolean;
    customRole: string;
  }>({
    username: "",
    role: "employee",
    status: "active",
    follows: false,
    customRole: NO_CUSTOM_ROLE,
  });

  // The two dialogs are the forms: the invitation is dirty once anything is
  // typed or the role is moved off the default, the edit once a field differs
  // from the user it was opened on. Sending or closing either clears it.
  useUnsavedChangesWarning(
    (inviteOpen &&
      (form.email !== "" ||
        form.username !== "" ||
        form.role !== "employee")) ||
      (editUser !== null &&
        (editForm.username !== (editUser.username ?? "") ||
          editForm.role !== editUser.role ||
          editForm.status !==
            (editUser.status === "invited" ? null : editUser.status) ||
          editForm.follows !== editUser.role_follows_position ||
          editForm.customRole !==
            (editUser.custom_role?.public_id ?? NO_CUSTOM_ROLE))),
  );
  const { announceSaved, announceError } = useSaveStatus();

  function openEdit(u: TenantUser) {
    setEditForm({
      username: u.username ?? "",
      role: u.role,
      status: u.status === "invited" ? null : u.status,
      follows: u.role_follows_position,
      customRole: u.custom_role?.public_id ?? NO_CUSTOM_ROLE,
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
      announceSaved(t("users_page.invited", "Invitation sent"));
      setInviteOpen(false);
      setForm({ email: "", username: "", role: "employee" });
    } catch (e) {
      announceError(e, t("users_page.invite_failed", "Could not invite user"));
      toastError(e, t("users_page.invite_failed", "Could not invite user"));
    }
  }

  const roleLabel = (r: string) => t(`role.${r}`, r.replace(/_/g, " "));

  // Switching an account on to its position applies at once, so when that
  // would change its role the person is asked first.
  function savePressed() {
    const preview = editUser?.title_preview;
    if (
      editUser &&
      editForm.follows &&
      !editUser.role_follows_position &&
      preview &&
      preview.role !== editUser.role
    ) {
      setConfirmFollow(true);
      return;
    }
    void submitEdit();
  }

  async function submitEdit() {
    if (!editUser) return;
    setConfirmFollow(false);
    try {
      await update.mutateAsync({
        publicId: editUser.public_id,
        payload: {
          username: editForm.username || null,
          ...(editForm.follows
            ? editUser.role_follows_position
              ? {}
              : { role_follows_position: true }
            : {
                role: editForm.role,
                ...(editUser.role_follows_position
                  ? { role_follows_position: false }
                  : {}),
              }),
          ...(editForm.status !== null && editForm.status !== editUser.status
            ? { status: editForm.status }
            : {}),
          ...(!ownRow &&
          editForm.customRole !==
            (editUser.custom_role?.public_id ?? NO_CUSTOM_ROLE)
            ? {
                custom_role_id:
                  editForm.customRole === NO_CUSTOM_ROLE
                    ? null
                    : editForm.customRole,
              }
            : {}),
        },
      });
      toast.success(t("users_page.updated", "User updated"));
      announceSaved(t("users_page.updated", "User updated"));
      setEditUser(null);
    } catch (e) {
      announceError(e, t("users_page.update_failed", "Could not update user"));
      toastError(e, t("users_page.update_failed", "Could not update user"));
    }
  }

  async function applyTitleRole(u: TenantUser) {
    try {
      await applyTitle.mutateAsync(u.public_id);
      toast.success(
        t("users_page.title_applied", "Role updated from the position"),
      );
    } catch (e) {
      toastError(e, t("common.error", "Something went wrong"));
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
              label={t("users_page.search", "Search by name or email")}
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
          <Button
            variant={differsOnly ? "default" : "outline"}
            size="sm"
            aria-pressed={differsOnly}
            onClick={() => {
              filters.set({ title: differsOnly ? "" : "held" });
              setPage(1);
            }}
          >
            {t("users_page.differs_from_title", "Differs from position")}
          </Button>
          {(customRoles.length > 0 || roleFilter !== "") && (
            <Select
              value={roleFilter || ANY_CUSTOM_ROLE}
              onValueChange={(v) => {
                filters.set({ custom_role: v === ANY_CUSTOM_ROLE ? "" : v });
                setPage(1);
              }}
            >
              <SelectTrigger
                className="h-9 w-52"
                aria-label={t(
                  "users_page.filter_custom_role",
                  "Filter by custom role",
                )}
              >
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={ANY_CUSTOM_ROLE}>
                  {t("users_page.any_custom_role", "Any custom role")}
                </SelectItem>
                {roleFilter !== "" &&
                  !customRoles.some((r) => r.public_id === roleFilter) && (
                    <SelectItem value={roleFilter}>
                      {users.find(
                        (u) => u.custom_role?.public_id === roleFilter,
                      )?.custom_role?.name ??
                        t("users_page.one_custom_role", "One custom role")}
                    </SelectItem>
                  )}
                {customRoles.map((r) => (
                  <SelectItem key={r.public_id} value={r.public_id}>
                    {r.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
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
                        {t("common.not_set", "Not set")}
                      </span>
                    ),
                    <div key="r">
                      <span className="text-muted-foreground">
                        {t(`role.${u.role}`, u.role.replace(/_/g, " "))}
                      </span>
                      {u.role_follows_position && (
                        <span className="block text-xs text-muted-foreground">
                          {t("users_page.from_title", "From position")}
                        </span>
                      )}
                      {u.custom_role && (
                        <Badge
                          variant={
                            u.custom_role.is_active === false
                              ? "outline"
                              : "secondary"
                          }
                          className="mt-1 block w-fit max-w-full truncate text-caption"
                          title={
                            u.custom_role.is_active === false
                              ? t(
                                  "users_page.custom_role_off_title",
                                  "Inactive custom role: it grants nothing, so the built-in role applies",
                                )
                              : t(
                                  "users_page.custom_role_badge_title",
                                  "Custom role: its permissions replace the built-in role's",
                                )
                          }
                        >
                          {u.custom_role.name}
                          {u.custom_role.is_active === false &&
                            ` (${t("roles_page.inactive", "Inactive")})`}
                        </Badge>
                      )}
                      {u.title_role && u.title_role !== u.role && (
                        <span className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                          {t(
                            "users_page.title_gives",
                            "Position gives :role. Waiting for someone who may give that role.",
                            {
                              role: t(
                                `role.${u.title_role}`,
                                u.title_role.replace(/_/g, " "),
                              ),
                            },
                          )}
                          <Button
                            variant="outline"
                            size="sm"
                            disabled={applyTitle.isPending}
                            onClick={() => applyTitleRole(u)}
                          >
                            {t("users_page.apply_title", "Apply")}
                          </Button>
                        </span>
                      )}
                    </div>,
                    <div key="s">
                      <Badge
                        variant="outline"
                        className={userStatusClass(u.status)}
                      >
                        {t(`status.${u.status}`, u.status)}
                      </Badge>
                      {u.status === "invited" && (
                        <span className="mt-1 block text-xs text-muted-foreground">
                          {invitationLine(u, t, formatDate)}
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
              {editUser?.employee && (
                <div>
                  <Label id="edit-source-label">
                    {t("users_page.role_source", "Where the role comes from")}
                  </Label>
                  <div
                    role="radiogroup"
                    aria-labelledby="edit-source-label"
                    className="mt-1 flex flex-wrap gap-2"
                  >
                    {[true, false].map((follows) => (
                      <Button
                        key={String(follows)}
                        type="button"
                        size="sm"
                        role="radio"
                        aria-checked={editForm.follows === follows}
                        variant={
                          editForm.follows === follows ? "default" : "outline"
                        }
                        onClick={() => setEditForm({ ...editForm, follows })}
                      >
                        {follows
                          ? t("users_page.follows_title", "Follows position")
                          : t("users_page.set_by_hand", "Set by hand")}
                      </Button>
                    ))}
                  </div>
                  {editForm.follows && editUser.title_preview && (
                    <p className="mt-2 text-xs text-muted-foreground">
                      {editUser.title_preview.position
                        ? t(
                            "users_page.title_preview",
                            "Position :title gives :role",
                            {
                              title: editUser.title_preview.position,
                              role: roleLabel(editUser.title_preview.role),
                            },
                          )
                        : t(
                            "users_page.title_preview_none",
                            "No position, so this gives :role",
                            { role: roleLabel(editUser.title_preview.role) },
                          )}
                    </p>
                  )}
                </div>
              )}
              <div>
                <Label htmlFor="edit-role">{t("common.role", "Role")}</Label>
                <Select
                  disabled={editForm.follows}
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
              {!ownRow && (
                <div>
                  <Label htmlFor="edit-custom-role">
                    {t("users_page.custom_role", "Custom role")}
                  </Label>
                  <Select
                    value={editForm.customRole}
                    onValueChange={(v) =>
                      setEditForm({ ...editForm, customRole: v })
                    }
                  >
                    <SelectTrigger id="edit-custom-role" className="mt-1">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value={NO_CUSTOM_ROLE}>
                        {t(
                          "users_page.custom_role_none",
                          "None (built-in role only)",
                        )}
                      </SelectItem>
                      <SelectGroup>
                        <SelectLabel>
                          {t("users_page.custom_roles_group", "Custom roles")}
                        </SelectLabel>
                        {editUser?.custom_role &&
                          !customRoles.some(
                            (r) =>
                              r.public_id === editUser.custom_role?.public_id,
                          ) && (
                            <SelectItem value={editUser.custom_role.public_id}>
                              {editUser.custom_role.is_active === false
                                ? t(
                                    "users_page.custom_role_current_off",
                                    ":name (inactive, grants nothing)",
                                    { name: editUser.custom_role.name },
                                  )
                                : t(
                                    "users_page.custom_role_current",
                                    ":name (current, not available to give)",
                                    { name: editUser.custom_role.name },
                                  )}
                            </SelectItem>
                          )}
                        {customRoles.map((r) => (
                          <SelectItem key={r.public_id} value={r.public_id}>
                            {r.name}
                            <span className="ml-2 text-xs text-muted-foreground">
                              {reachLabel(r.org_scope)}
                            </span>
                          </SelectItem>
                        ))}
                      </SelectGroup>
                    </SelectContent>
                  </Select>
                  {editForm.customRole !== NO_CUSTOM_ROLE && (
                    <p className="mt-2 text-xs text-muted-foreground">
                      {editUser?.custom_role?.is_active === false &&
                      editForm.customRole === editUser.custom_role.public_id
                        ? t(
                            "users_page.custom_role_note_off",
                            "This custom role is inactive, so it grants nothing and the built-in role applies.",
                          )
                        : editForm.follows
                          ? t(
                              "users_page.custom_role_note_follows",
                              "The custom role's permissions replace the built-in role's. While it is set, the position does not change the built-in role. Remove the custom role to have the position apply again.",
                            )
                          : t(
                              "users_page.custom_role_note",
                              "The custom role's permissions replace the built-in role's while it is set.",
                            )}
                    </p>
                  )}
                </div>
              )}
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
              <Button onClick={savePressed} disabled={update.isPending}>
                {update.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                {t("common.save", "Save")}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

        {/* Following the position would change this role: say how, first */}
        <ConfirmDialog
          open={confirmFollow && !!editUser?.title_preview}
          onOpenChange={setConfirmFollow}
          title={t("users_page.follow_confirm_title", "Follow the position?")}
          description={
            editUser?.title_preview
              ? t(
                  editUser.title_preview.applies
                    ? "users_page.follow_confirm_applies"
                    : "users_page.follow_confirm_held",
                  editUser.title_preview.applies
                    ? ":name's role changes from :from to :to."
                    : ":name keeps :from until someone who may give :to applies it. It will show under Differs from position.",
                  {
                    name:
                      editUser.employee?.name ??
                      editUser.display_name ??
                      editUser.email ??
                      "",
                    from: roleLabel(editUser.role),
                    to: roleLabel(editUser.title_preview.role),
                  },
                )
              : undefined
          }
          confirmLabel={
            editUser?.title_preview?.applies
              ? t("users_page.follow_confirm_change", "Change role")
              : t("users_page.follow_confirm_follow", "Follow the position")
          }
          cancelLabel={t("users_page.keep_editing", "Keep editing")}
          loading={update.isPending}
          onConfirm={() => void submitEdit()}
        />

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
