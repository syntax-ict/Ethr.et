"use client";

import { useState } from "react";
import { Mail, Phone, MapPin, CheckCircle2, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { FormField } from "@/components/patterns/FormField";
import { apiClient } from "@/api/client";
import { fieldErrors, type FieldErrors } from "@/lib/errors";
import { useT } from "@/lib/i18n/useT";

const contactChannels = [
  { icon: Mail, key: "email" as const, value: "info@ethr.et" },
  { icon: Phone, key: "phone" as const, value: "+251 11 123 4567" },
  { icon: MapPin, key: "office" as const, value: "" },
];

export function ContactContent() {
  const [loading, setLoading] = useState(false);
  const [success, setSuccess] = useState(false);
  const [error, setError] = useState("");
  const [errors, setErrors] = useState<FieldErrors>({});
  const { t } = useT();

  async function handleSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setLoading(true);
    setError("");
    setErrors({});

    const form = new FormData(e.currentTarget);
    const data = {
      name: form.get("name") as string,
      email: form.get("email") as string,
      phone: form.get("phone") as string,
      organization: form.get("organization") as string,
      message: form.get("message") as string,
      // Honeypot. Empty for a person, filled by a form-filler bot; the API
      // answers 201 either way and simply does not store the submission, so a
      // bot cannot learn which field gave it away.
      website: form.get("website") as string,
    };

    try {
      await apiClient.post("/contact", data);
      setSuccess(true);
    } catch (err) {
      // The bare `catch {}` here discarded the response entirely, so a 422
      // naming the exact invalid field ("the message must be at least 10
      // characters") was flattened into "Failed to send message. Please try
      // again." — leaving the sender to guess. Field messages now render under
      // their inputs; the banner is reserved for failures with no field.
      const fields = fieldErrors(err);
      setErrors(fields);

      const firstField = Object.keys(fields)[0];
      if (firstField) {
        document.getElementById(firstField)?.focus();
      } else {
        setError(
          t(
            "marketing.contact.error",
            "Failed to send message. Please try again.",
          ),
        );
      }
    } finally {
      setLoading(false);
    }
  }

  return (
    <div>
      {/* Hero */}
      <section className="relative overflow-hidden border-b border-border/50 bg-muted/20">
        <div className="pointer-events-none absolute inset-0 -z-10">
          <div className="absolute left-1/2 top-0 h-[400px] w-[600px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-primary/[0.04] blur-3xl" />
        </div>
        <div className="mx-auto max-w-7xl px-4 py-20 sm:px-6 sm:py-24 lg:px-8">
          <div className="mx-auto max-w-2xl text-center">
            <p className="text-sm font-semibold uppercase tracking-wider text-primary">
              {t("marketing.contact.overline", "Contact")}
            </p>
            <h1 className="mt-2 text-4xl font-bold tracking-tight text-foreground sm:text-5xl">
              {t("marketing.contact.title", "Contact Us")}
            </h1>
            <p className="mt-6 text-lg text-muted-foreground">
              {t(
                "marketing.contact.subtitle",
                "Have questions? We would love to hear from you.",
              )}
            </p>
          </div>
        </div>
      </section>

      {/* Content */}
      <section className="py-20 sm:py-24">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="grid gap-12 lg:grid-cols-5">
            {/* Contact info */}
            <div className="lg:col-span-2">
              <h2 className="text-lg font-semibold text-foreground">
                {t("marketing.contact.get_in_touch", "Get in Touch")}
              </h2>
              <p className="mt-2 text-sm text-muted-foreground">
                {t(
                  "marketing.contact.response_time",
                  "We typically respond within 24 hours.",
                )}
              </p>

              <div className="mt-8 space-y-6">
                {contactChannels.map((channel) => {
                  const Icon = channel.icon;
                  return (
                    <div key={channel.key} className="flex items-start gap-4">
                      <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                        <Icon className="h-5 w-5 text-primary" />
                      </div>
                      <div>
                        <h3 className="text-sm font-medium text-foreground">
                          {channel.key === "office"
                            ? t("marketing.contact.office", "Office")
                            : channel.key === "email"
                              ? t("common.email", "Email")
                              : t("common.phone", "Phone")}
                        </h3>
                        <p className="mt-1 text-sm text-muted-foreground">
                          {channel.key === "office"
                            ? t(
                                "marketing.contact.office_address",
                                "Addis Ababa, Ethiopia",
                              )
                            : channel.value}
                        </p>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>

            {/* Form */}
            <div className="lg:col-span-3">
              <div className="rounded-2xl border border-border/60 bg-card p-6 shadow-sm sm:p-8">
                <h2 className="text-lg font-semibold text-foreground">
                  {t("marketing.contact.form_title", "Send us a message")}
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                  {t(
                    "marketing.contact.form_subtitle",
                    "Fill out the form below and we will get back to you within 24 hours.",
                  )}
                </p>

                {success ? (
                  <div className="mt-8 flex flex-col items-center gap-3 rounded-xl bg-status-success/5 p-8 text-center">
                    <div className="flex h-12 w-12 items-center justify-center rounded-full bg-status-success/10">
                      <CheckCircle2 className="h-6 w-6 text-status-success" />
                    </div>
                    <p className="font-semibold text-foreground">
                      {t(
                        "marketing.contact.success",
                        "Message sent successfully!",
                      )}
                    </p>
                    <p className="text-sm text-muted-foreground">
                      {t(
                        "marketing.contact.success_detail",
                        "We will get back to you soon.",
                      )}
                    </p>
                  </div>
                ) : (
                  <form onSubmit={handleSubmit} className="mt-6 space-y-5">
                    <div className="grid gap-5 sm:grid-cols-2">
                      <FormField
                        id="name"
                        label={t("marketing.contact.name", "Full Name")}
                        required
                        error={errors.name}
                      >
                        <Input name="name" required minLength={2} />
                      </FormField>
                      <FormField
                        id="email"
                        label={t("marketing.contact.email", "Email Address")}
                        required
                        error={errors.email}
                      >
                        <Input name="email" type="email" required />
                      </FormField>
                    </div>
                    <div className="grid gap-5 sm:grid-cols-2">
                      <FormField
                        id="phone"
                        label={t("marketing.contact.phone", "Phone Number")}
                        error={errors.phone}
                      >
                        <Input name="phone" placeholder="+251" />
                      </FormField>
                      <FormField
                        id="organization"
                        label={t(
                          "marketing.contact.organization",
                          "Organization",
                        )}
                        error={errors.organization}
                      >
                        <Input name="organization" />
                      </FormField>
                    </div>
                    {/* Off-screen rather than `display: none` — a bot that
                        skips hidden inputs would sail past a display:none trap,
                        and this one is still unreachable by tab, invisible to
                        screen readers, and never autofilled. */}
                    <div
                      aria-hidden="true"
                      className="pointer-events-none absolute -left-[9999px] h-px w-px overflow-hidden"
                    >
                      <label htmlFor="website">Website</label>
                      <input
                        id="website"
                        name="website"
                        type="text"
                        tabIndex={-1}
                        autoComplete="off"
                        defaultValue=""
                      />
                    </div>

                    <FormField
                      id="message"
                      label={t("marketing.contact.message", "Message")}
                      required
                      error={errors.message}
                    >
                      {/* Was a raw <textarea> with the primitive's classes
                          hand-copied, so it missed the aria-invalid styling and
                          would drift from Textarea on any future change. */}
                      <Textarea
                        name="message"
                        required
                        minLength={10}
                        rows={4}
                      />
                    </FormField>

                    {error && (
                      <Alert variant="destructive">
                        <AlertDescription>{error}</AlertDescription>
                      </Alert>
                    )}
                    <Button
                      type="submit"
                      disabled={loading}
                      className="h-11 px-8"
                    >
                      {loading ? (
                        <>
                          <Loader2 className="h-4 w-4 animate-spin" />
                          {t("marketing.contact.sending", "Sending...")}
                        </>
                      ) : (
                        t("marketing.contact.send", "Send Message")
                      )}
                    </Button>
                  </form>
                )}
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>
  );
}
