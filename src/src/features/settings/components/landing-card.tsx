"use client";

import { useRef, useState } from "react";
import { QRCodeCanvas } from "qrcode.react";
import {
  ArrowDown,
  ArrowUp,
  Copy,
  Download,
  ExternalLink,
  Loader2,
  LogIn,
  Plus,
  Save,
  Trash2,
} from "lucide-react";
import { toast } from "sonner";
import {
  useUpdateLanding,
  type LandingUpdate,
  type TenantAddress,
  type TenantLandingSettings,
} from "@/features/settings/api";
import type {
  PortalSection,
  TenantBrand,
  TenantLanding,
} from "@/lib/auth/use-auth-host-context";
import {
  LINKS,
  PORTAL_SECTIONS,
  SECTORS,
  STAFF_BANDS,
  sectionOn,
  sectionOrder,
} from "@/lib/auth/landing-text";
import type { LandingBackground } from "@/lib/auth/landing-background";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Textarea } from "@/components/ui/textarea";
import { CoverUpload } from "@/features/settings/components/brand-image-upload";
import { PortalPreview } from "@/features/settings/components/portal-preview";

type Background = LandingBackground["background"];
const BACKGROUNDS: readonly Background[] = [
  "brand",
  "gradient",
  "pattern",
  "image",
];

function isBackground(value: unknown): value is Background {
  return BACKGROUNDS.includes(value as Background);
}

const HEADLINE_MAX = 80;
const MESSAGE_MAX = 280;
const ABOUT_MAX = 600;
const ADDRESS_MAX = 200;
const HOURS_MAX = 120;
const FAQ_MAX = 12;
const QUESTION_MAX = 160;
const ANSWER_MAX = 1000;

const SERVICES_MAX = 8;
const SERVICE_TITLE_MAX = 80;
const SERVICE_TEXT_MAX = 400;

/**
 * One item of a bilingual list (a question, a service) as edited: its texts
 * by key, each with an `_am` twin, and a key that survives reordering.
 */
type ListItem = { key: string } & Record<string, string>;

let listKeys = 0;
const listKey = () => `item-${++listKeys}`;

/** A list as sent: blanks trimmed to null, items left wholly empty dropped. */
function listUpdate<K extends string>(
  items: ListItem[],
  keys: readonly K[],
): Array<Record<K, string | null>> {
  return items
    .filter((item) => keys.some((k) => (item[k] ?? "").trim() !== ""))
    .map(
      (item) =>
        Object.fromEntries(
          keys.map((k) => [k, trimmed(item[k] ?? "")]),
        ) as Record<K, string | null>,
    );
}

const FAQ_KEYS = ["question", "question_am", "answer", "answer_am"] as const;
const SERVICE_KEYS = [
  "title",
  "title_am",
  "description",
  "description_am",
] as const;

type LinkKey = (typeof LINKS)[number];

/** The form as edited: every text as a string, every switch as a boolean. */
interface LandingForm {
  headline: string;
  headline_am: string;
  message: string;
  message_am: string;
  support_email: string;
  support_phone: string;
  background: Background;
  about: string;
  about_am: string;
  founded_year: string;
  sector: string;
  staff_band: string;
  show_branch_count: boolean;
  address: string;
  address_am: string;
  hours: string;
  hours_am: string;
  latitude: string;
  longitude: string;
  show_branches: boolean;
  links: Record<LinkKey, string>;
  faqs: ListItem[];
  services: ListItem[];
  sections: Record<PortalSection, boolean>;
  order: PortalSection[];
  listed: boolean;
}

