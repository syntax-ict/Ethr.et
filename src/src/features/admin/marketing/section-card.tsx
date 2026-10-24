"use client";

import { useId, useState } from "react";
import { Plus, Save } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import {
  useCreateMarketingItem,
  useReorderMarketingItems,
  useUpdateMarketingSection,
  type AdminMarketingSection,
} from "@/features/admin/api";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";
import { fieldErrors } from "./field-errors";
import { Field, ItemEditor } from "./item-editor";
import { MarketingImageUpload } from "./marketing-image-upload";

/** What each kind is called on the screen. */
export function kindLabel(
  kind: string,
  t: (key: string, fallback: string) => string,
): string {
  switch (kind) {
    case "hero":
      return t("admin_marketing.kind.hero", "Opening");
    case "problems":
      return t("admin_marketing.kind.problems", "Problems and what ETHR does");
    case "capabilities":
      return t("admin_marketing.kind.capabilities", "Capabilities");
    case "how_it_works":
      return t("admin_marketing.kind.how_it_works", "Getting started");
    case "industries":
      return t("admin_marketing.kind.industries", "Industries");
    case "trust":
      return t("admin_marketing.kind.trust", "Data and safeguards");
    case "cta":
      return t("admin_marketing.kind.cta", "Call to action");
    default:
      return kind;
  }
}

/**
 * One section of the landing page: its heading and sentence in both
 * languages, whether it is shown, the hero's picture, and its rows in order.
 */
