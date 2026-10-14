"use client";

import { useRef, useState } from "react";
import { QRCodeCanvas } from "qrcode.react";
import {
  Copy,
  Download,
  ExternalLink,
  Loader2,
  LogIn,
  Save,
} from "lucide-react";
import { toast } from "sonner";
import {
  useUpdateLanding,
  type BrandAssets,
  type TenantAddress,
  type TenantLandingSettings,
} from "@/features/settings/api";
import {
  panelBackground,
  type LandingBackground,
} from "@/lib/auth/landing-background";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";
import { OrgMark } from "@/components/shared/org-mark";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { CoverUpload } from "@/features/settings/components/brand-image-upload";

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

export interface LandingCardProps {
  landing: TenantLandingSettings;
  address: TenantAddress;
  organisationName: string;
  logoUrl: string | null;
  /** The uploaded cover, which the photo background draws. */
  cover?: BrandAssets["cover"];
  secondaryColor: string | null | undefined;
}

/**
 * The organisation's sign-in page - ethr.et/{slug}, its subdomain or its own
 * domain. Its admin sets the words, the HR help contacts and the background,
 * sees the result as they type, and takes away the link and a QR code for
 * posters at the entrance or the time clock.
 *
 * The photo background is the uploaded cover: the upload sits under that
 * choice, and until one exists the server refuses the choice, which the
 * card says before the save is tried.
 */
export function LandingCard({
  landing,
  address,
  organisationName,
  logoUrl,
  cover = null,
  secondaryColor,
}: LandingCardProps) {
  const { t } = useT();
  const updateLanding = useUpdateLanding();
  const qrRef = useRef<HTMLCanvasElement>(null);

  const [headline, setHeadline] = useState(landing.headline ?? "");
  const [message, setMessage] = useState(landing.message ?? "");
  const [email, setEmail] = useState(landing.support_email ?? "");
  const [phone, setPhone] = useState(landing.support_phone ?? "");
  const [background, setBackground] = useState<Background>(
    isBackground(landing.background) ? landing.background : "brand",
  );
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

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

  const trimmed = (v: string) => (v.trim() === "" ? null : v.trim());
  const coverMissing = background === "image" && !cover?.url;

  function save() {
    setFieldErrors({});
    updateLanding.mutate(
      {
        headline: trimmed(headline),
        message: trimmed(message),
        support_email: trimmed(email),
        support_phone: trimmed(phone),
        background,
      },
      {
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
            t(
              "settings.landing_save_failed",
              "Failed to save the sign-in page",
            ),
          );
        },
      },
    );
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
        <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
          <div className="space-y-4">
            <Field
              id="landing-headline"
              label={t("settings.landing_headline", "Headline")}
              error={fieldErrors.headline}
              counter={`${headline.length}/${HEADLINE_MAX}`}
            >
              <Input
                id="landing-headline"
                value={headline}
                maxLength={HEADLINE_MAX}
                placeholder={organisationName}
                onChange={(e) => setHeadline(e.target.value)}
              />
            </Field>

            <Field
              id="landing-message"
              label={t("settings.landing_message", "Welcome message")}
              error={fieldErrors.message}
              counter={`${message.length}/${MESSAGE_MAX}`}
            >
              <Textarea
                id="landing-message"
                value={message}
                maxLength={MESSAGE_MAX}
                rows={3}
                placeholder={t(
                  "auth.org_welcome",
                  "Your workspace for attendance, leave and pay.",
                )}
                onChange={(e) => setMessage(e.target.value)}
              />
            </Field>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field
                id="landing-email"
                label={t("settings.landing_support_email", "HR help e-mail")}
                error={fieldErrors.support_email}
              >
                <Input
                  id="landing-email"
                  type="email"
                  value={email}
                  placeholder="hr@example.et"
                  onChange={(e) => setEmail(e.target.value)}
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
                  value={phone}
                  placeholder="+251 11 000 0000"
                  onChange={(e) => setPhone(e.target.value)}
                />
              </Field>
            </div>

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
                    aria-checked={background === option.value}
                    onClick={() => setBackground(option.value)}
                    className={cn(
                      "rounded-md px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                      background === option.value
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
              {background === "image" && (
                <div className="mt-3 space-y-2">
                  <CoverUpload cover={cover} />
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
          </div>

          {/* Live preview: the side panel as it will render. */}
          <div>
            <p className="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
              {t("settings.branding_preview", "Preview")}
            </p>
            <div
              data-testid="landing-preview"
              className="flex min-h-64 flex-col items-center justify-center rounded-xl bg-primary p-6 text-center"
              style={panelBackground({ background }, secondaryColor, cover)}
            >
              <OrgMark
                name={organisationName}
                logoUrl={logoUrl}
                className="h-12 w-12 bg-primary-foreground/15 ring-0"
                initialClassName="text-xl"
              />
              {headline.trim() && (
                <p className="mt-4 text-[10px] font-medium uppercase tracking-wider text-primary-foreground/70">
                  {organisationName}
                </p>
              )}
              <p
                className={cn(
                  "text-balance text-lg font-bold leading-tight text-primary-foreground",
                  headline.trim() ? "mt-1" : "mt-4",
                )}
              >
                {headline.trim() || organisationName}
              </p>
              <p className="mt-2 line-clamp-4 whitespace-pre-line text-xs text-primary-foreground/80">
                {message.trim() ||
                  t(
                    "auth.org_welcome",
                    "Your workspace for attendance, leave and pay.",
                  )}
              </p>
              {(email.trim() || phone.trim()) && (
                <p className="mt-4 text-[11px] text-primary-foreground/80">
                  {[email.trim(), phone.trim()].filter(Boolean).join(" · ")}
                </p>
              )}
            </div>
          </div>
        </div>

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
