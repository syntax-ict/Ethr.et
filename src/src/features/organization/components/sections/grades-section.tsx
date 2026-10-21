"use client";

import { useState } from "react";
import { Award } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { SimpleTable, TableRowActions } from "@/components/shared/simple-table";
import { CurrencyDisplay } from "@/components/shared/currency-display";
import {
  CrudField,
  CrudFieldRow,
  CrudSection,
  useCrudSection,
} from "@/components/shared/crud-section";
import { gradesApi, type Grade } from "@/features/organization/api";
import { GradeSalaryStepsDialog } from "@/features/organization/components/grade-salary-steps-dialog";
import { useT } from "@/lib/i18n/useT";
import { useCanDeleteOrg } from "./shared";

const EMPTY = { name: "", min_salary: "", max_salary: "", sort_order: "0" };

export function GradesSection() {
  const { t } = useT();
  const canDelete = useCanDeleteOrg();
  const list = gradesApi.useList();
  const [stepsFor, setStepsFor] = useState<Grade | null>(null);
  const crud = useCrudSection({
    create: gradesApi.useCreate(),
    update: gradesApi.useUpdate(),
    remove: gradesApi.useDelete(),
    empty: EMPTY,
    toForm: (g: Grade) => ({
      name: g.name,
      min_salary: (g.min_salary_cents / 100).toString(),
      max_salary: (g.max_salary_cents / 100).toString(),
      sort_order: (g.sort_order ?? 0).toString(),
    }),
    // Birr typed, cents stored (convention 3).
    toPayload: (f) => ({
      name: f.name,
      min_salary_cents: Math.round(parseFloat(f.min_salary) * 100),
      max_salary_cents: Math.round(parseFloat(f.max_salary) * 100),
      sort_order: parseInt(f.sort_order || "0"),
    }),
    messages: {
      created: t("org.grade.created", "Grade created"),
      updated: t("org.grade.updated", "Grade updated"),
      deleteTitle: (g) =>
        t("org.grade.delete_confirm", 'Delete grade ":name"?', {
          name: g.name,
        }),
    },
  });
  const { form, set } = crud;
  const items = (list.data?.data ?? [])
    .slice()
    .sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0));

  return (
    <CrudSection
      crud={crud}
      title={t("org.tab.grades", "Grades")}
      query={list}
      isEmpty={items.length === 0}
      emptyIcon={Award}
      emptyTitle={t("org.grade.empty_title", "No grades")}
      emptyDescription={t(
        "org.grade.empty_desc",
        "Define salary grade bands for your organization",
      )}
      dialogTitle={{
        create: t("org.grade.new_title", "New Grade"),
        edit: t("org.grade.edit_title", "Edit Grade"),
      }}
      form={
        <>
          <CrudField label={t("org.field.grade_name", "Grade Name")} required>
            <Input
              value={form.name}
              onChange={(e) => set("name", e.target.value)}
              required
              placeholder={t(
                "org.field.grade_name_placeholder",
                "G1, Manager I, Level 5...",
              )}
            />
          </CrudField>
          <CrudFieldRow>
            <CrudField
              label={t("org.field.min_salary", "Min Salary (ETB)")}
              required
            >
              <Input
                type="number"
                step="0.01"
                value={form.min_salary}
                onChange={(e) => set("min_salary", e.target.value)}
                required
              />
            </CrudField>
            <CrudField
              label={t("org.field.max_salary", "Max Salary (ETB)")}
              required
            >
              <Input
                type="number"
                step="0.01"
                value={form.max_salary}
                onChange={(e) => set("max_salary", e.target.value)}
                required
              />
            </CrudField>
          </CrudFieldRow>
          <CrudField label={t("org.field.sort_order", "Sort Order")}>
            <Input
              type="number"
              value={form.sort_order}
              onChange={(e) => set("sort_order", e.target.value)}
            />
          </CrudField>
        </>
      }
    >
      <Card>
        <CardContent className="p-0">
          <SimpleTable
            mobileCards
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
              actions: (
                <div className="flex items-center justify-end gap-1">
                  <Button
                    size="sm"
                    variant="ghost"
                    className="h-7 px-2 text-xs"
                    onClick={() => setStepsFor(g)}
                  >
                    {t("org.grade.step.manage", "Steps")}
                  </Button>
                  <TableRowActions
                    onEdit={() => crud.openEdit(g)}
                    onDelete={
                      canDelete ? () => crud.requestDelete(g) : undefined
                    }
                  />
                </div>
              ),
            }))}
          />
        </CardContent>
      </Card>

      {stepsFor && (
        <GradeSalaryStepsDialog
          grade={stepsFor}
          open={stepsFor !== null}
          onOpenChange={(v) => !v && setStepsFor(null)}
        />
      )}
    </CrudSection>
  );
}
