"use client";

import { useState } from "react";
import {
  Building2,
  GitBranch,
  Users2,
  Briefcase,
  Award,
  Wallet,
  Plus,
  Edit,
  Trash2,
  Loader2,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import { Textarea } from "@/components/ui/textarea";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
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
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { RoleGate } from "@/components/shared/role-gate";
import { CurrencyDisplay } from "@/components/shared/currency-display";
import {
  branchesApi,
  departmentsApi,
  teamsApi,
  positionsApi,
  gradesApi,
  costCentersApi,
  type Branch,
  type Department,
  type Team,
  type Position,
  type Grade,
  type CostCenter,
} from "@/features/organization/api";
import { toast } from "sonner";
import { useT } from "@/lib/i18n/useT";

export default function OrganizationPage() {
  const { t } = useT();
  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("org.title", "Organization")}
          description={t(
            "org.description",
            "Manage your organizational structure",
          )}
        />

        <Tabs defaultValue="branches">
          <TabsList className="flex flex-wrap h-auto">
            <TabsTrigger value="branches">
              <Building2 className="mr-1.5 h-3.5 w-3.5" />{" "}
              {t("org.tab.branches", "Branches")}
            </TabsTrigger>
            <TabsTrigger value="departments">
              <GitBranch className="mr-1.5 h-3.5 w-3.5" />{" "}
              {t("org.tab.departments", "Departments")}
            </TabsTrigger>
            <TabsTrigger value="teams">
              <Users2 className="mr-1.5 h-3.5 w-3.5" />{" "}
              {t("org.tab.teams", "Teams")}
            </TabsTrigger>
            <TabsTrigger value="positions">
              <Briefcase className="mr-1.5 h-3.5 w-3.5" />{" "}
              {t("org.tab.positions", "Positions")}
            </TabsTrigger>
            <TabsTrigger value="grades">
              <Award className="mr-1.5 h-3.5 w-3.5" />{" "}
              {t("org.tab.grades", "Grades")}
            </TabsTrigger>
            <TabsTrigger value="cost-centers">
              <Wallet className="mr-1.5 h-3.5 w-3.5" />{" "}
              {t("org.tab.cost_centers", "Cost Centers")}
            </TabsTrigger>
          </TabsList>

          <TabsContent value="branches" className="mt-4">
            <BranchesTab />
          </TabsContent>
          <TabsContent value="departments" className="mt-4">
            <DepartmentsTab />
          </TabsContent>
          <TabsContent value="teams" className="mt-4">
            <TeamsTab />
          </TabsContent>
          <TabsContent value="positions" className="mt-4">
            <PositionsTab />
          </TabsContent>
          <TabsContent value="grades" className="mt-4">
            <GradesTab />
          </TabsContent>
          <TabsContent value="cost-centers" className="mt-4">
            <CostCentersTab />
          </TabsContent>
        </Tabs>
      </div>
    </RoleGate>
  );
}

// ── BRANCHES ───────────────────────────────────────────────────

