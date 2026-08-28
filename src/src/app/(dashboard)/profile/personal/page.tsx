"use client";

import { useEffect, useState } from "react";
import { fieldErrors } from "@/lib/errors";
import { useForm, Controller } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { Plus, Save, Trash2, Loader2, Pencil, X, Users } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { PhoneInput } from "@/components/shared/phone-input";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import {
  useCreateEmergencyContact,
  useDeleteEmergencyContact,
  useMyProfile,
  useUpdateEmergencyContact,
  useUpdateProfile,
  type EmergencyContact,
  type ProfileResponse,
} from "@/features/profile/api";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

const MARITAL_STATUSES = ["single", "married", "divorced", "widowed"] as const;
const RELATIONSHIPS = [
  "spouse",
  "parent",
  "sibling",
  "child",
  "friend",
  "other",
] as const;
/** Server-side cap; kept here so the Add button disables instead of 422-ing. */
const MAX_CONTACTS = 5;

const personalSchema = z.object({
  phone: z.string().max(20).optional(),
  marital_status: z.string().optional(),
  nationality: z.string().max(100).optional(),
});
type PersonalForm = z.infer<typeof personalSchema>;

export default function PersonalDetailsPage() {
  const query = useMyProfile();

  return (
    <QueryBoundary query={query}>
      {(profile) => <PersonalDetails profile={profile} />}
    </QueryBoundary>
  );
}

