"use client";

import { GitBranch } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { EmployeeSelect } from "@/components/shared/employee-select";
import { SimpleTable } from "@/components/shared/simple-table";
import {
  ActiveToggle,
  CrudField,
  CrudFieldRow,
  CrudSection,
  useCrudSection,
} from "@/components/shared/crud-section";
import {
  branchesApi,
  departmentsApi,
  type Department,
} from "@/features/organization/api";
import { useT } from "@/lib/i18n/useT";
import { ActiveBadge, noneToNull, useCanDeleteOrg } from "./shared";

const EMPTY = {
  name: "",
  name_am: "",
  code: "",
  branch_public_id: "none",
  parent_public_id: "none",
  head_employee_public_id: "none",
  is_active: true,
};

/**
 * The public ids of every department below `rootId`, from a flat list whose
 * rows carry their parent.
 */
export function descendantIds(
  items: { public_id: string; parent?: { public_id: string } | null }[],
  rootId: string,
): Set<string> {
  const childrenOf = new Map<string, string[]>();
  for (const d of items) {
    const parent = d.parent?.public_id;
    if (parent)
      childrenOf.set(parent, [...(childrenOf.get(parent) ?? []), d.public_id]);
  }
  const found = new Set<string>();
  const queue = [rootId];
  while (queue.length > 0) {
    for (const child of childrenOf.get(queue.pop() as string) ?? []) {
      if (!found.has(child)) {
        found.add(child);
        queue.push(child);
      }
    }
  }
  return found;
}

export function DepartmentsSection() {
  const { t } = useT();
  const canDelete = useCanDeleteOrg();
  const list = departmentsApi.useList();
  const { data: branches } = branchesApi.useList();
  const crud = useCrudSection({
    create: departmentsApi.useCreate(),
    update: departmentsApi.useUpdate(),
    remove: departmentsApi.useDelete(),
    empty: EMPTY,
    toForm: (d: Department) => ({
      name: d.name,
      name_am: d.name_am ?? "",
      code: d.code ?? "",
      branch_public_id: d.branch?.public_id ?? "none",
      parent_public_id: d.parent?.public_id ?? "none",
      head_employee_public_id: d.head?.public_id ?? "none",
      is_active: d.is_active,
    }),
    toPayload: (f) => ({
      name: f.name,
      name_am: f.name_am || null,
      code: f.code || null,
      branch_public_id: noneToNull(f.branch_public_id),
      parent_public_id: noneToNull(f.parent_public_id),
      head_employee_public_id: noneToNull(f.head_employee_public_id),
      is_active: f.is_active,
    }),
    messages: {
      created: t("org.department.created", "Department created"),
      updated: t("org.department.updated", "Department updated"),
      deleteTitle: (d) =>
        t("org.department.delete_confirm", 'Delete department ":name"?', {
          name: d.name,
        }),
    },
  });
  const { form, set, editing } = crud;
  const items = list.data?.data ?? [];
  // Its own sub-departments cannot be its parent: the API refuses that
  // cycle (N72), and offering them only to refuse is no help.
  const descendantsOfEditing = editing
    ? descendantIds(items, editing.public_id)
    : new Set<string>();

  return (
    <CrudSection
      crud={crud}
      title={t("org.tab.departments", "Departments")}
      query={list}
      isEmpty={items.length === 0}
      emptyIcon={GitBranch}
      emptyTitle={t("org.department.empty_title", "No departments")}
      emptyDescription={t(
        "org.department.empty_desc",
        "Create departments to organize your workforce",
      )}
      dialogTitle={{
        create: t("org.department.new_title", "New Department"),
        edit: t("org.department.edit_title", "Edit Department"),
      }}
      form={
        <>
          <CrudFieldRow>
            <CrudField label={t("org.field.name", "Name")} required>
              <Input
                value={form.name}
                onChange={(e) => set("name", e.target.value)}
                required
              />
            </CrudField>
            <CrudField label={t("org.field.name_am", "Name (Amharic)")}>
              <Input
                value={form.name_am}
                onChange={(e) => set("name_am", e.target.value)}
              />
            </CrudField>
            <CrudField label={t("org.field.code", "Code")}>
              <Input
                value={form.code}
                onChange={(e) => set("code", e.target.value)}
                placeholder="ENG, HR..."
              />
            </CrudField>
            <CrudField label={t("org.field.branch", "Branch")}>
              <Select
                value={form.branch_public_id}
                onValueChange={(v) => set("branch_public_id", v)}
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
            </CrudField>
            <CrudField
              label={t("org.field.parent_department", "Parent Department")}
            >
              <Select
                value={form.parent_public_id}
                onValueChange={(v) => set("parent_public_id", v)}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">
                    {t("org.field.none_top_level", "None (top-level)")}
                  </SelectItem>
                  {items
                    .filter(
                      (d) =>
                        d.public_id !== editing?.public_id &&
                        !descendantsOfEditing.has(d.public_id),
                    )
                    .map((d) => (
                      <SelectItem key={d.public_id} value={d.public_id}>
                        {d.name}
                      </SelectItem>
                    ))}
                </SelectContent>
              </Select>
            </CrudField>
            <CrudField
              label={t("org.field.department_head", "Department head")}
            >
              <EmployeeSelect
                value={form.head_employee_public_id}
                onChange={(v) => set("head_employee_public_id", v)}
                enabled={crud.isOpen}
                noneLabel={t("common.none", "None")}
              />
              <span className="mt-1 block text-xs text-muted-foreground">
                {t(
                  "org.field.department_head_hint",
                  "Approval chains with a department-head step send requests from this department to this person.",
                )}
              </span>
            </CrudField>
          </CrudFieldRow>
          <ActiveToggle
            checked={form.is_active}
            onChange={(v) => set("is_active", v)}
          />
        </>
      }
    >
      <Card>
        <CardContent className="p-0">
          <SimpleTable
            mobileCards
            headers={[
              t("org.field.name", "Name"),
              t("org.field.code", "Code"),
              t("org.tab.branches", "Branches"),
              t("org.field.parent", "Parent"),
              t("org.field.department_head", "Department head"),
              t("common.status", "Status"),
            ]}
            rows={items.map((d) => ({
              key: d.public_id,
              cells: [
                d.name,
                d.code ?? "—",
                d.branch?.name ?? "—",
                d.parent?.name ?? "—",
                d.head?.name ?? "—",
                <ActiveBadge key="s" active={d.is_active} />,
              ],
              onEdit: () => crud.openEdit(d),
              onDelete: canDelete ? () => crud.requestDelete(d) : undefined,
            }))}
          />
        </CardContent>
      </Card>
    </CrudSection>
  );
}