function fromSettings(landing: TenantLandingSettings): LandingForm {
  const text = (v: string | null | undefined) => v ?? "";
  const links = {} as Record<LinkKey, string>;
  for (const key of LINKS) links[key] = text(landing.links?.[key]);
  const sections = {} as Record<PortalSection, boolean>;
  for (const key of PORTAL_SECTIONS) sections[key] = sectionOn(landing, key);
  return {
    headline: text(landing.headline),
    headline_am: text(landing.headline_am),
    message: text(landing.message),
    message_am: text(landing.message_am),
    support_email: text(landing.support_email),
    support_phone: text(landing.support_phone),
    background: isBackground(landing.background) ? landing.background : "brand",
    about: text(landing.about),
    about_am: text(landing.about_am),
    founded_year: landing.founded_year ? String(landing.founded_year) : "",
    sector: text(landing.sector),
    staff_band: text(landing.staff_band),
    show_branch_count: Boolean(landing.show_branch_count),
    address: text(landing.address),
    address_am: text(landing.address_am),
    hours: text(landing.hours),
    hours_am: text(landing.hours_am),
    latitude: landing.latitude == null ? "" : String(landing.latitude),
    longitude: landing.longitude == null ? "" : String(landing.longitude),
    show_branches: Boolean(landing.show_branches),
    links,
    faqs: (landing.faqs ?? []).map((faq) => ({
      key: listKey(),
      question: text(faq.question),
      question_am: text(faq.question_am),
      answer: text(faq.answer),
      answer_am: text(faq.answer_am),
    })),
    services: (landing.services ?? []).map((service) => ({
      key: listKey(),
      title: text(service.title),
      title_am: text(service.title_am),
      description: text(service.description),
      description_am: text(service.description_am),
    })),
    sections,
    order: sectionOrder(landing),
    listed: Boolean(landing.listed),
  };
}

const trimmed = (v: string) => (v.trim() === "" ? null : v.trim());
const numberOrNull = (v: string) => (v.trim() === "" ? null : Number(v));

/** What is sent: trimmed strings, nulls for blanks, numbers as numbers. */
function toUpdate(form: LandingForm): LandingUpdate {
  const links = {} as Record<LinkKey, string | null>;
  for (const key of LINKS) links[key] = trimmed(form.links[key]);
  return {
    headline: trimmed(form.headline),
    headline_am: trimmed(form.headline_am),
    message: trimmed(form.message),
    message_am: trimmed(form.message_am),
    support_email: trimmed(form.support_email),
    support_phone: trimmed(form.support_phone),
    background: form.background,
    about: trimmed(form.about),
    about_am: trimmed(form.about_am),
    founded_year: numberOrNull(form.founded_year),
    sector: trimmed(form.sector) as LandingUpdate["sector"],
    staff_band: trimmed(form.staff_band) as LandingUpdate["staff_band"],
    show_branch_count: form.show_branch_count,
    address: trimmed(form.address),
    address_am: trimmed(form.address_am),
    hours: trimmed(form.hours),
    hours_am: trimmed(form.hours_am),
    latitude: numberOrNull(form.latitude),
    longitude: numberOrNull(form.longitude),
    show_branches: form.show_branches,
    links,
    faqs: listUpdate(form.faqs, FAQ_KEYS),
    services: listUpdate(form.services, SERVICE_KEYS),
    sections: form.sections,
    order: form.order,
    listed: form.listed,
  };
}

/** The form as the portal reads it, for the live preview. */
function toLanding(form: LandingForm): TenantLanding {
  const update = toUpdate(form);
  return {
    ...update,
    headline: update.headline ?? null,
    message: update.message ?? null,
    support_email: update.support_email ?? null,
    support_phone: update.support_phone ?? null,
    background: form.background,
    order: form.order,
  };
}

export interface LandingCardProps {
  landing: TenantLandingSettings;
  address: TenantAddress;
  organisationName: string;
  /** The organisation's images and colours, for the preview. */
  brand: TenantBrand;
}

/**
 * The organisation's portal - ethr.et/{slug}, its subdomain or its own
 * domain. Its admin sets the words in both languages, what the page says
 * about the organisation, how to reach it, its links, which sections show
 * and in what order, and whether search engines may list the page; sees the
 * whole page as it will render while typing; and takes away the link and a
 * QR code for posters at the entrance or the time clock.
 *
 * The photo background is the uploaded cover: the upload sits under that
 * choice, and until one exists the server refuses the choice, which the
 * card says before the save is tried.
 */
