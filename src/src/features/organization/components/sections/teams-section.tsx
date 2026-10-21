"use client";

import { Users2 } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { SimpleTable } from "@/components/shared/simple-table";
import {
  ActiveToggle,
  CrudField,
  CrudSection,
  useCrudSection,
} from "@/components/shared/crud-section";
import {
  departmentsApi,
  teamsApi,
  type Team,
} from "@/features/organization/api";
import { useT } from "@/lib/i18n/useT";
import { ActiveBadge, noneToNull, useCanDeleteOrg } from "./shared";

const EMPTY = {
  name: "",
  name_am: "",
  department_public_id: "none",
  is_active: true,
};

export function TeamsSection() {
  const { t } = useT();
  const canDelete = useCanDeleteOrg();
  const list = teamsApi.useList();
  const { data: departments } = departmentsApi.useList();
  const crud = useCrudSection({
    create: teamsApi.useCreate(),
    update: teamsApi.useUpdate(),
    remove: teamsApi.useDelete(),
    empty: EMPTY,
    toForm: (tm: Team) => ({
      name: tm.name,
      name_am: tm.name_am ?? "",
      department_public_id: tm.department?.public_id ?? "none",
      is_active: tm.is_active,
    }),
    toPayload: (f) => ({
      name: f.name,
      name_am: f.name_am || null,
      department_public_id: noneToNull(f.department_public_id),
      is_active: f.is_active,
    }),
    messages: {
      created: t("org.team.created", "Team created"),
      updated: t("org.team.updated", "Team updated"),
      deleteTitle: (tm) =>
        t("org.team.delete_confirm", 'Delete team ":name"?', {
          name: tm.name,
        }),
    },
  });
  const { form, set } = crud;
  const items = list.data?.data ?? [];

  return (
    <CrudSection
      crud={crud}
      title={t("org.tab.teams", "Teams")}
      query={list}
      isEmpty={items.length === 0}
      emptyIcon={Users2}
      emptyTitle={t("org.team.empty_title", "No teams")}
      emptyDescription={t(
        "org.team.empty_desc",
        "Create teams within departments for finer-grained reporting",
      )}
      dialogTitle={{
        create: t("org.team.new_title", "New Team"),
        edit: t("org.team.edit_title", "Edit Team"),
      }}
      form={
        <>
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
          <CrudField label={t("common.department", "Department")}>
            <Select
              value={form.department_public_id}
              onValueChange={(v) => set("department_public_id", v)}
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="none">{t("common.none", "None")}</SelectItem>
                {departments?.data?.map((d) => (
                  <SelectItem key={d.public_id} value={d.public_id}>
                    {d.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </CrudField>
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
              t("common.department", "Department"),
              t("common.status", "Status"),
            ]}
            rows={items.map((tm) => ({
              key: tm.public_id,
              cells: [
                tm.name,
                tm.department?.name ?? "—",
                <ActiveBadge key="s" active={tm.is_active} />,
              ],
              onEdit: () => crud.openEdit(tm),
              onDelete: canDelete ? () => crud.requestDelete(tm) : undefined,
            }))}
          />
        </CardContent>
      </Card>
    </CrudSection>
  );
}
