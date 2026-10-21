"use client";

import {
  useId,
  useState,
  type ComponentType,
  type FormEvent,
  type ReactNode,
} from "react";
import { Loader2, Plus } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { useConfirm } from "@/components/shared/use-confirm";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

/**
 * A list of records with an add and edit dialog and a confirmed delete: the
 * shape of every unit on the organization page, which carried six copies of
 * the same state, submit, delete and dialog code (FRONTEND_REDESIGN_PLAN.md,
 * Phase 3).
 *
 *   const crud = useCrudSection({ create, update, remove, empty, toForm, toPayload, messages });
 *   return (
 *     <CrudSection crud={crud} query={list} form={<Fields />} ...>
 *       {list of rows, each calling crud.openEdit(row) and crud.requestDelete(row)}
 *     </CrudSection>
 *   );
 *
 * The hook owns the dialog's state and the two requests; the page owns the
 * fields, the form shape and how rows are drawn.
 */

interface SaveMutation<V> {
  mutateAsync: (variables: V) => Promise<unknown>;
  isPending: boolean;
}

interface CrudMessages<T> {
  created: string;
  updated: string;
  /** The whole question, naming the record: "Delete branch "Bole"?" */
  deleteTitle: (item: T) => string;
}

export function useCrudSection<T extends { public_id: string }, F, P>({
  create,
  update,
  remove,
  empty,
  toForm,
  toPayload,
  messages,
}: {
  create: SaveMutation<P>;
  update: SaveMutation<{ publicId: string; payload: P }>;
  remove: { mutateAsync: (publicId: string) => Promise<unknown> };
  /** The form for a new record. */
  empty: F;
  /** The form for an existing record. */
  toForm: (item: T) => F;
  /** The request body; one for create and update alike. */
  toPayload: (form: F) => P;
  messages: CrudMessages<T>;
}) {
  const { t } = useT();
  const { requestConfirm, confirmDialog } = useConfirm();
  const [editing, setEditing] = useState<T | null>(null);
  const [isOpen, setIsOpen] = useState(false);
  const [form, setForm] = useState<F>(empty);

  function set<K extends keyof F>(field: K, value: F[K]) {
    setForm((current) => ({ ...current, [field]: value }));
  }

  function openCreate() {
    setEditing(null);
    setForm(empty);
    setIsOpen(true);
  }

  function openEdit(item: T) {
    setEditing(item);
    setForm(toForm(item));
    setIsOpen(true);
  }

  // A failed save said only "Save failed", so a duplicate code or a
  // validation message from the server was never shown.
  async function submit(e: FormEvent) {
    e.preventDefault();
    const payload = toPayload(form);
    try {
      if (editing) {
        await update.mutateAsync({ publicId: editing.public_id, payload });
      } else {
        await create.mutateAsync(payload);
      }
    } catch (err) {
      toast.error(apiErrorMessage(err, t("common.save_failed", "Save failed")));
      return;
    }
    toast.success(editing ? messages.updated : messages.created);
    setIsOpen(false);
  }

  async function requestDelete(item: T) {
    const confirmed = await requestConfirm({
      title: messages.deleteTitle(item),
      confirmLabel: t("common.delete", "Delete"),
      variant: "destructive",
    });
    if (!confirmed) return;
    try {
      await remove.mutateAsync(item.public_id);
    } catch (err) {
      toast.error(
        apiErrorMessage(err, t("common.delete_failed", "Delete failed")),
      );
      return;
    }
    toast.success(t("common.deleted", "Deleted"));
  }

  return {
    editing,
    isOpen,
    setIsOpen,
    form,
    set,
    openCreate,
    openEdit,
    submit,
    requestDelete,
    isSaving: create.isPending || update.isPending,
    confirmDialog,
  };
}

/** What `CrudSection` needs from `useCrudSection`, whatever the record type. */
export interface CrudSectionControl {
  editing: unknown;
  isOpen: boolean;
  setIsOpen: (open: boolean) => void;
  openCreate: () => void;
  submit: (e: FormEvent) => void;
  isSaving: boolean;
  confirmDialog: ReactNode;
}

