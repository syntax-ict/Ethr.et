"use client";

import { useState } from "react";
import { Loader2, Plus, Trash2, Heart } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import { EmptyState } from "@/components/shared/empty-state";
import { FormField } from "@/components/patterns/FormField";
import { FormErrorSummary } from "@/components/patterns/FormErrorSummary";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { rules, fieldMessage } from "@/lib/forms/rules";
import { z } from "zod";
import { toast } from "sonner";

interface EmergencyContact {
  public_id: string;
  name: string;
  relationship: string;
  phone: string;
}

const contactSchema = z.object({
  name: rules.requiredText(255),
  relationship: rules.requiredText(100),
  // The backend canonicalizes this through `EthiopianPhone::canonicalOrRaw()`,
  // so an unparseable number is stored raw and is then unreachable by any
  // lookup that expects E.164. Catching it here keeps that from happening
  // quietly — an emergency contact nobody can dial is worse than a blank one.
  phone: rules.phone(),
});
type ContactValues = z.infer<typeof contactSchema>;

export function EmergencyContactsTab({ employeeId }: { employeeId: string }) {
  const { t } = useT();
  const queryClient = useQueryClient();
  const [addOpen, setAddOpen] = useState(false);

  const {
    register,
    submit,
    reset,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<ContactValues>({
    schema: contactSchema,
    defaultValues: { name: "", relationship: "", phone: "" },
  });

  const { data, isLoading } = useQuery({
    queryKey: ["employee", employeeId, "emergency-contacts"],
    queryFn: async () => {
      const { data } = await apiClient.get(
        `/employees/${employeeId}/emergency-contacts`,
      );
      return data;
    },
  });

  const addContact = useMutation({
    mutationFn: async (values: ContactValues) => {
      const { data } = await apiClient.post(
        `/employees/${employeeId}/emergency-contacts`,
        values,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ["employee", employeeId, "emergency-contacts"],
      });
      toast.success(t("employee.emergency.added", "Contact added"));
      setAddOpen(false);
      reset();
    },
  });

  const deleteContact = useMutation({
    mutationFn: async (id: string) => {
      await apiClient.delete(
        `/employees/${employeeId}/emergency-contacts/${id}`,
      );
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ["employee", employeeId, "emergency-contacts"],
      });
      toast.success(t("employee.emergency.deleted", "Contact deleted"));
    },
  });

  const contacts: EmergencyContact[] = data?.data ?? [];

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-base">
          {t("employee.emergency.title", "Emergency Contacts")}
        </CardTitle>
        <Button size="sm" onClick={() => setAddOpen(true)}>
          <Plus className="mr-2 h-3 w-3" /> {t("common.add", "Add")}
        </Button>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-20 w-full" />
        ) : contacts.length === 0 ? (
          <EmptyState
            icon={Heart}
            title={t("employee.emergency.empty_title", "No emergency contacts")}
            description={t(
              "employee.emergency.empty_desc",
              "Add people to contact in case of emergency",
            )}
          />
        ) : (
          <div className="space-y-2">
            {contacts.map((c) => (
              <div
                key={c.public_id}
                className="flex items-center justify-between rounded-lg border p-3"
              >
                <div className="flex items-center gap-3">
                  <Heart className="h-4 w-4 text-muted-foreground" />
                  <div>
                    <p className="text-sm font-medium">{c.name}</p>
                    <p className="text-xs text-muted-foreground">
                      {c.relationship} · {c.phone}
                    </p>
                  </div>
                </div>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => deleteContact.mutate(c.public_id)}
                >
                  <Trash2 className="h-4 w-4 text-destructive" />
                </Button>
              </div>
            ))}
          </div>
        )}
      </CardContent>

      <Dialog open={addOpen} onOpenChange={setAddOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {t("employee.emergency.add_title", "Add Emergency Contact")}
            </DialogTitle>
          </DialogHeader>
          <form
            onSubmit={submit(
              (values) => addContact.mutateAsync(values),
              t("employee.emergency.add_failed", "Failed to add contact"),
            )}
            className="space-y-4"
            noValidate
          >
            <FormErrorSummary message={rootError} />

            <FormField
              id="contact_name"
              label={t("common.name", "Name")}
              required
              error={fieldMessage(t, errors.name?.message)}
            >
              <Input {...register("name")} className="mt-1" />
            </FormField>

            <FormField
              id="contact_relationship"
              label={t("employee.emergency.relationship", "Relationship")}
              required
              error={fieldMessage(t, errors.relationship?.message)}
            >
              <Input
                {...register("relationship")}
                placeholder="Spouse, Parent..."
                className="mt-1"
              />
            </FormField>

            <FormField
              id="contact_phone"
              label={t("common.phone", "Phone")}
              required
              error={fieldMessage(t, errors.phone?.message)}
            >
              <Input
                {...register("phone")}
                type="tel"
                inputMode="tel"
                placeholder="0911223344"
                className="mt-1"
              />
            </FormField>

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setAddOpen(false)}
              >
                {t("common.cancel", "Cancel")}
              </Button>
              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                {t("common.add", "Add")}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </Card>
  );
}