function BranchesTab() {
  const { t } = useT();
  const { data, isLoading } = branchesApi.useList();
  const createMut = branchesApi.useCreate();
  const updateMut = branchesApi.useUpdate();
  const deleteMut = branchesApi.useDelete();

  const [editing, setEditing] = useState<Branch | null>(null);
  const [isOpen, setIsOpen] = useState(false);
  const empty = {
    name: "",
    name_am: "",
    code: "",
    address: "",
    city: "",
    phone: "",
    latitude: "",
    longitude: "",
    geofence_radius_meters: "100",
    is_active: true,
  };
  const [form, setForm] = useState(empty);

  function openCreate() {
    setEditing(null);
    setForm(empty);
    setIsOpen(true);
  }
  function openEdit(b: Branch) {
    setEditing(b);
    setForm({
      name: b.name,
      name_am: b.name_am ?? "",
      code: b.code ?? "",
      address: b.address ?? "",
      city: b.city ?? "",
      phone: b.phone ?? "",
      latitude: b.latitude?.toString() ?? "",
      longitude: b.longitude?.toString() ?? "",
      geofence_radius_meters: b.geofence_radius_meters?.toString() ?? "100",
      is_active: b.is_active,
    });
    setIsOpen(true);
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const payload = {
      name: form.name,
      name_am: form.name_am || null,
      code: form.code || null,
      address: form.address || null,
      city: form.city || null,
      phone: form.phone || null,
      latitude: form.latitude ? parseFloat(form.latitude) : null,
      longitude: form.longitude ? parseFloat(form.longitude) : null,
      geofence_radius_meters: form.geofence_radius_meters
        ? parseInt(form.geofence_radius_meters)
        : null,
      is_active: form.is_active,
    };
    const op = editing
      ? updateMut.mutateAsync({ publicId: editing.public_id, payload })
      : createMut.mutateAsync(payload);
    op.then(() => {
      toast.success(
        editing
          ? t("org.branch.updated", "Branch updated")
          : t("org.branch.created", "Branch created"),
      );
      setIsOpen(false);
    }).catch(() => toast.error(t("org.save_failed", "Save failed")));
  }

  function handleDelete(b: Branch) {
    if (!confirm(`Delete branch "${b.name}"?`)) return;
    deleteMut.mutate(b.public_id, {
      onSuccess: () => toast.success(t("org.deleted", "Deleted")),
      onError: () => toast.error(t("org.delete_failed", "Delete failed")),
    });
  }

  const items = data?.data ?? [];
  return (
    <ResourceLayout
      title={t("org.tab.branches", "Branches")}
      onAdd={openCreate}
      isLoading={isLoading}
      isEmpty={items.length === 0}
      emptyIcon={Building2}
      emptyTitle={t("org.branch.empty_title", "No branches")}
      emptyDescription={t(
        "org.branch.empty_desc",
        "Add your first branch to organize multi-location operations",
      )}
    >
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {items.map((b) => (
          <Card key={b.public_id} className="hover:shadow-md transition-shadow">
            <CardContent className="p-4">
              <div className="flex items-start justify-between">
                <div className="min-w-0">
                  <p className="font-semibold text-foreground truncate">
                    {b.name}
                  </p>
                  {b.code && (
                    <p className="text-xs text-muted-foreground font-mono">
                      {b.code}
                    </p>
                  )}
                  {b.address && (
                    <p className="mt-1 text-xs text-muted-foreground line-clamp-2">
                      📍 {b.address}
                    </p>
                  )}
                  <div className="mt-2 flex flex-wrap gap-1">
                    {!b.is_active && (
                      <Badge variant="outline" className="text-[10px]">
                        {t("org.inactive", "Inactive")}
                      </Badge>
                    )}
                    {b.departments_count != null && (
                      <Badge variant="outline" className="text-[10px]">
                        {b.departments_count} dept
                      </Badge>
                    )}
                    {b.employees_count != null && (
                      <Badge variant="outline" className="text-[10px]">
                        {b.employees_count} emp
                      </Badge>
                    )}
                  </div>
                </div>
                <RowActions
                  onEdit={() => openEdit(b)}
                  onDelete={() => handleDelete(b)}
                />
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      <Dialog open={isOpen} onOpenChange={setIsOpen}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>
              {editing
                ? t("org.branch.edit_title", "Edit Branch")
                : t("org.branch.new_title", "New Branch")}
            </DialogTitle>
          </DialogHeader>
          <form onSubmit={handleSubmit} className="space-y-3">
            <div className="grid grid-cols-2 gap-3">
              <Field label={t("org.field.name", "Name") + " *"}>
                <Input
                  value={form.name}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, name: e.target.value }))
                  }
                  required
                />
              </Field>
              <Field label={t("org.field.name_am", "Name (Amharic)")}>
                <Input
                  value={form.name_am}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, name_am: e.target.value }))
                  }
                />
              </Field>
              <Field label={t("org.field.code", "Code")}>
                <Input
                  value={form.code}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, code: e.target.value }))
                  }
                  placeholder="HQ, BR01..."
                />
              </Field>
              <Field label={t("common.phone", "Phone")}>
                <Input
                  value={form.phone}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, phone: e.target.value }))
                  }
                />
              </Field>
            </div>
            <Field label={t("org.field.address", "Address")}>
              <Input
                value={form.address}
                onChange={(e) =>
                  setForm((p) => ({ ...p, address: e.target.value }))
                }
              />
            </Field>
            <Field label={t("org.field.city", "City")}>
              <Input
                value={form.city}
                onChange={(e) =>
                  setForm((p) => ({ ...p, city: e.target.value }))
                }
              />
            </Field>
            <div className="grid grid-cols-3 gap-3">
              <Field label={t("org.field.latitude", "Latitude")}>
                <Input
                  type="number"
                  step="any"
                  value={form.latitude}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, latitude: e.target.value }))
                  }
                />
              </Field>
              <Field label={t("org.field.longitude", "Longitude")}>
                <Input
                  type="number"
                  step="any"
                  value={form.longitude}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, longitude: e.target.value }))
                  }
                />
              </Field>
              <Field label={t("org.field.geofence", "Geofence (m)")}>
                <Input
                  type="number"
                  value={form.geofence_radius_meters}
                  onChange={(e) =>
                    setForm((p) => ({
                      ...p,
                      geofence_radius_meters: e.target.value,
                    }))
                  }
                />
              </Field>
            </div>
            <ActiveToggle
              checked={form.is_active}
              onChange={(v) => setForm((p) => ({ ...p, is_active: v }))}
            />
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setIsOpen(false)}
              >
                {t("common.cancel", "Cancel")}
              </Button>
              <SaveButton
                isPending={createMut.isPending || updateMut.isPending}
              />
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </ResourceLayout>
  );
}

