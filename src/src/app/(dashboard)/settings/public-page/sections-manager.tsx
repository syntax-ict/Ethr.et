"use client";

import { useState } from "react";
import {
  ChevronDown,
  ChevronUp,
  Eye,
  EyeOff,
  Loader2,
  Plus,
  Trash2,
} from "lucide-react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import { apiClient } from "@/api/client";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { FormField } from "@/components/patterns/FormField";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";

/**
 * The section builder.
 *
 * Reordering is up/down buttons rather than drag-and-drop, and that is a
 * decision rather than a shortcut. A drag surface needs a parallel keyboard
 * path built and maintained beside it; two buttons are operable by keyboard,
 * by switch control and by screen reader on the day they ship, and they work
 * on a phone without a long-press gesture nobody discovers.
 *
 * Alternative text is required beside every image upload, because an image
 * with none is a WCAG 1.1.1 failure on a page whose entire purpose is to be
 * read by the public.
 */

interface SectionItem {
  id: string;
  position: number;
  title: string | null;
  title_am: string | null;
  body: string | null;
  body_am: string | null;
  has_image: boolean;
  image_url: string | null;
  image_alt: string | null;
  image_alt_am: string | null;
  icon: string | null;
  link_url: string | null;
  link_label: string | null;
  link_label_am: string | null;
  meta: Record<string, string>;
}

interface Section {
  id: string;
  kind: string;
  position: number;
  is_visible: boolean;
  heading: string | null;
  heading_am: string | null;
  intro: string | null;
  intro_am: string | null;
  layout: string | null;
  has_items: boolean;
  allows_images: boolean;
  reads_profile: boolean;
  items: SectionItem[];
}

const SECTION_KINDS = [
  "hero",
  "about",
  "services",
  "stats",
  "notices",
  "news",
  "leadership",
  "gallery",
  "faq",
  "hours",
  "contact",
  "cta",
] as const;

type Translate = (
  key: string,
  fallback?: string,
  replacements?: Record<string, string>,
) => string;

/**
 * The display name for a section kind.
 *
 * A literal switch, for the same reason the preset labels are: the i18n gate
 * only sees string-literal keys, so an interpolated one would never be checked
 * for Amharic coverage.
 */
function kindLabel(t: Translate, kind: string): string {
  switch (kind) {
    case "hero":
      return t("settings.public_page.kind_hero", "Welcome");
    case "about":
      return t("settings.public_page.kind_about", "About us");
    case "services":
      return t("settings.public_page.kind_services", "Services");
    case "stats":
      return t("settings.public_page.kind_stats", "At a glance");
    case "notices":
      return t("settings.public_page.kind_notices", "Public notices");
    case "news":
      return t("settings.public_page.kind_news", "News and updates");
    case "leadership":
      return t("settings.public_page.kind_leadership", "Leadership");
    case "gallery":
      return t("settings.public_page.kind_gallery", "Gallery");
    case "faq":
      return t("settings.public_page.kind_faq", "Questions");
    case "hours":
      return t("settings.public_page.kind_hours", "Opening hours");
    case "contact":
      return t("settings.public_page.kind_contact", "Contact");
    default:
      return t("settings.public_page.kind_cta", "Call to action");
  }
}

export function SectionsManager({ t }: { t: Translate }) {
  const query = useQuery({
    queryKey: ["settings", "public-page", "sections"],
    queryFn: async () => {
      const { data } = await apiClient.get<{ sections: Section[] }>(
        "/settings/public-page/sections",
      );
      return data.sections;
    },
  });

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          {t("settings.public_page.sections", "Page sections")}
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        <QueryBoundary query={query}>
          {(sections) => <SectionList sections={sections} t={t} />}
        </QueryBoundary>
      </CardContent>
    </Card>
  );
}

