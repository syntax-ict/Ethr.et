"use client";

import { Briefcase } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Button } from "@/components/ui/button";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Label } from "@/components/ui/label";
import { SimpleTable } from "@/components/shared/simple-table";
import {
  ActiveToggle,
  CrudField,
  CrudFieldRow,
  CrudSection,
  useCrudSection,
} from "@/components/shared/crud-section";
import { positionsApi, type Position } from "@/features/organization/api";
import { useT } from "@/lib/i18n/useT";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { ActiveBadge, useCanDeleteOrg } from "./shared";

/** The roles a job title can give; the organisation admin's is only ever given by hand. */
const ACCESS_ROLES = [
  "supervisor",
  "dept_admin",
  "hr_admin",
  "finance_admin",
] as const;

type AccessRole = (typeof ACCESS_ROLES)[number];

/** The select's "no access" value: Radix items cannot carry an empty string. */
const NO_ACCESS = "none";

const ENGLISH_ROLE_LABELS: Record<AccessRole, string> = {
  supervisor: "Supervisor",
  dept_admin: "Department admin",
  hr_admin: "HR admin",
  finance_admin: "Finance admin",
};

const EMPTY = {
  title: "",
  title_am: "",
  code: "",
  description: "",
  is_active: true,
  access: NO_ACCESS as string,
};

export function PositionsSection() {
  const { t } = useT();
  const canDelete = useCanDeleteOrg();
  const { can } = usePermissions();
  const canSetAccess = can.updateUsers;
  const list = positionsApi.useList();
  const crud = useCrudSection({
    create: positionsApi.useCreate(),
    update: positionsApi.useUpdate(),
    remove: positionsApi.useDelete(),
    empty: EMPTY,
    toForm: (p: Position) => ({
      title: p.title,
      title_am: p.title_am ?? "",
      code: p.code ?? "",
      description: p.description ?? "",
      is_active: p.is_active,
      access: p.access_role ?? NO_ACCESS,
    }),
    toPayload: (f) => ({
      title: f.title,
      title_am: f.title_am || null,
      code: f.code || null,
      description: f.description || null,
      is_active: f.is_active,
      ...(canSetAccess
        ? {
            access_role:
              f.access === NO_ACCESS ? null : (f.access as AccessRole),
          }
        : {}),
    }),
    messages: {
      created: t("org.position.created", "Position created"),
      updated: t("org.position.updated", "Position updated"),
      deleteTitle: (p) =>
        t("org.position.delete_confirm", 'Delete position ":name"?', {
          name: p.title,
        }),
    },
  });
  const { form, set } = crud;
  const items = list.data?.data ?? [];
  const roleLabel = (r: AccessRole) => t(`role.${r}`, ENGLISH_ROLE_LABELS[r]);

  // The saved value of the title being edited, and how many people hold it:
  // changing the access moves every account that follows the title.
  const saved = crud.editing?.access_role ?? NO_ACCESS;
  const holders = crud.editing?.employees_count ?? 0;
  const accessChanged = crud.editing !== null && form.access !== saved;

  // A title that is already a role's name ("Supervisor") probably means that
  // access. Offered, never chosen for the person.
  const typed = [form.title, form.title_am]
    .map((v) => v.trim().toLowerCase())
    .filter((v) => v !== "");
  const suggested =
    form.access === NO_ACCESS
      ? ACCESS_ROLES.find((r) =>
          [roleLabel(r), ENGLISH_ROLE_LABELS[r]].some((label) =>
            typed.includes(label.toLowerCase()),
          ),
        )
      : undefined;

  return (
    <CrudSection
      crud={crud}
      title={t("org.tab.positions", "Positions")}
      query={list}
      isEmpty={items.length === 0}
      emptyIcon={Briefcase}
      emptyTitle={t("org.position.empty_title", "No positions")}
      emptyDescription={t(
        "org.position.empty_desc",
        "Define job titles employees can be assigned to",
      )}
      dialogTitle={{
        create: t("org.position.new_title", "New Position"),
        edit: t("org.position.edit_title", "Edit Position"),
      }}
      form={
        <>
          <CrudFieldRow>
            <CrudField label={t("org.field.title", "Title")} required>
              <Input
                value={form.title}
                onChange={(e) => set("title", e.target.value)}
                required
              />
            </CrudField>
            <CrudField label={t("org.field.title_am", "Title (Amharic)")}>
              <Input
                value={form.title_am}
                onChange={(e) => set("title_am", e.target.value)}
              />
            </CrudField>
          </CrudFieldRow>
          <CrudField label={t("org.field.code", "Code")}>
            <Input
              value={form.code}
              onChange={(e) => set("code", e.target.value)}
              placeholder="DEV, MGR..."
            />
          </CrudField>
          <CrudField label={t("org.field.description", "Description")}>
            <Textarea
              value={form.description}
              onChange={(e) => set("description", e.target.value)}
              rows={3}
            />
          </CrudField>
          {canSetAccess && (
            <div className="space-y-2">
              <Label htmlFor="position-access">
                {t("org.position.access", "Access")}
              </Label>
              <Select
                value={form.access}
                onValueChange={(v) => set("access", v)}
              >
                <SelectTrigger id="position-access">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={NO_ACCESS}>
                    {t("org.position.access_none", "Employee access only")}
                  </SelectItem>
                  {ACCESS_ROLES.map((r) => (
                    <SelectItem key={r} value={r}>
                      {roleLabel(r)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {suggested && (
                <div className="flex flex-wrap items-center gap-2 text-sm">
                  <span>
                    {t(
                      "org.position.access_suggest",
                      "Give this job title :role access?",
                      { role: roleLabel(suggested) },
                    )}
                  </span>
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => set("access", suggested)}
                  >
                    {t("org.position.access_suggest_yes", "Yes, give it")}
                  </Button>
                </div>
              )}
              {accessChanged && holders > 0 && (
                <p className="text-sm text-muted-foreground">
                  {t(
                    "org.position.access_note",
                    ":count people hold this job title. Accounts that follow the job title change when you save. Accounts given a role by hand stay as they are.",
                    { count: holders },
                  )}
                </p>
              )}
            </div>
          )}
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
              t("org.field.title", "Title"),
              t("org.field.code", "Code"),
              t("org.field.employees", "Employees"),
              t("org.position.access", "Access"),
              t("common.status", "Status"),
            ]}
            rows={items.map((p) => ({
              key: p.public_id,
              cells: [
                <div key="t">
                  <div className="font-medium">{p.title}</div>
                  {p.description && (
                    <div className="line-clamp-1 text-xs text-muted-foreground">
                      {p.description}
                    </div>
                  )}
                </div>,
                p.code ?? "—",
                p.employees_count ?? 0,
                p.access_role
                  ? roleLabel(p.access_role as AccessRole)
                  : t("org.position.access_none", "Employee access only"),
                <ActiveBadge key="s" active={p.is_active} />,
              ],
              onEdit: () => crud.openEdit(p),
              onDelete: canDelete ? () => crud.requestDelete(p) : undefined,
            }))}
          />
        </CardContent>
      </Card>
    </CrudSection>
  );
}