export function CrudSection({
  crud,
  title,
  query,
  isEmpty,
  emptyIcon,
  emptyTitle,
  emptyDescription,
  dialogTitle,
  dialogClassName,
  form,
  children,
}: {
  crud: CrudSectionControl;
  title: string;
  /** The list query. A failed list must not read as "No branches yet". */
  query: { isLoading: boolean; isError: boolean; refetch: () => unknown };
  isEmpty: boolean;
  emptyIcon: ComponentType<{ className?: string }>;
  emptyTitle: string;
  emptyDescription: string;
  dialogTitle: { create: string; edit: string };
  dialogClassName?: string;
  /** The dialog's fields. Cancel and Save are drawn here. */
  form: ReactNode;
  children: ReactNode;
}) {
  const { t } = useT();
  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-3">
        <h2 className="text-lg font-semibold">{title}</h2>
        <Button size="sm" onClick={crud.openCreate}>
          <Plus className="mr-2 h-4 w-4" aria-hidden="true" />
          {t("common.add", "Add")}
        </Button>
      </div>

      {query.isLoading ? (
        <div className="space-y-2">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-16" />
          ))}
        </div>
      ) : query.isError ? (
        <ErrorState onRetry={() => query.refetch()} />
      ) : isEmpty ? (
        <EmptyState
          icon={emptyIcon}
          title={emptyTitle}
          description={emptyDescription}
          action={
            <Button onClick={crud.openCreate}>
              <Plus className="mr-2 h-4 w-4" aria-hidden="true" />
              {t("org.add_first", "Add first one")}
            </Button>
          }
        />
      ) : (
        children
      )}

      <Dialog open={crud.isOpen} onOpenChange={crud.setIsOpen}>
        <DialogContent className={dialogClassName}>
          <DialogHeader>
            <DialogTitle>
              {crud.editing ? dialogTitle.edit : dialogTitle.create}
            </DialogTitle>
          </DialogHeader>
          <form onSubmit={crud.submit} className="space-y-3">
            {form}
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => crud.setIsOpen(false)}
              >
                {t("common.cancel", "Cancel")}
              </Button>
              <Button type="submit" disabled={crud.isSaving}>
                {crud.isSaving && (
                  <Loader2
                    className="mr-2 h-4 w-4 animate-spin"
                    aria-hidden="true"
                  />
                )}
                {t("common.save", "Save")}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
      {crud.confirmDialog}
    </div>
  );
}

/**
 * A labelled field. The label wraps the control, so a click on it focuses
 * the input and a screen reader names the input by it; the organization
 * dialogs drew a `<Label>` beside each input with nothing tying the two.
 */
export function CrudField({
  label,
  required,
  className,
  children,
}: {
  label: string;
  required?: boolean;
  className?: string;
  children: ReactNode;
}) {
  return (
    <label className={cn("block", className)}>
      <span className="text-xs font-medium text-foreground">
        {label}
        {required && (
          <span aria-hidden="true" className="text-destructive">
            {" "}
            *
          </span>
        )}
      </span>
      <span className="mt-1 block">{children}</span>
    </label>
  );
}

/** Two fields side by side from the small breakpoint up, stacked on a phone. */
export function CrudFieldRow({
  columns = 2,
  children,
}: {
  columns?: 2 | 3;
  children: ReactNode;
}) {
  return (
    <div
      className={cn(
        "grid gap-3",
        columns === 3 ? "sm:grid-cols-3" : "sm:grid-cols-2",
      )}
    >
      {children}
    </div>
  );
}

export function ActiveToggle({
  checked,
  onChange,
}: {
  checked: boolean;
  onChange: (value: boolean) => void;
}) {
  const { t } = useT();
  const hintId = useId();
  return (
    <label className="flex cursor-pointer items-center gap-2 rounded-lg border p-3 hover:bg-muted/50">
      <input
        type="checkbox"
        checked={checked}
        onChange={(e) => onChange(e.target.checked)}
        aria-describedby={hintId}
        className="h-4 w-4 rounded"
      />
      <span className="text-sm font-medium">{t("org.active", "Active")}</span>
      <span id={hintId} className="text-xs text-muted-foreground">
        {t("org.active_desc", "Inactive items are hidden from selection menus")}
      </span>
    </label>
  );
}