function SectionList({ sections, t }: { sections: Section[]; t: Translate }) {
  const queryClient = useQueryClient();
  const [open, setOpen] = useState<string | null>(null);

  const invalidate = () =>
    queryClient.invalidateQueries({
      queryKey: ["settings", "public-page", "sections"],
    });

  const addSection = useMutation({
    mutationFn: async (kind: string) => {
      await apiClient.post("/settings/public-page/sections", { kind });
    },
    onSuccess: invalidate,
    onError: (error: unknown) => {
      // The API refuses a duplicate of a section that may appear only once,
      // and refuses anything past the cap. Both are worth showing verbatim:
      // they name a limit rather than describing a failure.
      const message =
        (
          error as {
            response?: { data?: { errors?: Record<string, string[]> } };
          }
        ).response?.data?.errors?.kind?.[0] ??
        t(
          "settings.public_page.section_add_failed",
          "Could not add that section.",
        );
      toast.error(message);
    },
  });

  const removeSection = useMutation({
    mutationFn: async (id: string) => {
      await apiClient.delete(`/settings/public-page/sections/${id}`);
    },
    onSuccess: invalidate,
  });

  const toggleVisible = useMutation({
    mutationFn: async ({ id, visible }: { id: string; visible: boolean }) => {
      await apiClient.put(`/settings/public-page/sections/${id}`, {
        is_visible: visible,
      });
    },
    onSuccess: invalidate,
  });

  const reorder = useMutation({
    mutationFn: async (order: string[]) => {
      await apiClient.put("/settings/public-page/sections/order", { order });
    },
    onSuccess: invalidate,
  });

  /** Move one section by one place, and send the whole resulting order. */
  const move = (index: number, delta: number) => {
    const next = [...sections];
    const target = index + delta;

    if (target < 0 || target >= next.length) return;

    [next[index], next[target]] = [next[target], next[index]];
    reorder.mutate(next.map((s) => s.id));
  };

  const used = new Set(sections.map((s) => s.kind));

  return (
    <div className="space-y-3">
      {sections.length === 0 && (
        <p className="text-sm text-muted-foreground">
          {t(
            "settings.public_page.sections_empty",
            "Choose a layout above and the sections that suit it will be added for you.",
          )}
        </p>
      )}

      <ul className="space-y-2">
        {sections.map((section, index) => (
          <li key={section.id} className="rounded-md border border-border">
            <div className="flex items-center gap-2 p-3">
              <div className="flex flex-col">
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="h-6 w-6"
                  disabled={index === 0 || reorder.isPending}
                  aria-label={t("settings.public_page.move_up", "Move up")}
                  onClick={() => move(index, -1)}
                >
                  <ChevronUp className="h-4 w-4" />
                </Button>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="h-6 w-6"
                  disabled={index === sections.length - 1 || reorder.isPending}
                  aria-label={t("settings.public_page.move_down", "Move down")}
                  onClick={() => move(index, 1)}
                >
                  <ChevronDown className="h-4 w-4" />
                </Button>
              </div>

              <button
                type="button"
                className="flex-1 text-start text-sm font-medium"
                aria-expanded={open === section.id}
                onClick={() => setOpen(open === section.id ? null : section.id)}
              >
                {kindLabel(t, section.kind)}
                {!section.is_visible && (
                  <span className="ms-2 text-xs font-normal text-muted-foreground">
                    {t("settings.public_page.section_hidden", "hidden")}
                  </span>
                )}
              </button>

              <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label={
                  section.is_visible
                    ? t("settings.public_page.hide_section", "Hide section")
                    : t("settings.public_page.show_section", "Show section")
                }
                onClick={() =>
                  toggleVisible.mutate({
                    id: section.id,
                    visible: !section.is_visible,
                  })
                }
              >
                {section.is_visible ? (
                  <Eye className="h-4 w-4" />
                ) : (
                  <EyeOff className="h-4 w-4" />
                )}
              </Button>

              <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label={t(
                  "settings.public_page.remove_section",
                  "Remove section",
                )}
                onClick={() => removeSection.mutate(section.id)}
              >
                <Trash2 className="h-4 w-4" />
              </Button>
            </div>

            {open === section.id && (
              <SectionEditor section={section} t={t} onSaved={invalidate} />
            )}
          </li>
        ))}
      </ul>

      <div className="flex flex-wrap gap-2 pt-2">
        {SECTION_KINDS.filter((kind) => !used.has(kind)).map((kind) => (
          <Button
            key={kind}
            type="button"
            variant="outline"
            size="sm"
            disabled={addSection.isPending}
            onClick={() => addSection.mutate(kind)}
          >
            <Plus className="mr-1 h-3 w-3" />
            {kindLabel(t, kind)}
          </Button>
        ))}
      </div>
    </div>
  );
}