// ── DEPARTMENTS ────────────────────────────────────────────────

function DepartmentsTab() {
  const { t } = useT();
  const { data, isLoading } = departmentsApi.useList();
  const { data: branches } = branchesApi.useList();
  const createMut = departmentsApi.useCreate();
  const updateMut = departmentsApi.useUpdate();
  const deleteMut = departmentsApi.useDelete();

  const [editing, setEditing] = useState<Department | null>(null);
  const [isOpen, setIsOpen] = useState(false);
  const empty = {
    name: "",
    name_am: "",
    code: "",
    branch_public_id: "none",
    parent_public_id: "none",
    is_active: true,
  };
  const [form, setForm] = useState(empty);

  function openCreate() {
    setEditing(null);
    setForm(empty);
    setIsOpen(true);
  }
  function openEdit(d: Department) {
    setEditing(d);
    setForm({
      name: d.name,
      name_am: d.name_am ?? "",
      code: d.code ?? "",
      branch_public_id: d.branch?.public_id ?? "none",
      parent_public_id: d.parent?.public_id ?? "none",
      is_active: d.is_active,
    });
    setIsOpen(true);
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const payload = {
      name: form.name,
      name_am: form.name_am || null,
      code: form.code || null,
      branch_public_id:
        form.branch_public_id === "none" ? null : form.branch_public_id,
      parent_public_id:
        form.parent_public_id === "none" ? null : form.parent_public_id,
      is_active: form.is_active,
    };
    const op = editing
      ? updateMut.mutateAsync({ publicId: editing.public_id, payload })
      : createMut.mutateAsync(payload);
    op.then(() => {
      toast.success(
        editing
          ? t("org.department.updated", "Department updated")
          : t("org.department.created", "Department created"),
      );
      setIsOpen(false);
    }).catch(() => toast.error(t("org.save_failed", "Save failed")));
  }

  function handleDelete(d: Department) {
    if (!confirm(`Delete department "${d.name}"?`)) return;
    deleteMut.mutate(d.public_id, {
      onSuccess: () => toast.success(t("org.deleted", "Deleted")),
      onError: () => toast.error(t("org.delete_failed", "Delete failed")),
    });
  }

  const items = data?.data ?? [];
  return (
    <ResourceLayout
      title={t("org.tab.departments", "Departments")}
      onAdd={openCreate}
      isLoading={isLoading}
      isEmpty={items.length === 0}
      emptyIcon={GitBranch}
      emptyTitle={t("org.department.empty_title", "No departments")}
      emptyDescription={t(
        "org.department.empty_desc",
        "Create departments to organize your workforce",
      )}
    >
      <Card>
        <CardContent className="p-0">
          <ResourceTable
            headers={[
              t("org.field.name", "Name"),
              t("org.field.code", "Code"),
              t("org.tab.branches", "Branches"),
              t("org.field.parent", "Parent"),
              t("common.status", "Status"),
            ]}
            rows={items.map((d) => ({
              key: d.public_id,
              cells: [
                d.name,
                d.code ?? "—",
                d.branch?.name ?? "—",
                d.parent?.name ?? "—",
                <Badge
                  key="s"
                  variant="outline"
                  className={
                    d.is_active
                      ? "bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300 border-0"
                      : ""
                  }
                >
                  {d.is_active
                    ? t("org.active", "Active")
                    : t("org.inactive", "Inactive")}
                </Badge>,
              ],
              onEdit: () => openEdit(d),
              onDelete: () => handleDelete(d),
            }))}
          />
        </CardContent>
      </Card>

      <Dialog open={isOpen} onOpenChange={setIsOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {editing
                ? t("org.department.edit_title", "Edit Department")
                : t("org.department.new_title", "New Department")}
            </DialogTitle>
          </DialogHeader>
          <form onSubmit={handleSubmit} className="space-y-3">
            <div className="grid grid-cols-2 gap-3">
              <Field label={t("org.field.name", "Name") + " *"}>
                <Input
                  value={form.name}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, name: e.target.value }))
                  }
                  required
                />
              </Field>
              <Field label={t("org.field.name_am", "Name (Amharic)")}>
                <Input
                  value={form.name_am}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, name_am: e.target.value }))
                  }
                />
              </Field>
              <Field label={t("org.field.code", "Code")}>
                <Input
                  value={form.code}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, code: e.target.value }))
                  }
                  placeholder="ENG, HR..."
                />
              </Field>
              <Field label={t("org.field.branch", "Branch")}>
                <Select
                  value={form.branch_public_id}
                  onValueChange={(v) =>
                    setForm((p) => ({ ...p, branch_public_id: v }))
                  }
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">
                      {t("common.none", "None")}
                    </SelectItem>
                    {branches?.data?.map((b) => (
                      <SelectItem key={b.public_id} value={b.public_id}>
                        {b.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
              <Field
                label={t("org.field.parent_department", "Parent Department")}
              >
                <Select
                  value={form.parent_public_id}
                  onValueChange={(v) =>
                    setForm((p) => ({ ...p, parent_public_id: v }))
                  }
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">
                      {t("org.field.none_top_level", "None (top-level)")}
                    </SelectItem>
                    {items
                      .filter((d) => d.public_id !== editing?.public_id)
                      .map((d) => (
                        <SelectItem key={d.public_id} value={d.public_id}>
                          {d.name}
                        </SelectItem>
                      ))}
                  </SelectContent>
                </Select>
              </Field>
            </div>
            <ActiveToggle
              checked={form.is_active}
              onChange={(v) => setForm((p) => ({ ...p, is_active: v }))}
            />
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setIsOpen(false)}
              >
                {t("common.cancel", "Cancel")}
              </Button>
              <SaveButton
                isPending={createMut.isPending || updateMut.isPending}
              />
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </ResourceLayout>
  );
}

// ── TEAMS ──────────────────────────────────────────────────────

function TeamsTab() {
  const { t } = useT();
  const { data, isLoading } = teamsApi.useList();
  const { data: departments } = departmentsApi.useList();
  const createMut = teamsApi.useCreate();
  const updateMut = teamsApi.useUpdate();
  const deleteMut = teamsApi.useDelete();

  const [editing, setEditing] = useState<Team | null>(null);
  const [isOpen, setIsOpen] = useState(false);
  const empty = {
    name: "",
    name_am: "",
    department_public_id: "none",
    is_active: true,
  };
  const [form, setForm] = useState(empty);

  function openCreate() {
    setEditing(null);
    setForm(empty);
    setIsOpen(true);
  }
  function openEdit(tm: Team) {
    setEditing(tm);
    setForm({
      name: tm.name,
      name_am: tm.name_am ?? "",
      department_public_id: tm.department?.public_id ?? "none",
      is_active: tm.is_active,
    });
    setIsOpen(true);
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const payload = {
      name: form.name,
      name_am: form.name_am || null,
      department_public_id:
        form.department_public_id === "none" ? null : form.department_public_id,
      is_active: form.is_active,
    };
    const op = editing
      ? updateMut.mutateAsync({ publicId: editing.public_id, payload })
      : createMut.mutateAsync(payload);
    op.then(() => {
      toast.success(
        editing
          ? t("org.team.updated", "Team updated")
          : t("org.team.created", "Team created"),
      );
      setIsOpen(false);
    }).catch(() => toast.error(t("org.save_failed", "Save failed")));
  }

  function handleDelete(tm: Team) {
    if (!confirm(`Delete team "${tm.name}"?`)) return;
    deleteMut.mutate(tm.public_id, {
      onSuccess: () => toast.success(t("org.deleted", "Deleted")),
      onError: () => toast.error(t("org.delete_failed", "Delete failed")),
    });
  }

  const items = data?.data ?? [];
  return (
    <ResourceLayout
      title={t("org.tab.teams", "Teams")}
      onAdd={openCreate}
      isLoading={isLoading}
      isEmpty={items.length === 0}
      emptyIcon={Users2}
      emptyTitle={t("org.team.empty_title", "No teams")}
      emptyDescription={t(
        "org.team.empty_desc",
        "Create teams within departments for finer-grained reporting",
      )}
    >
      <Card>
        <CardContent className="p-0">
          <ResourceTable
            headers={[
              t("org.field.name", "Name"),
              t("common.department", "Department"),
              t("common.status", "Status"),
            ]}
            rows={items.map((tm) => ({
              key: tm.public_id,
              cells: [
                tm.name,
                tm.department?.name ?? "—",
                <Badge
                  key="s"
                  variant="outline"
                  className={
                    tm.is_active
                      ? "bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300 border-0"
                      : ""
                  }
                >
                  {tm.is_active
                    ? t("org.active", "Active")
                    : t("org.inactive", "Inactive")}
                </Badge>,
              ],
              onEdit: () => openEdit(tm),
              onDelete: () => handleDelete(tm),
            }))}
          />
        </CardContent>
      </Card>

      <Dialog open={isOpen} onOpenChange={setIsOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {editing
                ? t("org.team.edit_title", "Edit Team")
                : t("org.team.new_title", "New Team")}
            </DialogTitle>
          </DialogHeader>
          <form onSubmit={handleSubmit} className="space-y-3">
            <Field label={t("org.field.name", "Name") + " *"}>
              <Input
                value={form.name}
                onChange={(e) =>
                  setForm((p) => ({ ...p, name: e.target.value }))
                }
                required
              />
            </Field>
            <Field label={t("org.field.name_am", "Name (Amharic)")}>
              <Input
                value={form.name_am}
                onChange={(e) =>
                  setForm((p) => ({ ...p, name_am: e.target.value }))
                }
              />
            </Field>
            <Field label={t("common.department", "Department")}>
              <Select
                value={form.department_public_id}
                onValueChange={(v) =>
                  setForm((p) => ({ ...p, department_public_id: v }))
                }
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">
                    {t("common.none", "None")}
                  </SelectItem>
                  {departments?.data?.map((d) => (
                    <SelectItem key={d.public_id} value={d.public_id}>
                      {d.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>
            <ActiveToggle
              checked={form.is_active}
              onChange={(v) => setForm((p) => ({ ...p, is_active: v }))}
            />
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setIsOpen(false)}
              >
                {t("common.cancel", "Cancel")}
              </Button>
              <SaveButton
                isPending={createMut.isPending || updateMut.isPending}
              />
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </ResourceLayout>
  );
}

// ── POSITIONS ──────────────────────────────────────────────────

function PositionsTab() {
  const { t } = useT();
  const { data, isLoading } = positionsApi.useList();
  const createMut = positionsApi.useCreate();
  const updateMut = positionsApi.useUpdate();
  const deleteMut = positionsApi.useDelete();

  const [editing, setEditing] = useState<Position | null>(null);
  const [isOpen, setIsOpen] = useState(false);
  const empty = {
    title: "",
    title_am: "",
    code: "",
    description: "",
    is_active: true,
  };
  const [form, setForm] = useState(empty);

  function openCreate() {
    setEditing(null);
    setForm(empty);
    setIsOpen(true);
  }
  function openEdit(p: Position) {
    setEditing(p);
    setForm({
      title: p.title,
      title_am: p.title_am ?? "",
      code: p.code ?? "",
      description: p.description ?? "",
      is_active: p.is_active,
    });
    setIsOpen(true);
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const payload = {
      title: form.title,
      title_am: form.title_am || null,
      code: form.code || null,
      description: form.description || null,
      is_active: form.is_active,
    };
    const op = editing
      ? updateMut.mutateAsync({ publicId: editing.public_id, payload })
      : createMut.mutateAsync(payload);
    op.then(() => {
      toast.success(
        editing
          ? t("org.position.updated", "Position updated")
          : t("org.position.created", "Position created"),
      );
      setIsOpen(false);
    }).catch(() => toast.error(t("org.save_failed", "Save failed")));
  }

  function handleDelete(p: Position) {
    if (!confirm(`Delete position "${p.title}"?`)) return;
    deleteMut.mutate(p.public_id, {
      onSuccess: () => toast.success(t("org.deleted", "Deleted")),
      onError: () => toast.error(t("org.delete_failed", "Delete failed")),
    });
  }

  const items = data?.data ?? [];
  return (
    <ResourceLayout
      title={t("org.tab.positions", "Positions")}
      onAdd={openCreate}
      isLoading={isLoading}
      isEmpty={items.length === 0}
      emptyIcon={Briefcase}
      emptyTitle={t("org.position.empty_title", "No positions")}
      emptyDescription={t(
        "org.position.empty_desc",
        "Define job titles employees can be assigned to",
      )}
    >
      <Card>
        <CardContent className="p-0">
          <ResourceTable
            headers={[
              t("org.field.title", "Title"),
              t("org.field.code", "Code"),
              t("org.field.employees", "Employees"),
              t("common.status", "Status"),
            ]}
            rows={items.map((p) => ({
              key: p.public_id,
              cells: [
                <div key="t">
                  <div className="font-medium">{p.title}</div>
                  {p.description && (
                    <div className="text-xs text-muted-foreground line-clamp-1">
                      {p.description}
                    </div>
                  )}
                </div>,
                p.code ?? "—",
                p.employees_count ?? 0,
                <Badge
                  key="s"
                  variant="outline"
                  className={
                    p.is_active
                      ? "bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300 border-0"
                      : ""
                  }
                >
                  {p.is_active
                    ? t("org.active", "Active")
                    : t("org.inactive", "Inactive")}
                </Badge>,
              ],
              onEdit: () => openEdit(p),
              onDelete: () => handleDelete(p),
            }))}
          />
        </CardContent>
      </Card>

      <Dialog open={isOpen} onOpenChange={setIsOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {editing
                ? t("org.position.edit_title", "Edit Position")
                : t("org.position.new_title", "New Position")}
            </DialogTitle>
          </DialogHeader>
          <form onSubmit={handleSubmit} className="space-y-3">
            <div className="grid grid-cols-2 gap-3">
              <Field label={t("org.field.title", "Title") + " *"}>
                <Input
                  value={form.title}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, title: e.target.value }))
                  }
                  required
                />
              </Field>
              <Field label={t("org.field.title_am", "Title (Amharic)")}>
                <Input
                  value={form.title_am}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, title_am: e.target.value }))
                  }
                />
              </Field>
            </div>
            <Field label={t("org.field.code", "Code")}>
              <Input
                value={form.code}
                onChange={(e) =>
                  setForm((p) => ({ ...p, code: e.target.value }))
                }
                placeholder="DEV, MGR..."
              />
            </Field>
            <Field label={t("org.field.description", "Description")}>
              <Textarea
                value={form.description}
                onChange={(e) =>
                  setForm((p) => ({ ...p, description: e.target.value }))
                }
                rows={3}
              />
            </Field>
            <ActiveToggle
              checked={form.is_active}
              onChange={(v) => setForm((p) => ({ ...p, is_active: v }))}
            />
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setIsOpen(false)}
              >
                {t("common.cancel", "Cancel")}
              </Button>
              <SaveButton
                isPending={createMut.isPending || updateMut.isPending}
              />
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </ResourceLayout>
  );
}

