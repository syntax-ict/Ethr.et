"use client";

import { useId, useState } from "react";
import { ArrowDown, ArrowUp, Save, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import {
  useDeleteMarketingItem,
  useUpdateMarketingItem,
  type AdminMarketingItem,
  type AdminMarketingSpec,
} from "@/features/admin/api";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";
import { fieldErrors } from "./field-errors";
import { IconPicker } from "./icon-picker";
import { MarketingImageUpload } from "./marketing-image-upload";

/**
 * One row of a section, with exactly the fields its kind uses. The server
 * refuses a field the kind does not use, so the form never offers one; a
 * refusal that still arrives (a forbidden claim, a bad link) is shown
 * beside its field.
 */
export function ItemEditor({
  item,
  spec,
  icons,
  position,
  count,
  onMove,
}: {
  item: AdminMarketingItem;
  spec: AdminMarketingSpec;
  icons: string[];
  position: number;
  count: number;
  onMove: (direction: -1 | 1) => void;
}) {
  const { t } = useT();
  const update = useUpdateMarketingItem();
  const remove = useDeleteMarketingItem();
  const [confirming, setConfirming] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const ids = {
    title: useId(),
    titleAm: useId(),
    body: useId(),
    bodyAm: useId(),
    detail: useId(),
    detailAm: useId(),
    icon: useId(),
    link: useId(),
    alt: useId(),
    altAm: useId(),
    published: useId(),
  };

  const [form, setForm] = useState({
    title: item.title,
    title_am: item.title_am ?? "",
    body: item.body ?? "",
    body_am: item.body_am ?? "",
    detail: item.detail ?? "",
    detail_am: item.detail_am ?? "",
    icon: item.icon,
    link_path: item.link_path ?? "",
    image_alt: item.image?.alt ?? "",
    image_alt_am: item.image?.alt_am ?? "",
    is_published: item.is_published,
  });
  const set = <K extends keyof typeof form>(
    key: K,
    value: (typeof form)[K],
  ): void => setForm((previous) => ({ ...previous, [key]: value }));

  const uses = (field: string) => spec.fields.includes(field);

  function save() {
    setErrors({});
    update.mutate(
      {
        publicId: item.public_id,
        title: form.title,
        title_am: form.title_am || null,
        ...(uses("body")
          ? { body: form.body || null, body_am: form.body_am || null }
          : {}),
        ...(uses("detail")
          ? { detail: form.detail || null, detail_am: form.detail_am || null }
          : {}),
        ...(uses("icon") ? { icon: form.icon } : {}),
        ...(uses("link_path") ? { link_path: form.link_path || null } : {}),
        ...(spec.images
          ? {
              image_alt: form.image_alt || null,
              image_alt_am: form.image_alt_am || null,
            }
          : {}),
        is_published: form.is_published,
      },
      {
        onSuccess: () =>
          toast.success(t("admin_marketing.item_saved", "Row saved")),
        onError: (err) => {
          setErrors(fieldErrors(err));
          toast.error(
            apiErrorMessage(
              err,
              t("admin_marketing.item_save_failed", "Could not save the row"),
            ),
          );
        },
      },
    );
  }

  function destroy() {
    remove.mutate(item.public_id, {
      onSuccess: () =>
        toast.success(t("admin_marketing.item_deleted", "Row deleted")),
      onError: (err) =>
        toast.error(
          apiErrorMessage(
            err,
            t("admin_marketing.item_delete_failed", "Could not delete the row"),
          ),
        ),
    });
  }

  return (
    <li className="rounded-lg border border-border/70 bg-background p-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm font-medium text-foreground">
          <span className="text-muted-foreground">{position}.</span>{" "}
          {item.title}{" "}
          <span className="font-mono text-xs text-muted-foreground">
            {item.slug}
          </span>
        </p>
        <div className="flex items-center gap-1">
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className="h-8 w-8"
            disabled={position === 1}
            onClick={() => onMove(-1)}
            aria-label={t("admin_marketing.move_up", "Move up")}
          >
            <ArrowUp className="h-4 w-4" aria-hidden="true" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className="h-8 w-8"
            disabled={position === count}
            onClick={() => onMove(1)}
            aria-label={t("admin_marketing.move_down", "Move down")}
          >
            <ArrowDown className="h-4 w-4" aria-hidden="true" />
          </Button>
        </div>
      </div>

      <div className="mt-4 grid gap-4 sm:grid-cols-2">
        <Field
          id={ids.title}
          label={t("admin_marketing.field.title", "Title")}
          error={errors.title}
        >
          <Input
            id={ids.title}
            value={form.title}
            onChange={(e) => set("title", e.target.value)}
          />
        </Field>
        <Field
          id={ids.titleAm}
          label={t("admin_marketing.field.title_am", "Title (Amharic)")}
          error={errors.title_am}
        >
          <Input
            id={ids.titleAm}
            value={form.title_am}
            onChange={(e) => set("title_am", e.target.value)}
          />
        </Field>

        {uses("body") && (
          <>
            <Field
              id={ids.body}
              label={t("admin_marketing.field.body", "Text")}
              error={errors.body}
            >
              <Textarea
                id={ids.body}
                rows={3}
                value={form.body}
                onChange={(e) => set("body", e.target.value)}
              />
            </Field>
            <Field
              id={ids.bodyAm}
              label={t("admin_marketing.field.body_am", "Text (Amharic)")}
              error={errors.body_am}
            >
              <Textarea
                id={ids.bodyAm}
                rows={3}
                value={form.body_am}
                onChange={(e) => set("body_am", e.target.value)}
              />
            </Field>
          </>
        )}

        {uses("detail") && (
          <>
            <Field
              id={ids.detail}
              label={t("admin_marketing.field.detail", "Detail")}
              hint={t(
                "admin_marketing.field.detail_hint",
                "For a problem, what ETHR does with it. For a capability, its business value.",
              )}
              error={errors.detail}
            >
              <Textarea
                id={ids.detail}
                rows={3}
                value={form.detail}
                onChange={(e) => set("detail", e.target.value)}
              />
            </Field>
            <Field
              id={ids.detailAm}
              label={t("admin_marketing.field.detail_am", "Detail (Amharic)")}
              error={errors.detail_am}
            >
              <Textarea
                id={ids.detailAm}
                rows={3}
                value={form.detail_am}
                onChange={(e) => set("detail_am", e.target.value)}
              />
            </Field>
          </>
        )}

        {uses("icon") && (
          <div>
            <IconPicker
              id={ids.icon}
              value={form.icon}
              icons={icons}
              onChange={(icon) => set("icon", icon)}
            />
            {errors.icon && (
              <p className="mt-1 text-xs text-destructive">{errors.icon}</p>
            )}
          </div>
        )}

        {uses("link_path") && (
          <Field
            id={ids.link}
            label={t("admin_marketing.field.link", "Link")}
            hint={t(
              "admin_marketing.field.link_hint",
              "A page on this site, like /payroll-software. Leave empty for none.",
            )}
            error={errors.link_path}
          >
            <Input
              id={ids.link}
              value={form.link_path}
              onChange={(e) => set("link_path", e.target.value)}
            />
          </Field>
        )}

        {spec.images && (
          <>
            <Field
              id={ids.alt}
              label={t("admin_marketing.field.alt", "Picture description")}
              error={errors.image_alt}
            >
              <Input
                id={ids.alt}
                value={form.image_alt}
                onChange={(e) => set("image_alt", e.target.value)}
              />
            </Field>
            <Field
              id={ids.altAm}
              label={t(
                "admin_marketing.field.alt_am",
                "Picture description (Amharic)",
              )}
              error={errors.image_alt_am}
            >
              <Input
                id={ids.altAm}
                value={form.image_alt_am}
                onChange={(e) => set("image_alt_am", e.target.value)}
              />
            </Field>
          </>
        )}
      </div>

      {spec.images && (
        <div className="mt-4">
          <MarketingImageUpload
            owner={{ type: "item", publicId: item.public_id }}
            url={item.image?.url}
            title={t("admin_marketing.row_picture", "Picture for this row")}
          />
        </div>
      )}

      <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <Switch
            id={ids.published}
            checked={form.is_published}
            onCheckedChange={(checked) => set("is_published", checked)}
          />
          <Label htmlFor={ids.published}>
            {t("admin_marketing.published", "Shown on the page")}
          </Label>
        </div>
        <div className="flex items-center gap-2">
          {confirming ? (
            <>
              <Button
                type="button"
                variant="destructive"
                size="sm"
                disabled={remove.isPending}
                onClick={destroy}
              >
                {t("admin_marketing.confirm_delete", "Yes, delete this row")}
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => setConfirming(false)}
              >
                {t("common.cancel", "Cancel")}
              </Button>
            </>
          ) : (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={() => setConfirming(true)}
            >
              <Trash2 className="mr-1.5 h-3.5 w-3.5" aria-hidden="true" />
              {t("admin_marketing.delete", "Delete")}
            </Button>
          )}
          <Button
            type="button"
            size="sm"
            disabled={update.isPending}
            onClick={save}
          >
            <Save className="mr-1.5 h-3.5 w-3.5" aria-hidden="true" />
            {t("admin_marketing.save", "Save")}
          </Button>
        </div>
      </div>
    </li>
  );
}

export function Field({
  id,
  label,
  hint,
  error,
  children,
}: {
  id: string;
  label: string;
  hint?: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-1.5">
      <Label htmlFor={id}>{label}</Label>
      {children}
      {error ? (
        <p className="text-xs text-destructive" role="alert">
          {error}
        </p>
      ) : (
        hint && <p className="text-xs text-muted-foreground">{hint}</p>
      )}
    </div>
  );
}
