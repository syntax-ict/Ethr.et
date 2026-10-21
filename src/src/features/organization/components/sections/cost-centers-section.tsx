"use client";

import { Wallet } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { SimpleTable } from "@/components/shared/simple-table";
import {
  ActiveToggle,
  CrudField,
  CrudSection,
  useCrudSection,
} from "@/components/shared/crud-section";
import { costCentersApi, type CostCenter } from "@/features/organization/api";
import { useT } from "@/lib/i18n/useT";
import { ActiveBadge, useCanDeleteOrg } from "./shared";

const EMPTY = { name: "", code: "", is_active: true };

export function CostCentersSection() {
  const { t } = useT();
  const canDelete = useCanDeleteOrg();
  const list = costCentersApi.useList();
  const crud = useCrudSection({
    create: costCentersApi.useCreate(),
    update: costCentersApi.useUpdate(),
    remove: costCentersApi.useDelete(),
    empty: EMPTY,
    toForm: (c: CostCenter) => ({
      name: c.name,
      code: c.code ?? "",
      is_active: c.is_active,
    }),
    toPayload: (f) => ({
      name: f.name,
      code: f.code || null,
      is_active: f.is_active,
    }),
    messages: {
      created: t("org.cost_center.created", "Cost center created"),
      updated: t("org.cost_center.updated", "Cost center updated"),
      deleteTitle: (c) =>
        t("org.cost_center.delete_confirm", 'Delete cost center ":name"?', {
          name: c.name,
        }),
    },
  });
  const { form, set } = crud;
  const items = list.data?.data ?? [];

  return (
    <CrudSection
      crud={crud}
      title={t("org.tab.cost_centers", "Cost Centers")}
      query={list}
      isEmpty={items.length === 0}
      emptyIcon={Wallet}
      emptyTitle={t("org.cost_center.empty_title", "No cost centers")}
      emptyDescription={t(
        "org.cost_center.empty_desc",
        "Cost centers help allocate payroll expenses across the organization",
      )}
      dialogTitle={{
        create: t("org.cost_center.new_title", "New Cost Center"),
        edit: t("org.cost_center.edit_title", "Edit Cost Center"),
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
          <CrudField label={t("org.field.code", "Code")}>
            <Input
              value={form.code}
              onChange={(e) => set("code", e.target.value)}
              placeholder="CC-001..."
            />
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
              t("org.field.code", "Code"),
              t("common.status", "Status"),
            ]}
            rows={items.map((c) => ({
              key: c.public_id,
              cells: [
                c.name,
                c.code ?? "—",
                <ActiveBadge key="s" active={c.is_active} />,
              ],
              onEdit: () => crud.openEdit(c),
              onDelete: canDelete ? () => crud.requestDelete(c) : undefined,
            }))}
          />
        </CardContent>
      </Card>
    </CrudSection>
  );
}
