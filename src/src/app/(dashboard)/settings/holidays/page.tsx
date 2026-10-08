"use client";

import { useState } from "react";
import {
  CalendarDays,
  Plus,
  Pencil,
  Trash2,
  Wand2,
  Loader2,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { SimpleTable } from "@/components/shared/simple-table";
import { RoleGate } from "@/components/shared/role-gate";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { FormField } from "@/components/patterns/FormField";
import { FormErrorSummary } from "@/components/patterns/FormErrorSummary";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { Controller } from "react-hook-form";
import {
  useAutoDetectHolidays,
  useCreateHoliday,
  useDeleteHoliday,
  useHolidays,
  useUpdateHoliday,
  type Holiday,
} from "@/features/holidays/api";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { rules, fieldMessage } from "@/lib/forms/rules";
import { statusBadgeClass } from "@/lib/utils/status-colors";
import { toast } from "sonner";
import { z } from "zod";

const holidaySchema = z.object({
  name: rules.requiredText(255),
  date: rules.date(),
  recurring: z.boolean(),
});
type HolidayValues = z.infer<typeof holidaySchema>;

export default function HolidaysPage() {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  const { hasPermission } = usePermissions();
  // Deleting is tenant-admin only (`holiday.delete`) while adding is HR
  // (`holiday.create`). The page offered delete to everyone it admits, so an
  // HR admin's click always came back 403 as "delete failed".
  const canCreate = hasPermission("holiday.create");
  const canDelete = hasPermission("holiday.delete");
  // Editing (`holiday.update`, HR) had an API and no screen, so a wrong date
  // or name meant deleting the holiday — a tenant-admin action — and adding
  // it again (audit N99).
  const canUpdate = hasPermission("holiday.update");
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<Holiday | null>(null);
  const [deleting, setDeleting] = useState<Holiday | null>(null);

  const {
    register,
    control,
    submit,
    reset,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<HolidayValues>({
    schema: holidaySchema,
    defaultValues: { name: "", date: "", recurring: false },
  });

  const holidaysQuery = useHolidays();
  const createHoliday = useCreateHoliday();
  const updateHoliday = useUpdateHoliday();
  const deleteHoliday = useDeleteHoliday();
  const autoDetect = useAutoDetectHolidays();

  // No `onError` toast — `submit` shows a duplicate date or a rejected name on
  // the field inside the still-open dialog, where it can be corrected.
  async function saveHoliday(values: HolidayValues) {
    if (editing) {
      await updateHoliday.mutateAsync({
        publicId: editing.public_id,
        ...values,
      });
      toast.success(t("holidays_page.updated", "Holiday updated"));
    } else {
      await createHoliday.mutateAsync(values);
      toast.success(t("holidays_page.added"));
    }
    closeDialog();
  }

  function openAdd() {
    setEditing(null);
    reset({ name: "", date: "", recurring: false });
    setDialogOpen(true);
  }

  function openEdit(holiday: Holiday) {
    setEditing(holiday);
    reset({
      name: holiday.name,
      date: holiday.date.slice(0, 10),
      recurring: holiday.recurring,
    });
    setDialogOpen(true);
  }

  function closeDialog() {
    setDialogOpen(false);
    setEditing(null);
    reset({ name: "", date: "", recurring: false });
  }

  // Deleted on one click before; an accidental click removed a holiday from
  // every leave and attendance calculation (audit N99).
  function confirmDelete() {
    if (!deleting) return;
    deleteHoliday.mutate(deleting.public_id, {
      onSuccess: () => toast.success(t("holidays_page.deleted")),
      onError: () => toast.error(t("holidays_page.delete_failed")),
      onSettled: () => setDeleting(null),
    });
  }

  function runAutoDetect() {
    autoDetect.mutate(undefined, {
      onSuccess: () => toast.success(t("holidays_page.auto_detected")),
      onError: () => toast.error(t("holidays_page.auto_detect_failed")),
    });
  }

  return (
    <RoleGate anyPermission={["manageHolidays"]}>
      <div className="space-y-6">
        <PageHeader
          title={t("holidays_page.title")}
          description={t("holidays_page.description")}
          actions={
            canCreate && (
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  onClick={runAutoDetect}
                  disabled={autoDetect.isPending}
                >
                  {autoDetect.isPending ? (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  ) : (
                    <Wand2 className="mr-2 h-4 w-4" />
                  )}
                  {t("holidays_page.auto_detect")}
                </Button>
                <Button onClick={openAdd}>
                  <Plus className="mr-2 h-4 w-4" />
                  {t("holidays_page.add_holiday")}
                </Button>
              </div>
            )
          }
        />

        <QueryBoundary
          query={holidaysQuery}
          loading={
            <div className="space-y-3">
              {Array.from({ length: 4 }).map((_, i) => (
                <Skeleton key={i} className="h-12 w-full" />
              ))}
            </div>
          }
          empty={
            <EmptyState
              icon={CalendarDays}
              title={t("holidays_page.no_holidays")}
              description={t("holidays_page.no_holidays_desc")}
            />
          }
        >
          {(holidays) => (
            <Card>
              <CardHeader>
                <CardTitle className="text-base">
                  {t("holidays_page.title")}
                </CardTitle>
              </CardHeader>
              <CardContent className="p-0">
                <SimpleTable
                  caption={t("holidays_page.title")}
                  headers={[
                    t("common.name"),
                    t("common.date"),
                    t("holidays_page.recurring"),
                  ]}
                  rows={holidays.map((holiday) => ({
                    key: holiday.public_id,
                    cells: [
                      <span key="n" className="font-medium">
                        {holiday.name}
                      </span>,
                      <span key="d" className="text-muted-foreground">
                        {formatDate(holiday.date)}
                      </span>,
                      holiday.recurring ? (
                        <Badge
                          key="r"
                          variant="outline"
                          className={statusBadgeClass("active")}
                        >
                          {t("holidays_page.recurring")}
                        </Badge>
                      ) : (
                        <Badge
                          key="r"
                          variant="outline"
                          className={statusBadgeClass("offline")}
                        >
                          {t("holidays_page.one_time")}
                        </Badge>
                      ),
                    ],
                    actions:
                      canUpdate || canDelete ? (
                        <div className="flex justify-end gap-1">
                          {canUpdate && (
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => openEdit(holiday)}
                            >
                              <Pencil className="h-4 w-4" />
                              <span className="sr-only">
                                {t("common.edit", "Edit")} {holiday.name}
                              </span>
                            </Button>
                          )}
                          {canDelete && (
                            <Button
                              variant="ghost"
                              size="sm"
                              className="text-destructive-on-soft hover:bg-destructive-soft"
                              onClick={() => setDeleting(holiday)}
                              disabled={deleteHoliday.isPending}
                            >
                              <Trash2 className="h-4 w-4" />
                              <span className="sr-only">
                                {t("common.delete", "Delete")} {holiday.name}
                              </span>
                            </Button>
                          )}
                        </div>
                      ) : undefined,
                  }))}
                />
              </CardContent>
            </Card>
          )}
        </QueryBoundary>

        <Dialog
          open={dialogOpen}
          onOpenChange={(open) => (open ? setDialogOpen(true) : closeDialog())}
        >
          <DialogContent>
            <DialogHeader>
              <DialogTitle>
                {editing
                  ? t("holidays_page.edit_holiday", "Edit holiday")
                  : t("holidays_page.add_holiday")}
              </DialogTitle>
            </DialogHeader>
            <form
              onSubmit={submit(
                saveHoliday,
                editing
                  ? t(
                      "holidays_page.update_failed",
                      "Couldn't update the holiday",
                    )
                  : t("holidays_page.add_failed"),
              )}
              className="space-y-4"
              noValidate
            >
              <FormErrorSummary message={rootError} />

              <FormField
                id="holiday_name"
                label={t("common.name")}
                required
                error={fieldMessage(t, errors.name?.message)}
              >
                <Input
                  {...register("name")}
                  placeholder={t("holidays_page.name_placeholder")}
                  className="mt-1"
                />
              </FormField>

              <FormField
                id="holiday_date"
                label={t("common.date")}
                required
                error={fieldMessage(t, errors.date?.message)}
              >
                {(control_) => (
                  // `Controller`, not `register`: DualCalendarDateInput is a
                  // controlled component with an ISO-string contract, and in
                  // Ethiopian entry mode it is three Selects rather than an
                  // input RHF could register directly.
                  <Controller
                    name="date"
                    control={control}
                    render={({ field }) => (
                      <DualCalendarDateInput
                        {...control_}
                        value={field.value}
                        onChange={field.onChange}
                        className="mt-1"
                      />
                    )}
                  />
                )}
              </FormField>

              <div className="flex items-center gap-2">
                <input
                  {...register("recurring")}
                  id="holiday_recurring"
                  type="checkbox"
                  className="h-4 w-4 rounded border-input"
                />
                <Label htmlFor="holiday_recurring" className="cursor-pointer">
                  {t("holidays_page.recurring_every_year")}
                </Label>
              </div>

              <DialogFooter>
                <Button type="button" variant="outline" onClick={closeDialog}>
                  {t("common.cancel")}
                </Button>
                <Button type="submit" disabled={isSubmitting}>
                  {isSubmitting && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  {editing
                    ? t("common.save", "Save")
                    : t("holidays_page.add_holiday")}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>

        <ConfirmDialog
          open={deleting !== null}
          onOpenChange={(open) => !open && setDeleting(null)}
          title={t("holidays_page.delete_title", "Delete this holiday?")}
          description={t(
            "holidays_page.delete_desc",
            "Leave and attendance stop treating this day as a holiday.",
          )}
          confirmLabel={t("common.delete", "Delete")}
          variant="destructive"
          loading={deleteHoliday.isPending}
          onConfirm={confirmDelete}
        />
      </div>
    </RoleGate>
  );
}