function SectionEditor({
  section,
  t,
  onSaved,
}: {
  section: Section;
  t: Translate;
  onSaved: () => void;
}) {
  const [heading, setHeading] = useState(section.heading ?? "");
  const [headingAm, setHeadingAm] = useState(section.heading_am ?? "");
  const [intro, setIntro] = useState(section.intro ?? "");

  const save = useMutation({
    mutationFn: async () => {
      await apiClient.put(`/settings/public-page/sections/${section.id}`, {
        heading,
        heading_am: headingAm,
        intro,
      });
    },
    onSuccess: () => {
      toast.success(t("settings.public_page.section_saved", "Section saved"));
      onSaved();
    },
  });

  return (
    <div className="space-y-4 border-t border-border p-3">
      {section.reads_profile && (
        <p className="text-xs text-muted-foreground">
          {t(
            "settings.public_page.section_reads_profile",
            "This section uses the details you entered above, so there is nothing separate to write here.",
          )}
        </p>
      )}

      <FormField
        id={`section-${section.id}-heading`}
        label={t("settings.public_page.section_heading", "Heading")}
      >
        <Input
          id={`section-${section.id}-heading`}
          value={heading}
          maxLength={160}
          onChange={(e) => setHeading(e.target.value)}
        />
      </FormField>

      <FormField
        id={`section-${section.id}-heading-am`}
        label={t(
          "settings.public_page.section_heading_am",
          "Heading (Amharic)",
        )}
        hint={t(
          "settings.public_page.section_heading_am_hint",
          "Optional. Visitors reading in Amharic see the English heading if this is empty.",
        )}
      >
        <Input
          id={`section-${section.id}-heading-am`}
          value={headingAm}
          maxLength={160}
          onChange={(e) => setHeadingAm(e.target.value)}
        />
      </FormField>

      <FormField
        id={`section-${section.id}-intro`}
        label={t("settings.public_page.section_intro", "Introduction")}
      >
        <Textarea
          id={`section-${section.id}-intro`}
          rows={3}
          value={intro}
          maxLength={2000}
          onChange={(e) => setIntro(e.target.value)}
        />
      </FormField>

      <Button
        type="button"
        size="sm"
        disabled={save.isPending}
        onClick={() => save.mutate()}
      >
        {save.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
        {t("settings.public_page.section_save", "Save section")}
      </Button>

      {section.has_items && (
        <ItemList section={section} t={t} onSaved={onSaved} />
      )}
    </div>
  );
}

function ItemList({
  section,
  t,
  onSaved,
}: {
  section: Section;
  t: Translate;
  onSaved: () => void;
}) {
  const [title, setTitle] = useState("");

  const addItem = useMutation({
    mutationFn: async () => {
      await apiClient.post(
        `/settings/public-page/sections/${section.id}/items`,
        { title },
      );
    },
    onSuccess: () => {
      setTitle("");
      onSaved();
    },
    onError: () => {
      toast.error(
        t("settings.public_page.item_add_failed", "Could not add that entry."),
      );
    },
  });

  const removeItem = useMutation({
    mutationFn: async (id: string) => {
      await apiClient.delete(`/settings/public-page/items/${id}`);
    },
    onSuccess: onSaved,
  });

  return (
    <div className="space-y-2 rounded-md bg-muted/30 p-3">
      <p className="text-xs font-medium">
        {t("settings.public_page.entries", "Entries")}
      </p>

      <ul className="space-y-1">
        {section.items.map((item) => (
          <li
            key={item.id}
            className="flex items-center gap-2 rounded bg-background p-2 text-sm"
          >
            <span className="flex-1">
              {item.title ||
                t("settings.public_page.entry_untitled", "Untitled entry")}
            </span>

            {item.has_image && !item.image_alt && (
              // Surfaced rather than silently accepted: an image with no
              // description is invisible to anyone using a screen reader.
              <span className="text-xs text-destructive">
                {t("settings.public_page.entry_no_alt", "needs a description")}
              </span>
            )}

            <Button
              type="button"
              variant="ghost"
              size="icon"
              aria-label={t(
                "settings.public_page.remove_entry",
                "Remove entry",
              )}
              onClick={() => removeItem.mutate(item.id)}
            >
              <Trash2 className="h-4 w-4" />
            </Button>
          </li>
        ))}
      </ul>

      <div className="flex gap-2">
        <Input
          value={title}
          maxLength={160}
          placeholder={t("settings.public_page.entry_title", "New entry")}
          aria-label={t("settings.public_page.entry_title", "New entry")}
          onChange={(e) => setTitle(e.target.value)}
        />
        <Button
          type="button"
          size="sm"
          disabled={title.trim() === "" || addItem.isPending}
          onClick={() => addItem.mutate()}
        >
          <Plus className="h-4 w-4" />
        </Button>
      </div>
    </div>
  );
}