export function SectionCard({
  section,
  icons,
}: {
  section: AdminMarketingSection;
  icons: string[];
}) {
  const { t } = useT();
  const update = useUpdateMarketingSection();
  const reorder = useReorderMarketingItems();
  const create = useCreateMarketingItem();
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [adding, setAdding] = useState(false);
  const [draft, setDraft] = useState({ slug: "", title: "", title_am: "" });

  const ids = {
    heading: useId(),
    headingAm: useId(),
    subheading: useId(),
    subheadingAm: useId(),
    published: useId(),
    alt: useId(),
    altAm: useId(),
    slug: useId(),
    title: useId(),
    titleAm: useId(),
  };

  const [form, setForm] = useState({
    heading: section.heading,
    heading_am: section.heading_am ?? "",
    subheading: section.subheading ?? "",
    subheading_am: section.subheading_am ?? "",
    is_published: section.is_published,
    image_alt: section.image?.alt ?? "",
    image_alt_am: section.image?.alt_am ?? "",
  });
  const set = <K extends keyof typeof form>(
    key: K,
    value: (typeof form)[K],
  ): void => setForm((previous) => ({ ...previous, [key]: value }));

  const canAdd =
    section.spec.max_items > 0 && section.items.length < section.spec.max_items;

  function save() {
    setErrors({});
    update.mutate(
      {
        kind: section.kind,
        heading: form.heading,
        heading_am: form.heading_am || null,
        subheading: form.subheading || null,
        subheading_am: form.subheading_am || null,
        is_published: form.is_published,
        ...(section.spec.section_image
          ? {
              image_alt: form.image_alt || null,
              image_alt_am: form.image_alt_am || null,
            }
          : {}),
      },
      {
        onSuccess: () =>
          toast.success(t("admin_marketing.section_saved", "Section saved")),
        onError: (err) => {
          setErrors(fieldErrors(err));
          toast.error(
            apiErrorMessage(
              err,
              t(
                "admin_marketing.section_save_failed",
                "Could not save the section",
              ),
            ),
          );
        },
      },
    );
  }

  function move(index: number, direction: -1 | 1) {
    const order = section.items.map((item) => item.public_id);
    const target = index + direction;
    if (target < 0 || target >= order.length) return;
    [order[index], order[target]] = [order[target], order[index]];
    reorder.mutate(
      { kind: section.kind, order },
      {
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t("admin_marketing.reorder_failed", "Could not reorder the rows"),
            ),
          ),
      },
    );
  }

  function add() {
    setErrors({});
    create.mutate(
      {
        kind: section.kind,
        slug: draft.slug,
        title: draft.title,
        title_am: draft.title_am || null,
      },
      {
        onSuccess: () => {
          toast.success(t("admin_marketing.item_added", "Row added"));
          setDraft({ slug: "", title: "", title_am: "" });
          setAdding(false);
        },
        onError: (err) => {
          setErrors(fieldErrors(err));
          toast.error(
            apiErrorMessage(
              err,
              t("admin_marketing.item_add_failed", "Could not add the row"),
            ),
          );
        },
      },
    );
  }

  return (
    <Card data-testid={`marketing-section-${section.kind}`}>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle className="text-base">
            {kindLabel(section.kind, t)}
          </CardTitle>
          <p className="mt-1 text-sm text-muted-foreground">
            {section.spec.max_items > 0
              ? t("admin_marketing.rows_of_max", ":count of :max rows", {
                  count: String(section.items.length),
                  max: String(section.spec.max_items),
                })
              : t("admin_marketing.no_rows", "Heading and sentence only")}
          </p>
        </div>
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
      </CardHeader>

      <CardContent className="space-y-5">
        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            id={ids.heading}
            label={t("admin_marketing.field.heading", "Heading")}
            error={errors.heading}
          >
            <Input
              id={ids.heading}
              value={form.heading}
              onChange={(e) => set("heading", e.target.value)}
            />
          </Field>
          <Field
            id={ids.headingAm}
            label={t("admin_marketing.field.heading_am", "Heading (Amharic)")}
            error={errors.heading_am}
          >
            <Input
              id={ids.headingAm}
              value={form.heading_am}
              onChange={(e) => set("heading_am", e.target.value)}
            />
          </Field>
          <Field
            id={ids.subheading}
            label={t("admin_marketing.field.subheading", "Sentence under it")}
            error={errors.subheading}
          >
            <Textarea
              id={ids.subheading}
              rows={3}
              value={form.subheading}
              onChange={(e) => set("subheading", e.target.value)}
            />
          </Field>
          <Field
            id={ids.subheadingAm}
            label={t(
              "admin_marketing.field.subheading_am",
              "Sentence under it (Amharic)",
            )}
            error={errors.subheading_am}
          >
            <Textarea
              id={ids.subheadingAm}
              rows={3}
              value={form.subheading_am}
              onChange={(e) => set("subheading_am", e.target.value)}
            />
          </Field>
          {section.spec.section_image && (
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

        {section.spec.section_image && (
          <MarketingImageUpload
            owner={{ type: "section", kind: section.kind }}
            url={section.image?.url}
            title={t("admin_marketing.hero_picture", "Opening picture")}
          />
        )}

        <div className="flex justify-end">
          <Button
            type="button"
            size="sm"
            disabled={update.isPending}
            onClick={save}
          >
            <Save className="mr-1.5 h-3.5 w-3.5" aria-hidden="true" />
            {t("admin_marketing.save_section", "Save section")}
          </Button>
        </div>

        {section.spec.max_items > 0 && (
          <div className="space-y-3 border-t pt-5">
            <div className="flex items-center justify-between">
              <h3 className="text-sm font-semibold text-foreground">
                {t("admin_marketing.rows", "Rows")}
              </h3>
              {canAdd && !adding && (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => setAdding(true)}
                >
                  <Plus className="mr-1.5 h-3.5 w-3.5" aria-hidden="true" />
                  {t("admin_marketing.add_row", "Add row")}
                </Button>
              )}
            </div>

            {adding && (
              <div className="rounded-lg border border-dashed p-4">
                <div className="grid gap-4 sm:grid-cols-3">
                  <Field
                    id={ids.slug}
                    label={t("admin_marketing.field.slug", "Key")}
                    hint={t(
                      "admin_marketing.field.slug_hint",
                      "Letters, digits and underscores. Cannot be changed later.",
                    )}
                    error={errors.slug}
                  >
                    <Input
                      id={ids.slug}
                      value={draft.slug}
                      onChange={(e) =>
                        setDraft({ ...draft, slug: e.target.value })
                      }
                    />
                  </Field>
                  <Field
                    id={ids.title}
                    label={t("admin_marketing.field.title", "Title")}
                    error={errors.title}
                  >
                    <Input
                      id={ids.title}
                      value={draft.title}
                      onChange={(e) =>
                        setDraft({ ...draft, title: e.target.value })
                      }
                    />
                  </Field>
                  <Field
                    id={ids.titleAm}
                    label={t(
                      "admin_marketing.field.title_am",
                      "Title (Amharic)",
                    )}
                    error={errors.title_am}
                  >
                    <Input
                      id={ids.titleAm}
                      value={draft.title_am}
                      onChange={(e) =>
                        setDraft({ ...draft, title_am: e.target.value })
                      }
                    />
                  </Field>
                </div>
                <div className="mt-3 flex justify-end gap-2">
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => setAdding(false)}
                  >
                    {t("common.cancel", "Cancel")}
                  </Button>
                  <Button
                    type="button"
                    size="sm"
                    disabled={create.isPending || !draft.slug || !draft.title}
                    onClick={add}
                  >
                    {t("admin_marketing.add_row_confirm", "Add")}
                  </Button>
                </div>
              </div>
            )}

            <ol className="space-y-3">
              {section.items.map((item, index) => (
                <ItemEditor
                  key={item.public_id}
                  item={item}
                  spec={section.spec}
                  icons={icons}
                  position={index + 1}
                  count={section.items.length}
                  onMove={(direction) => move(index, direction)}
                />
              ))}
            </ol>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