// ── GRADES ─────────────────────────────────────────────────────

function GradesTab() {
  const { t } = useT();
  const { data, isLoading } = gradesApi.useList();
  const createMut = gradesApi.useCreate();
  const updateMut = gradesApi.useUpdate();
  const deleteMut = gradesApi.useDelete();

  const [editing, setEditing] = useState<Grade | null>(null);
  const [isOpen, setIsOpen] = useState(false);
  const empty = { name: "", min_salary: "", max_salary: "", sort_order: "0" };
  const [form, setForm] = useState(empty);

  function openCreate() {
    setEditing(null);
    setForm(empty);
    setIsOpen(true);
  }
  function openEdit(g: Grade) {
    setEditing(g);
    setForm({
      name: g.name,
      min_salary: (g.min_salary_cents / 100).toString(),
      max_salary: (g.max_salary_cents / 100).toString(),
      sort_order: (g.sort_order ?? 0).toString(),
    });
    setIsOpen(true);
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const payload = {
      name: form.name,
      min_salary_cents: Math.round(parseFloat(form.min_salary) * 100),
      max_salary_cents: Math.round(parseFloat(form.max_salary) * 100),
      sort_order: parseInt(form.sort_order || "0"),
    };
    const op = editing
      ? updateMut.mutateAsync({ publicId: editing.public_id, payload })
      : createMut.mutateAsync(payload);
    op.then(() => {
      toast.success(
        editing
          ? t("org.grade.updated", "Grade updated")
          : t("org.grade.created", "Grade created"),
      );
      setIsOpen(false);
    }).catch(() => toast.error(t("org.save_failed", "Save failed")));
  }

  function handleDelete(g: Grade) {
    if (!confirm(`Delete grade "${g.name}"?`)) return;
    deleteMut.mutate(g.public_id, {
      onSuccess: () => toast.success(t("org.deleted", "Deleted")),
      onError: () => toast.error(t("org.delete_failed", "Delete failed")),
    });
  }

  const items = (data?.data ?? [])
    .slice()
    .sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0));
  return (
    <ResourceLayout
      title={t("org.tab.grades", "Grades")}
      onAdd={openCreate}
      isLoading={isLoading}
      isEmpty={items.length === 0}
      emptyIcon={Award}
      emptyTitle={t("org.grade.empty_title", "No grades")}
      emptyDescription={t(
        "org.grade.empty_desc",
        "Define salary grade bands for your organization",
      )}
    >
      <Card>
        <CardContent className="p-0">
          <ResourceTable
            headers={[
              t("org.tab.grades", "Grades"),
              t("org.field.min_salary", "Min Salary"),
              t("org.field.max_salary", "Max Salary"),
              t("org.field.order", "Order"),
            ]}
            rows={items.map((g) => ({
              key: g.public_id,
              cells: [
                g.name,
                <CurrencyDisplay key="min" cents={g.min_salary_cents} />,
                <CurrencyDisplay key="max" cents={g.max_salary_cents} />,
                g.sort_order ?? 0,
              ],
              onEdit: () => openEdit(g),
              onDelete: () => handleDelete(g),
            }))}
          />
        </CardContent>
      </Card>

      <Dialog open={isOpen} onOpenChange={setIsOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {editing
                ? t("org.grade.edit_title", "Edit Grade")
                : t("org.grade.new_title", "New Grade")}
            </DialogTitle>
          </DialogHeader>
          <form onSubmit={handleSubmit} className="space-y-3">
            <Field label={t("org.field.grade_name", "Grade Name") + " *"}>
              <Input
                value={form.name}
                onChange={(e) =>
                  setForm((p) => ({ ...p, name: e.target.value }))
                }
                required
                placeholder="G1, Manager I, Level 5..."
              />
            </Field>
            <div className="grid grid-cols-2 gap-3">
              <Field
                label={t("org.field.min_salary", "Min Salary (ETB)") + " *"}
              >
                <Input
                  type="number"
                  step="0.01"
                  value={form.min_salary}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, min_salary: e.target.value }))
                  }
                  required
                />
              </Field>
              <Field
                label={t("org.field.max_salary", "Max Salary (ETB)") + " *"}
              >
                <Input
                  type="number"
                  step="0.01"
                  value={form.max_salary}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, max_salary: e.target.value }))
                  }
                  required
                />
              </Field>
            </div>
            <Field label={t("org.field.sort_order", "Sort Order")}>
              <Input
                type="number"
                value={form.sort_order}
                onChange={(e) =>
                  setForm((p) => ({ ...p, sort_order: e.target.value }))
                }
              />
            </Field>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setIsOpen(false)}
              >
                {t("common.cancel", "Cancel")}
              </Button>
              <SaveButton
                isPending={createMut.isPending || updateMut.isPending}
              />
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </ResourceLayout>
  );
}

