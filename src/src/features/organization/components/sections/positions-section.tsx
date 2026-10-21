"use client";

import { Briefcase } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
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
import { ActiveBadge, useCanDeleteOrg } from "./shared";

const EMPTY = {
  title: "",
  title_am: "",
  code: "",
  description: "",
  is_active: true,
};

export function PositionsSection() {
  const { t } = useT();
  const canDelete = useCanDeleteOrg();
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
    }),
    toPayload: (f) => ({
      title: f.title,
      title_am: f.title_am || null,
      code: f.code || null,
      description: f.description || null,
      is_active: f.is_active,
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