function PersonalDetails({ profile }: { profile: ProfileResponse }) {
  const { t } = useT();
  const updateProfile = useUpdateProfile();
  const employee = profile.employee;

  const {
    control,
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isDirty },
  } = useForm<PersonalForm>({
    resolver: zodResolver(personalSchema),
    defaultValues: {
      phone: employee?.phone ?? profile.user.phone ?? "",
      marital_status: employee?.marital_status ?? "",
      nationality: employee?.nationality ?? "",
    },
  });

  // The form used to open blank and overwrite whatever was on record with it.
  // Re-seeding from the server keeps "save" meaning "save my edits".
  useEffect(() => {
    reset({
      phone: employee?.phone ?? profile.user.phone ?? "",
      marital_status: employee?.marital_status ?? "",
      nationality: employee?.nationality ?? "",
    });
  }, [
    employee?.phone,
    employee?.marital_status,
    employee?.nationality,
    profile.user.phone,
    reset,
  ]);

  async function onSave(values: PersonalForm) {
    try {
      await updateProfile.mutateAsync({
        phone: values.phone || undefined,
        marital_status: values.marital_status || undefined,
        nationality: values.nationality || undefined,
      });
      toast.success(t("profile.updated", "Profile updated successfully."));
    } catch (err) {
      // A bare `catch {}` threw away the server's RFC-7807 `errors` map, so a
      // rejected phone number or nationality surfaced only as a generic toast
      // with no indication of which field the server objected to. Feeding it
      // back through `setError` puts each message under its own input, reusing
      // the same `errors` rendering the client-side zod schema already drives.
      const fields = fieldErrors(err);
      for (const [name, message] of Object.entries(fields)) {
        setError(name as keyof PersonalForm, { type: "server", message });
      }
      if (Object.keys(fields).length === 0) {
        toast.error(
          t(
            "profile.update_failed",
            "Failed to update profile. Please try again.",
          ),
        );
      }
    }
  }

  if (!employee) {
    return (
      <Card>
        <CardContent className="py-10 text-center text-sm text-muted-foreground">
          {t(
            "profile.no_employee_record",
            "Your account is not linked to an employee record yet. Ask your HR administrator to link it.",
          )}
        </CardContent>
      </Card>
    );
  }

  return (
    <div className="space-y-6">
      <form onSubmit={handleSubmit(onSave)}>
        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              {t("profile.personal_details", "Personal details")}
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-3">
              <div className="space-y-2">
                <Label htmlFor="phone">
                  {t("profile.phone_number", "Phone Number")}
                </Label>
                <Controller
                  control={control}
                  name="phone"
                  render={({ field }) => (
                    <PhoneInput
                      id="phone"
                      value={field.value ?? ""}
                      onChange={field.onChange}
                    />
                  )}
                />
                {errors.phone && (
                  <p className="text-xs text-destructive">
                    {errors.phone.message}
                  </p>
                )}
              </div>

              <div className="space-y-2">
                <Label htmlFor="marital_status">
                  {t("employee.detail.marital_status", "Marital status")}
                </Label>
                <Controller
                  control={control}
                  name="marital_status"
                  render={({ field }) => (
                    <Select
                      value={field.value || undefined}
                      onValueChange={field.onChange}
                    >
                      <SelectTrigger id="marital_status">
                        <SelectValue
                          placeholder={t("common.select", "Select")}
                        />
                      </SelectTrigger>
                      <SelectContent>
                        {MARITAL_STATUSES.map((status) => (
                          <SelectItem key={status} value={status}>
                            {t(`marital_status.${status}`, status)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="nationality">
                  {t("employee.detail.nationality", "Nationality")}
                </Label>
                <Input
                  id="nationality"
                  placeholder="Ethiopian"
                  {...register("nationality")}
                />
              </div>
            </div>

            <p className="text-xs text-muted-foreground">
              {t(
                "profile.gated_fields_note",
                "Your name, birth date, TIN and bank details are changed through a request to HR.",
              )}
            </p>

            <div className="flex justify-end gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={!isDirty || updateProfile.isPending}
                onClick={() => reset()}
              >
                <X className="mr-1 h-3 w-3" />
                {t("common.cancel", "Cancel")}
              </Button>
              <Button
                type="submit"
                size="sm"
                disabled={updateProfile.isPending}
              >
                {updateProfile.isPending ? (
                  <Loader2 className="mr-1 h-3 w-3 animate-spin" />
                ) : (
                  <Save className="mr-1 h-3 w-3" />
                )}
                {t("profile.save_changes", "Save Changes")}
              </Button>
            </div>
          </CardContent>
        </Card>
      </form>

      <EmergencyContactsCard contacts={profile.emergency_contacts ?? []} />
    </div>
  );
}

function EmergencyContactsCard({ contacts }: { contacts: EmergencyContact[] }) {
  const { t } = useT();
  const [editing, setEditing] = useState<EmergencyContact | "new" | null>(null);
  const [pendingDelete, setPendingDelete] = useState<EmergencyContact | null>(
    null,
  );
  const remove = useDeleteEmergencyContact();

  async function confirmDelete() {
    if (!pendingDelete) return;

    try {
      await remove.mutateAsync(pendingDelete.public_id);
      toast.success(t("profile.contact_removed", "Emergency contact removed."));
    } catch {
      toast.error(t("common.action_failed", "That didn't work. Try again."));
    } finally {
      setPendingDelete(null);
    }
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between space-y-0">
        <CardTitle className="flex items-center gap-2 text-base">
          <Users className="h-4 w-4 text-muted-foreground" />
          {t("profile.emergency_contacts", "Emergency contacts")}
        </CardTitle>
        <Button
          size="sm"
          variant="outline"
          disabled={contacts.length >= MAX_CONTACTS || editing === "new"}
          onClick={() => setEditing("new")}
        >
          <Plus className="mr-1 h-3 w-3" />
          {t("profile.add_contact", "Add contact")}
        </Button>
      </CardHeader>
      <CardContent className="space-y-3">
        {contacts.length === 0 && editing !== "new" && (
          <p className="py-6 text-center text-sm text-muted-foreground">
            {t(
              "profile.no_emergency_contacts",
              "No emergency contacts on file. Add someone we should call in an emergency.",
            )}
          </p>
        )}

        {contacts.map((contact) =>
          editing !== null &&
          editing !== "new" &&
          editing.public_id === contact.public_id ? (
            <ContactForm
              key={contact.public_id}
              contact={contact}
              onDone={() => setEditing(null)}
            />
          ) : (
            <div
              key={contact.public_id}
              className="flex flex-col gap-2 rounded-md border border-border-default p-3 sm:flex-row sm:items-center sm:justify-between"
            >
              <div>
                <p className="text-sm font-medium text-foreground">
                  {contact.name}
                </p>
                <p className="text-sm capitalize text-muted-foreground">
                  {t(
                    `relationship.${contact.relationship}`,
                    contact.relationship,
                  )}{" "}
                  · <span className="tabular-nums">{contact.phone}</span>
                </p>
              </div>
              <div className="flex gap-1">
                <Button
                  size="sm"
                  variant="ghost"
                  aria-label={t("common.edit", "Edit")}
                  onClick={() => setEditing(contact)}
                >
                  <Pencil className="h-3 w-3" />
                </Button>
                <Button
                  size="sm"
                  variant="ghost"
                  aria-label={t("common.delete", "Delete")}
                  onClick={() => setPendingDelete(contact)}
                >
                  <Trash2 className="h-3 w-3 text-destructive" />
                </Button>
              </div>
            </div>
          ),
        )}

        {editing === "new" && <ContactForm onDone={() => setEditing(null)} />}
      </CardContent>

      <ConfirmDialog
        open={pendingDelete !== null}
        onOpenChange={(open) => !open && setPendingDelete(null)}
        title={t("profile.remove_contact_title", "Remove this contact?")}
        description={t(
          "profile.remove_contact_hint",
          "They will no longer be called in an emergency.",
        )}
        confirmLabel={t("common.delete", "Delete")}
        variant="destructive"
        onConfirm={confirmDelete}
      />
    </Card>
  );
}

const contactSchema = z.object({
  name: z.string().min(1).max(255),
  relationship: z.string().min(1).max(100),
  phone: z.string().min(1).max(20),
  email: z.string().email().or(z.literal("")).optional(),
});
type ContactForm = z.infer<typeof contactSchema>;

function ContactForm({
  contact,
  onDone,
}: {
  contact?: EmergencyContact;
  onDone: () => void;
}) {
  const { t } = useT();
  const create = useCreateEmergencyContact();
  const update = useUpdateEmergencyContact();
  const saving = create.isPending || update.isPending;

  const {
    control,
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<ContactForm>({
    resolver: zodResolver(contactSchema),
    defaultValues: {
      name: contact?.name ?? "",
      relationship: contact?.relationship ?? "",
      phone: contact?.phone ?? "",
      email: contact?.email ?? "",
    },
  });

  async function onSubmit(values: ContactForm) {
    const payload = {
      name: values.name,
      relationship: values.relationship,
      phone: values.phone,
      email: values.email || null,
    };

    try {
      if (contact) {
        await update.mutateAsync({ publicId: contact.public_id, payload });
      } else {
        await create.mutateAsync(payload);
      }
      toast.success(t("profile.contact_saved", "Emergency contact saved."));
      onDone();
    } catch (err) {
      const fields = fieldErrors(err);
      for (const [name, message] of Object.entries(fields)) {
        setError(name as keyof ContactForm, { type: "server", message });
      }
      if (Object.keys(fields).length === 0) {
        toast.error(t("common.action_failed", "That didn't work. Try again."));
      }
    }
  }

  return (
    <form
      onSubmit={handleSubmit(onSubmit)}
      className="space-y-3 rounded-md border border-border-strong bg-surface-secondary p-3"
    >
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="space-y-2">
          <Label htmlFor="contact_name">
            {t("profile.ec_name", "Emergency Contact Name")}
          </Label>
          <Input
            id="contact_name"
            placeholder={t("profile.placeholder.full_name", "Full name")}
            {...register("name")}
          />
          {errors.name && (
            <p className="text-xs text-destructive">
              {t("validation.required", "This field is required")}
            </p>
          )}
        </div>

        <div className="space-y-2">
          <Label htmlFor="contact_relationship">
            {t("employee.emergency.relationship", "Relationship")}
          </Label>
          <Controller
            control={control}
            name="relationship"
            render={({ field }) => (
              <Select
                value={field.value || undefined}
                onValueChange={field.onChange}
              >
                <SelectTrigger id="contact_relationship">
                  <SelectValue placeholder={t("common.select", "Select")} />
                </SelectTrigger>
                <SelectContent>
                  {RELATIONSHIPS.map((rel) => (
                    <SelectItem key={rel} value={rel}>
                      {t(`relationship.${rel}`, rel)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          />
          {errors.relationship && (
            <p className="text-xs text-destructive">
              {t("validation.required", "This field is required")}
            </p>
          )}
        </div>

        <div className="space-y-2">
          <Label htmlFor="contact_phone">
            {t("profile.ec_phone", "Emergency Contact Phone")}
          </Label>
          <Controller
            control={control}
            name="phone"
            render={({ field }) => (
              <PhoneInput
                id="contact_phone"
                value={field.value ?? ""}
                onChange={field.onChange}
              />
            )}
          />
          {errors.phone && (
            <p className="text-xs text-destructive">
              {t("validation.required", "This field is required")}
            </p>
          )}
        </div>

        <div className="space-y-2">
          <Label htmlFor="contact_email">
            {t("common.email", "Email")} ({t("common.optional", "optional")})
          </Label>
          <Input id="contact_email" type="email" {...register("email")} />
        </div>
      </div>

      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" size="sm" onClick={onDone}>
          {t("common.cancel", "Cancel")}
        </Button>
        <Button type="submit" size="sm" disabled={saving}>
          {saving ? (
            <Loader2 className="mr-1 h-3 w-3 animate-spin" />
          ) : (
            <Save className="mr-1 h-3 w-3" />
          )}
          {t("common.save", "Save")}
        </Button>
      </div>
    </form>
  );
}