// ── COST CENTERS ───────────────────────────────────────────────

function CostCentersTab() {
  const { t } = useT();
  const { data, isLoading } = costCentersApi.useList();
  const createMut = costCentersApi.useCreate();
  const updateMut = costCentersApi.useUpdate();
  const deleteMut = costCentersApi.useDelete();

  const [editing, setEditing] = useState<CostCenter | null>(null);
  const [isOpen, setIsOpen] = useState(false);
  const empty = { name: "", code: "", is_active: true };
  const [form, setForm] = useState(empty);

  function openCreate() {
    setEditing(null);
    setForm(empty);
    setIsOpen(true);
  }
  function openEdit(c: CostCenter) {
    setEditing(c);
    setForm({ name: c.name, code: c.code ?? "", is_active: c.is_active });
    setIsOpen(true);
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const payload = {
      name: form.name,
      code: form.code || null,
      is_active: form.is_active,
    };
    const op = editing
      ? updateMut.mutateAsync({ publicId: editing.public_id, payload })
      : createMut.mutateAsync(payload);
    op.then(() => {
      toast.success(
        editing
          ? t("org.cost_center.updated", "Cost center updated")
          : t("org.cost_center.created", "Cost center created"),
      );
      setIsOpen(false);
    }).catch(() => toast.error(t("org.save_failed", "Save failed")));
  }

  function handleDelete(c: CostCenter) {
    if (!confirm(`Delete cost center "${c.name}"?`)) return;
    deleteMut.mutate(c.public_id, {
      onSuccess: () => toast.success(t("org.deleted", "Deleted")),
      onError: () => toast.error(t("org.delete_failed", "Delete failed")),
    });
  }

  const items = data?.data ?? [];
  return (
    <ResourceLayout
      title={t("org.tab.cost_centers", "Cost Centers")}
      onAdd={openCreate}
      isLoading={isLoading}
      isEmpty={items.length === 0}
      emptyIcon={Wallet}
      emptyTitle={t("org.cost_center.empty_title", "No cost centers")}
      emptyDescription={t(
        "org.cost_center.empty_desc",
        "Cost centers help allocate payroll expenses across the organization",
      )}
    >
      <Card>
        <CardContent className="p-0">
          <ResourceTable
            headers={[
              t("org.field.name", "Name"),
              t("org.field.code", "Code"),
              t("common.status", "Status"),
            ]}
            rows={items.map((c) => ({
              key: c.public_id,
              cells: [
                c.name,
                c.code ?? "—",
                <Badge
                  key="s"
                  variant="outline"
                  className={
                    c.is_active
                      ? "bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300 border-0"
                      : ""
                  }
                >
                  {c.is_active
                    ? t("org.active", "Active")
                    : t("org.inactive", "Inactive")}
                </Badge>,
              ],
              onEdit: () => openEdit(c),
              onDelete: () => handleDelete(c),
            }))}
          />
        </CardContent>
      </Card>

      <Dialog open={isOpen} onOpenChange={setIsOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {editing
                ? t("org.cost_center.edit_title", "Edit Cost Center")
                : t("org.cost_center.new_title", "New Cost Center")}
            </DialogTitle>
          </DialogHeader>
          <form onSubmit={handleSubmit} className="space-y-3">
            <Field label={t("org.field.name", "Name") + " *"}>
              <Input
                value={form.name}
                onChange={(e) =>
                  setForm((p) => ({ ...p, name: e.target.value }))
                }
                required
              />
            </Field>
            <Field label={t("org.field.code", "Code")}>
              <Input
                value={form.code}
                onChange={(e) =>
                  setForm((p) => ({ ...p, code: e.target.value }))
                }
                placeholder="CC-001..."
              />
            </Field>
            <ActiveToggle
              checked={form.is_active}
              onChange={(v) => setForm((p) => ({ ...p, is_active: v }))}
            />
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setIsOpen(false)}
              >
                {t("common.cancel", "Cancel")}
              </Button>
              <SaveButton
                isPending={createMut.isPending || updateMut.isPending}
              />
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </ResourceLayout>
  );
}