export function LandingCard({
  landing,
  address,
  organisationName,
  brand,
}: LandingCardProps) {
  const { t } = useT();
  const updateLanding = useUpdateLanding();
  const linkLabel = useLinkLabel();
  const qrRef = useRef<HTMLCanvasElement>(null);

  const [form, setForm] = useState<LandingForm>(() => fromSettings(landing));
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const set = <K extends keyof LandingForm>(key: K, value: LandingForm[K]) =>
    setForm((prev) => ({ ...prev, [key]: value }));

  // The address people type or scan: the organisation's own address when it
  // has one, else the shared ethr.et/{slug}.
  const signInUrl = address.canonical_url
    ? `${address.canonical_url.replace(/\/$/, "")}/login`
    : address.entry_url;

  const backgrounds: Array<{ value: Background; label: string }> = [
    { value: "brand", label: t("settings.landing_bg_brand", "Brand colour") },
    {
      value: "gradient",
      label: t("settings.landing_bg_gradient", "Gradient"),
    },
    { value: "pattern", label: t("settings.landing_bg_pattern", "Pattern") },
    { value: "image", label: t("settings.landing_bg_image", "Photo") },
  ];

  const sectionLabels: Record<PortalSection, string> = {
    about: t("landing.about", "About us"),
    announcements: t("landing.news", "Latest news"),
    vacancies: t("landing.vacancies", "Open positions"),
    holidays: t("landing.holidays", "Upcoming holidays"),
    faq: t("landing.faq", "Frequently asked questions"),
    services: t("landing.services", "Our services"),
    contact: t("landing.contact", "Contact"),
  };

  const coverMissing = form.background === "image" && !brand.cover?.url;

  function move(section: PortalSection, by: -1 | 1) {
    setForm((prev) => {
      const order = [...prev.order];
      const from = order.indexOf(section);
      const to = from + by;
      if (from < 0 || to < 0 || to >= order.length) return prev;
      order.splice(from, 1);
      order.splice(to, 0, section);
      return { ...prev, order };
    });
  }

  function save() {
    setFieldErrors({});
    updateLanding.mutate(toUpdate(form), {
      onSuccess: () =>
        toast.success(t("settings.landing_saved", "Sign-in page saved")),
      onError: (error: unknown) => {
        const errors = (
          error as {
            response?: { data?: { errors?: Record<string, string[]> } };
          }
        ).response?.data?.errors;
        if (errors) {
          setFieldErrors(
            Object.fromEntries(
              Object.entries(errors).map(([k, v]) => [k, v[0] ?? ""]),
            ),
          );
        }
        toast.error(
          t("settings.landing_save_failed", "Failed to save the sign-in page"),
        );
      },
    });
  }

  async function copyLink() {
    try {
      await navigator.clipboard.writeText(signInUrl);
      toast.success(t("settings.landing_link_copied", "Link copied"));
    } catch {
      toast.error(t("settings.landing_link_copy_failed", "Could not copy"));
    }
  }

  function downloadQr() {
    const canvas = qrRef.current;
    if (!canvas) return;
    const link = document.createElement("a");
    link.href = canvas.toDataURL("image/png");
    link.download = `${address.subdomain}-sign-in-qr.png`;
    link.click();
  }

  const bilingual = (
    key: "headline" | "message" | "about" | "address" | "hours",
    label: string,
    max: number,
    multiline: boolean,
    placeholder?: string,
  ) => {
    const amKey = `${key}_am` as const;
    const Control = multiline ? Textarea : Input;
    return (
      <div className="grid gap-4 sm:grid-cols-2">
        <Field
          id={`landing-${key}`}
          label={`${label} (English)`}
          error={fieldErrors[key]}
          counter={`${form[key].length}/${max}`}
        >
          <Control
            id={`landing-${key}`}
            value={form[key]}
            maxLength={max}
            placeholder={placeholder}
            rows={multiline ? 3 : undefined}
            onChange={(e) => set(key, e.target.value)}
          />
        </Field>
        <Field
          id={`landing-${amKey}`}
          label={`${label} (አማርኛ)`}
          error={fieldErrors[amKey]}
          counter={`${form[amKey].length}/${max}`}
        >
          <Control
            id={`landing-${amKey}`}
            value={form[amKey]}
            maxLength={max}
            lang="am"
            rows={multiline ? 3 : undefined}
            onChange={(e) => set(amKey, e.target.value)}
          />
        </Field>
      </div>
    );
  };

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center gap-2">
          <LogIn className="h-4 w-4 text-muted-foreground" />
          <CardTitle className="text-base">
            {t("settings.landing_title", "Sign-in page")}
          </CardTitle>
        </div>
        <p className="text-sm text-muted-foreground">
          {t(
            "settings.landing_intro",
            "What your people see before they sign in. Everything here is public.",
          )}
        </p>
      </CardHeader>
      <CardContent className="space-y-6">
        <Tabs defaultValue="words">
          <TabsList className="flex w-full flex-wrap">
            <TabsTrigger value="words">
              {t("settings.landing_tab_words", "Words")}
            </TabsTrigger>
            <TabsTrigger value="about">
              {t("settings.landing_tab_about", "About")}
            </TabsTrigger>
            <TabsTrigger value="contact">
              {t("settings.landing_tab_contact", "Contact")}
            </TabsTrigger>
            <TabsTrigger value="links">
              {t("settings.landing_tab_links", "Links")}
            </TabsTrigger>
            <TabsTrigger value="services">
              {t("settings.landing_tab_services", "Services")}
            </TabsTrigger>
            <TabsTrigger value="faq">
              {t("settings.landing_tab_faq", "Questions")}
            </TabsTrigger>
            <TabsTrigger value="sections">
              {t("settings.landing_tab_sections", "Sections")}
            </TabsTrigger>
          </TabsList>

          <TabsContent value="words" className="mt-4 space-y-4">
            {bilingual(
              "headline",
              t("settings.landing_headline", "Headline"),
              HEADLINE_MAX,
              false,
              organisationName,
            )}
            {bilingual(
              "message",
              t("settings.landing_message", "Welcome message"),
              MESSAGE_MAX,
              true,
              t(
                "auth.org_welcome",
                "Your workspace for attendance, leave and pay.",
              ),
            )}
            <fieldset>
              <legend className="text-sm font-medium">
                {t("settings.landing_background", "Background")}
              </legend>
              <div
                role="radiogroup"
                className="mt-1 inline-flex flex-wrap rounded-lg border bg-muted/40 p-0.5"
              >
                {backgrounds.map((option) => (
                  <button
                    key={option.value}
                    type="button"
                    role="radio"
                    aria-checked={form.background === option.value}
                    onClick={() => set("background", option.value)}
                    className={cn(
                      "rounded-md px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                      form.background === option.value
                        ? "bg-background text-foreground shadow-xs"
                        : "text-muted-foreground hover:text-foreground",
                    )}
                  >
                    {option.label}
                  </button>
                ))}
              </div>
              {fieldErrors.background && (
                <p className="mt-1 text-xs text-destructive" role="alert">
                  {fieldErrors.background}
                </p>
              )}
              {form.background === "image" && (
                <div className="mt-3 space-y-2">
                  <CoverUpload cover={brand.cover ?? null} />
                  {coverMissing && (
                    <p className="text-xs text-muted-foreground" role="note">
                      {t(
                        "settings.landing_cover_missing",
                        "Upload a cover photo to use the photo background.",
                      )}
                    </p>
                  )}
                </div>
              )}
            </fieldset>
          </TabsContent>

          <TabsContent value="about" className="mt-4 space-y-4">
            {bilingual(
              "about",
              t("settings.landing_about", "About the organisation"),
              ABOUT_MAX,
              true,
            )}
            <div className="grid gap-4 sm:grid-cols-3">
              <Field
                id="landing-founded"
                label={t("settings.landing_founded", "Founded in")}
                error={fieldErrors.founded_year}
              >
                <Input
                  id="landing-founded"
                  inputMode="numeric"
                  value={form.founded_year}
                  placeholder="1998"
                  onChange={(e) =>
                    set("founded_year", e.target.value.replace(/\D/g, ""))
                  }
                />
              </Field>
              <Field
                id="landing-sector"
                label={t("settings.landing_sector", "Sector")}
                error={fieldErrors.sector}
              >
                <Select
                  value={form.sector || "none"}
                  onValueChange={(v) => set("sector", v === "none" ? "" : v)}
                >
                  <SelectTrigger id="landing-sector">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">
                      {t("settings.landing_not_stated", "Not stated")}
                    </SelectItem>
                    {SECTORS.map((sector) => (
                      <SelectItem key={sector} value={sector}>
                        {t(`landing.sector_${sector}`, sector)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
              <Field
                id="landing-staff"
                label={t("settings.landing_staff_band", "Staff size")}
                error={fieldErrors.staff_band}
              >
                <Select
                  value={form.staff_band || "none"}
                  onValueChange={(v) =>
                    set("staff_band", v === "none" ? "" : v)
                  }
                >
                  <SelectTrigger id="landing-staff">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">
                      {t("settings.landing_not_stated", "Not stated")}
                    </SelectItem>
                    {STAFF_BANDS.map((band) => (
                      <SelectItem key={band} value={band}>
                        {band}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
            </div>
            <SwitchRow
              id="landing-branch-count"
              label={t(
                "settings.landing_show_branch_count",
                "Show how many places we have",
              )}
              help={t(
                "settings.landing_show_branch_count_help",
                "Counts your active branches; nothing else about them.",
              )}
              checked={form.show_branch_count}
              onCheckedChange={(v) => set("show_branch_count", v)}
            />
          </TabsContent>

          <TabsContent value="contact" className="mt-4 space-y-4">
            {bilingual(
              "address",
              t("settings.landing_address", "Address"),
              ADDRESS_MAX,
              true,
            )}
            {bilingual(
              "hours",
              t("settings.landing_hours", "Office hours"),
              HOURS_MAX,
              false,
            )}
            <div className="grid gap-4 sm:grid-cols-2">
              <Field
                id="landing-email"
                label={t("settings.landing_support_email", "HR help e-mail")}
                error={fieldErrors.support_email}
              >
                <Input
                  id="landing-email"
                  type="email"
                  value={form.support_email}
                  placeholder="hr@example.et"
                  onChange={(e) => set("support_email", e.target.value)}
                />
              </Field>
              <Field
                id="landing-phone"
                label={t("settings.landing_support_phone", "HR help phone")}
                error={fieldErrors.support_phone}
              >
                <Input
                  id="landing-phone"
                  type="tel"
                  value={form.support_phone}
                  placeholder="+251 11 000 0000"
                  onChange={(e) => set("support_phone", e.target.value)}
                />
              </Field>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field
                id="landing-latitude"
                label={t("settings.landing_latitude", "Latitude")}
                error={fieldErrors.latitude}
                help={t(
                  "settings.landing_coordinates_help",
                  "For an Open in Maps link; no map is embedded.",
                )}
              >
                <Input
                  id="landing-latitude"
                  inputMode="decimal"
                  value={form.latitude}
                  placeholder="9.0108"
                  onChange={(e) => set("latitude", e.target.value)}
                />
              </Field>
              <Field
                id="landing-longitude"
                label={t("settings.landing_longitude", "Longitude")}
                error={fieldErrors.longitude}
              >
                <Input
                  id="landing-longitude"
                  inputMode="decimal"
                  value={form.longitude}
                  placeholder="38.7613"
                  onChange={(e) => set("longitude", e.target.value)}
                />
              </Field>
            </div>
            <SwitchRow
              id="landing-branches"
              label={t("settings.landing_show_branches", "List our places")}
              help={t(
                "settings.landing_show_branches_help",
                "Your active branches with their city, address and phone, from the branches you already keep.",
              )}
              checked={form.show_branches}
              onCheckedChange={(v) => set("show_branches", v)}
            />
          </TabsContent>

          <TabsContent value="links" className="mt-4 space-y-4">
            <p className="text-xs text-muted-foreground">
              {t(
                "settings.landing_links_help",
                "https links only. Each network link must be on that network's own site. Privacy and terms fall back to ETHR's when empty.",
              )}
            </p>
            <div className="grid gap-4 sm:grid-cols-2">
              {LINKS.map((key) => (
                <Field
                  key={key}
                  id={`landing-link-${key}`}
                  label={linkLabel(key)}
                  error={fieldErrors[`links.${key}`]}
                >
                  <Input
                    id={`landing-link-${key}`}
                    type="url"
                    inputMode="url"
                    value={form.links[key]}
                    placeholder={LINK_PLACEHOLDERS[key]}
                    onChange={(e) =>
                      set("links", { ...form.links, [key]: e.target.value })
                    }
                  />
                </Field>
              ))}
            </div>
          </TabsContent>

          <TabsContent value="services" className="mt-4 space-y-4">
            <BilingualList
              id="landing-service"
              errorKey="services"
              items={form.services}
              onChange={(services) => set("services", services)}
              max={SERVICES_MAX}
              fields={[
                {
                  key: "title",
                  label: t("settings.landing_service_title", "Service"),
                  max: SERVICE_TITLE_MAX,
                },
                {
                  key: "description",
                  label: t("settings.landing_service_text", "Description"),
                  max: SERVICE_TEXT_MAX,
                  multiline: true,
                },
              ]}
              itemName={(n) =>
                t("settings.landing_service_item", "Service :n", { n })
              }
              help={t(
                "settings.landing_services_help",
                "What your organisation offers the public, such as its products, services or programmes. Each needs a name in English, Amharic or both; a description is optional.",
              )}
              empty={t("settings.landing_services_empty", "No services yet.")}
              add={t("settings.landing_services_add", "Add a service")}
              full={t(
                "settings.landing_services_full",
                "Eight services is the most",
              )}
              errors={fieldErrors}
            />
          </TabsContent>

          <TabsContent value="faq" className="mt-4 space-y-4">
            <BilingualList
              id="landing-faq"
              errorKey="faqs"
              items={form.faqs}
              onChange={(faqs) => set("faqs", faqs)}
              max={FAQ_MAX}
              fields={[
                {
                  key: "question",
                  label: t("settings.landing_faq_question", "Question"),
                  max: QUESTION_MAX,
                },
                {
                  key: "answer",
                  label: t("settings.landing_faq_answer", "Answer"),
                  max: ANSWER_MAX,
                  multiline: true,
                },
              ]}
              itemName={(n) =>
                t("settings.landing_faq_item", "Question :n", { n })
              }
              help={t(
                "settings.landing_faq_help",
                "Answer the questions people ask most, such as payday, working hours or how to apply. Each needs a question and an answer in English, Amharic or both.",
              )}
              empty={t("settings.landing_faq_empty", "No questions yet.")}
              add={t("settings.landing_faq_add", "Add a question")}
              full={t(
                "settings.landing_faq_full",
                "Twelve questions is the most",
              )}
              errors={fieldErrors}
            />
          </TabsContent>

          <TabsContent value="sections" className="mt-4 space-y-4">
            <ul className="divide-y divide-border rounded-lg border">
              {form.order.map((section, index) => (
                <li key={section} className="flex items-center gap-3 px-3 py-2">
                  <Switch
                    id={`landing-section-${section}`}
                    checked={form.sections[section]}
                    onCheckedChange={(v) =>
                      set("sections", { ...form.sections, [section]: v })
                    }
                  />
                  <Label
                    htmlFor={`landing-section-${section}`}
                    className="min-w-0 flex-1"
                  >
                    {sectionLabels[section]}
                  </Label>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={index === 0}
                    aria-label={t(
                      "settings.landing_move_up",
                      "Move :section up",
                      {
                        section: sectionLabels[section],
                      },
                    )}
                    onClick={() => move(section, -1)}
                  >
                    <ArrowUp className="h-4 w-4" aria-hidden="true" />
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={index === form.order.length - 1}
                    aria-label={t(
                      "settings.landing_move_down",
                      "Move :section down",
                      { section: sectionLabels[section] },
                    )}
                    onClick={() => move(section, 1)}
                  >
                    <ArrowDown className="h-4 w-4" aria-hidden="true" />
                  </Button>
                </li>
              ))}
            </ul>
            <p className="text-xs text-muted-foreground">
              {t(
                "settings.landing_sections_help",
                "A section with nothing to show stays hidden even when it is on.",
              )}
            </p>
            <SwitchRow
              id="landing-listed"
              label={t(
                "settings.landing_listed",
                "List this page in search engines",
              )}
              help={t(
                "settings.landing_listed_help",
                "On by default while your organisation is active, so people who search for its name find this page. Turn it off to keep the page out of search results. Nothing behind sign-in is ever listed.",
              )}
              checked={form.listed}
              onCheckedChange={(v) => set("listed", v)}
            />
          </TabsContent>
        </Tabs>

        <PortalPreview
          name={organisationName}
          brand={brand}
          landing={toLanding(form)}
        />

        {/* Share it: the link, and a QR code for a poster. */}
        <div className="flex flex-col gap-4 rounded-lg border p-4 sm:flex-row sm:items-center">
          <QRCodeCanvas
            ref={qrRef}
            value={signInUrl}
            size={512}
            marginSize={2}
            title={t("settings.landing_qr", "Sign-in page QR code")}
            className="h-28 w-28 shrink-0 rounded-md border bg-white"
            style={{ width: "7rem", height: "7rem" }}
          />
          <div className="min-w-0 flex-1 space-y-2">
            <p className="text-sm font-medium">
              {t("settings.landing_share", "Share your sign-in page")}
            </p>
            <code
              className="block break-all rounded-md bg-muted px-2 py-1 font-mono text-sm"
              data-testid="landing-link"
            >
              {signInUrl}
            </code>
            <div className="flex flex-wrap gap-2">
              <Button variant="outline" size="sm" onClick={copyLink}>
                <Copy className="mr-2 h-3.5 w-3.5" />
                {t("settings.landing_copy_link", "Copy link")}
              </Button>
              <Button variant="outline" size="sm" asChild>
                <a href={signInUrl} target="_blank" rel="noopener noreferrer">
                  <ExternalLink className="mr-2 h-3.5 w-3.5" />
                  {t("settings.landing_open", "Open")}
                </a>
              </Button>
              <Button variant="outline" size="sm" onClick={downloadQr}>
                <Download className="mr-2 h-3.5 w-3.5" />
                {t("settings.landing_download_qr", "Download QR code")}
              </Button>
            </div>
          </div>
        </div>

        <div className="flex justify-end">
          <Button
            onClick={save}
            disabled={updateLanding.isPending || coverMissing}
          >
            {updateLanding.isPending ? (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            ) : (
              <Save className="mr-2 h-4 w-4" />
            )}
            {t("settings.landing_save", "Save sign-in page")}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

/** The networks keep their own names in every language; the rest are translated. */
function useLinkLabel() {
  const { t } = useT();
  return (key: LinkKey): string => {
    switch (key) {
      case "website":
        return t("settings.landing_link_website", "Website");
      case "privacy":
        return t("settings.landing_link_privacy", "Privacy policy");
      case "terms":
        return t("settings.landing_link_terms", "Terms");
      default:
        return LINK_LABELS[key];
    }
  };
}

const LINK_LABELS: Record<LinkKey, string> = {
  website: "Website",
  linkedin: "LinkedIn",
  facebook: "Facebook",
  x: "X",
  telegram: "Telegram",
  youtube: "YouTube",
  privacy: "Privacy policy",
  terms: "Terms",
};

const LINK_PLACEHOLDERS: Record<LinkKey, string> = {
  website: "https://example.et",
  linkedin: "https://www.linkedin.com/company/example",
  facebook: "https://www.facebook.com/example",
  x: "https://x.com/example",
  telegram: "https://t.me/example",
  youtube: "https://www.youtube.com/@example",
  privacy: "https://example.et/privacy",
  terms: "https://example.et/terms",
};

/**
 * A short bilingual list the organisation writes (its questions, its
 * services): each item's fields in English and Amharic, reordered with up and
 * down, removed, and added up to `max`. The server's refusal for an item's
 * field shows under its English input.
 */
function BilingualList({
  id,
  errorKey,
  items,
  onChange,
  max,
  fields,
  itemName,
  help,
  empty,
  add,
  full,
  errors,
}: {
  id: string;
  errorKey: string;
  items: ListItem[];
  onChange: (items: ListItem[]) => void;
  max: number;
  fields: Array<{
    key: string;
    label: string;
    max: number;
    multiline?: boolean;
  }>;
  itemName: (n: number) => string;
  help: string;
  empty: string;
  add: string;
  full: string;
  errors: Record<string, string>;
}) {
  const { t } = useT();

  const update = (index: number, key: string, value: string) =>
    onChange(
      items.map((item, i) => (i === index ? { ...item, [key]: value } : item)),
    );
  const move = (index: number, by: -1 | 1) => {
    const to = index + by;
    if (to < 0 || to >= items.length) return;
    const next = [...items];
    const [item] = next.splice(index, 1);
    next.splice(to, 0, item);
    onChange(next);
  };
  const blank = (): ListItem => {
    const item: ListItem = { key: listKey() };
    for (const field of fields) {
      item[field.key] = "";
      item[`${field.key}_am`] = "";
    }
    return item;
  };

  return (
    <>
      <p className="text-sm text-muted-foreground">{help}</p>
      {items.length === 0 ? (
        <p className="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">
          {empty}
        </p>
      ) : (
        <ol className="space-y-4">
          {items.map((item, index) => {
            const n = index + 1;
            const name = itemName(n);
            return (
              <li key={item.key} className="space-y-3 rounded-lg border p-3">
                <div className="flex items-center gap-1">
                  <span className="min-w-0 flex-1 text-sm font-medium">
                    {name}
                  </span>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={index === 0}
                    aria-label={t(
                      "settings.landing_move_up",
                      "Move :section up",
                      {
                        section: name,
                      },
                    )}
                    onClick={() => move(index, -1)}
                  >
                    <ArrowUp className="h-4 w-4" aria-hidden="true" />
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={index === items.length - 1}
                    aria-label={t(
                      "settings.landing_move_down",
                      "Move :section down",
                      { section: name },
                    )}
                    onClick={() => move(index, 1)}
                  >
                    <ArrowDown className="h-4 w-4" aria-hidden="true" />
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    aria-label={t(
                      "settings.landing_item_remove",
                      "Remove :item",
                      {
                        item: name,
                      },
                    )}
                    onClick={() =>
                      onChange(items.filter((_, i) => i !== index))
                    }
                  >
                    <Trash2 className="h-4 w-4" aria-hidden="true" />
                  </Button>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                  {fields.flatMap((field) =>
                    (["", "_am"] as const).map((suffix) => {
                      const key = `${field.key}${suffix}`;
                      const inputId = `${id}-${n}-${key}`;
                      const Control = field.multiline ? Textarea : Input;
                      return (
                        <Field
                          key={key}
                          id={inputId}
                          label={`${field.label} ${n} (${suffix ? "አማርኛ" : "English"})`}
                          error={
                            suffix
                              ? undefined
                              : errors[`${errorKey}.${index}.${field.key}`]
                          }
                          counter={`${(item[key] ?? "").length}/${field.max}`}
                        >
                          <Control
                            id={inputId}
                            value={item[key] ?? ""}
                            maxLength={field.max}
                            rows={field.multiline ? 3 : undefined}
                            lang={suffix ? "am" : undefined}
                            onChange={(e) => update(index, key, e.target.value)}
                          />
                        </Field>
                      );
                    }),
                  )}
                </div>
              </li>
            );
          })}
        </ol>
      )}
      <Button
        type="button"
        variant="outline"
        size="sm"
        disabled={items.length >= max}
        onClick={() => onChange([...items, blank()])}
      >
        <Plus className="mr-2 h-4 w-4" aria-hidden="true" />
        {items.length >= max ? full : add}
      </Button>
    </>
  );
}

function SwitchRow({
  id,
  label,
  help,
  checked,
  onCheckedChange,
}: {
  id: string;
  label: string;
  help: string;
  checked: boolean;
  onCheckedChange: (checked: boolean) => void;
}) {
  return (
    <div className="flex items-start gap-3 rounded-lg border p-3">
      <Switch id={id} checked={checked} onCheckedChange={onCheckedChange} />
      <div className="min-w-0">
        <Label htmlFor={id}>{label}</Label>
        <p className="mt-0.5 text-xs text-muted-foreground">{help}</p>
      </div>
    </div>
  );
}

function Field({
  id,
  label,
  error,
  help,
  counter,
  children,
}: {
  id: string;
  label: string;
  error?: string;
  help?: string;
  counter?: string;
  children: React.ReactNode;
}) {
  return (
    <div>
      <div className="flex items-baseline justify-between gap-2">
        <Label htmlFor={id}>{label}</Label>
        {counter && (
          <span className="text-xs tabular-nums text-muted-foreground">
            {counter}
          </span>
        )}
      </div>
      <div className="mt-1">{children}</div>
      {error ? (
        <p className="mt-1 text-xs text-destructive" role="alert">
          {error}
        </p>
      ) : (
        help && <p className="mt-1 text-xs text-muted-foreground">{help}</p>
      )}
    </div>
  );
}