// ── SHARED PIECES ──────────────────────────────────────────────

function ResourceLayout({
  title,
  onAdd,
  isLoading,
  isEmpty,
  emptyIcon,
  emptyTitle,
  emptyDescription,
  children,
}: {
  title: string;
  onAdd: () => void;
  isLoading: boolean;
  isEmpty: boolean;
  emptyIcon: React.ComponentType<{ className?: string }>;
  emptyTitle: string;
  emptyDescription: string;
  children: React.ReactNode;
}) {
  const { t } = useT();
  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-semibold">{title}</h2>
        <Button size="sm" onClick={onAdd}>
          <Plus className="mr-2 h-4 w-4" /> {t("common.add", "Add")}
        </Button>
      </div>

      {isLoading ? (
        <div className="space-y-2">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-16" />
          ))}
        </div>
      ) : isEmpty ? (
        <EmptyState
          icon={emptyIcon}
          title={emptyTitle}
          description={emptyDescription}
          action={
            <Button onClick={onAdd}>
              <Plus className="mr-2 h-4 w-4" />{" "}
              {t("org.add_first", "Add first one")}
            </Button>
          }
        />
      ) : (
        children
      )}
    </div>
  );
}

function ResourceTable({
  headers,
  rows,
}: {
  headers: string[];
  rows: Array<{
    key: string;
    cells: React.ReactNode[];
    onEdit: () => void;
    onDelete: () => void;
  }>;
}) {
  const { t } = useT();
  return (
    <div className="overflow-x-auto">
      <table className="w-full">
        <thead>
          <tr className="border-b bg-muted/50">
            {headers.map((h) => (
              <th
                key={h}
                className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground"
              >
                {h}
              </th>
            ))}
            <th className="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-muted-foreground">
              {t("common.actions", "Actions")}
            </th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr
              key={row.key}
              className="border-b last:border-0 hover:bg-muted/30"
            >
              {row.cells.map((c, i) => (
                <td key={i} className="px-4 py-3 text-sm text-foreground">
                  {c}
                </td>
              ))}
              <td className="px-4 py-3 text-right">
                <RowActions onEdit={row.onEdit} onDelete={row.onDelete} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function RowActions({
  onEdit,
  onDelete,
}: {
  onEdit: () => void;
  onDelete: () => void;
}) {
  return (
    <div className="flex justify-end gap-1">
      <Button
        size="sm"
        variant="ghost"
        className="h-7 w-7 p-0"
        onClick={onEdit}
      >
        <Edit className="h-3.5 w-3.5" />
      </Button>
      <Button
        size="sm"
        variant="ghost"
        className="h-7 w-7 p-0"
        onClick={onDelete}
      >
        <Trash2 className="h-3.5 w-3.5 text-destructive" />
      </Button>
    </div>
  );
}

function Field({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) {
  return (
    <div>
      <Label className="text-xs">{label}</Label>
      <div className="mt-1">{children}</div>
    </div>
  );
}

function ActiveToggle({
  checked,
  onChange,
}: {
  checked: boolean;
  onChange: (v: boolean) => void;
}) {
  const { t } = useT();
  return (
    <label className="flex cursor-pointer items-center gap-2 rounded-lg border p-3 hover:bg-muted/50">
      <input
        type="checkbox"
        checked={checked}
        onChange={(e) => onChange(e.target.checked)}
        className="h-4 w-4 rounded"
      />
      <span className="text-sm font-medium">{t("org.active", "Active")}</span>
      <span className="text-xs text-muted-foreground">
        {t(
          "org.active_desc",
          "— inactive items are hidden from selection menus",
        )}
      </span>
    </label>
  );
}

function SaveButton({ isPending }: { isPending: boolean }) {
  const { t } = useT();
  return (
    <Button type="submit" disabled={isPending}>
      {isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
      {t("common.save", "Save")}
    </Button>
  );
}
