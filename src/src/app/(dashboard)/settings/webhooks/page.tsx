"use client";

import { useState } from "react";
import {
  Webhook,
  Plus,
  Trash2,
  Copy,
  Send,
  Loader2,
  History,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
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
import { RoleGate } from "@/components/shared/role-gate";
import { WebhookDeliveriesDialog } from "@/features/webhooks/webhook-deliveries-dialog";
import { FormField } from "@/components/patterns/FormField";
import { FormErrorSummary } from "@/components/patterns/FormErrorSummary";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { rules, fieldMessage } from "@/lib/forms/rules";
import { statusBadgeClass } from "@/lib/utils/status-colors";
import { toast } from "sonner";
import { z } from "zod";

interface WebhookEntry {
  public_id: string;
  url: string;
  events: string[];
  is_active: boolean;
  failure_count: number;
  last_triggered_at: string | null;
  created_at: string;
}

const ALL_EVENTS = [
  "employee.created",
  "employee.updated",
  "employee.transitioned",
  "attendance.recorded",
  "attendance.corrected",
  "leave.requested",
  "leave.approved",
  "leave.rejected",
  "payroll.processed",
  "payroll.approved",
  "device.online",
  "device.offline",
];

const webhookSchema = z.object({
  url: rules.url(),
  // A webhook subscribed to nothing is silently inert: it is created, listed as
  // Active, and never fires. The server rejects an empty list; without this the
  // user only found out via a toast that named no field.
  events: z.array(z.string()).min(1, "webhooks_page.events_required"),
});
type WebhookValues = z.infer<typeof webhookSchema>;

export default function WebhooksPage() {
  const { t } = useT();
  const { formatDateTime } = useDateFormatters();
  const queryClient = useQueryClient();
  const [createOpen, setCreateOpen] = useState(false);
  const [newSecret, setNewSecret] = useState<string | null>(null);
  const [deliveriesFor, setDeliveriesFor] = useState<WebhookEntry | null>(null);

  const {
    register,
    submit,
    reset,
    watch,
    setValue,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<WebhookValues>({
    schema: webhookSchema,
    defaultValues: { url: "", events: [] },
  });

  const selectedEvents = watch("events");

  const { data, isLoading } = useQuery({
    queryKey: ["webhooks"],
    queryFn: async () => {
      const { data } = await apiClient.get("/webhooks");
      return data;
    },
  });

  // No `onError` toast: a failed create is now reported inside the dialog —
  // inline on the offending field for a 422, in the summary otherwise. A toast
  // would duplicate it and then vanish, which is what previously left the user
  // with a dialog full of rejected input and no explanation.
  const createWebhook = useMutation({
    mutationFn: async (values: WebhookValues) => {
      const { data } = await apiClient.post("/webhooks", values);
      return data;
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ["webhooks"] });
      setNewSecret(data.secret);
      setCreateOpen(false);
      reset();
      toast.success(t("webhooks_page.created"));
    },
  });

  const deleteWebhook = useMutation({
    mutationFn: async (id: string) => {
      await apiClient.delete(`/webhooks/${id}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["webhooks"] });
      toast.success(t("webhooks_page.deleted"));
    },
  });

  const testWebhook = useMutation({
    mutationFn: async (id: string) => {
      const { data } = await apiClient.post(`/webhooks/${id}/test`);
      return data;
    },
    onSuccess: () => toast.success(t("webhooks_page.test_dispatched")),
    onError: () => toast.error(t("webhooks_page.test_failed")),
  });

  const webhooks: WebhookEntry[] = data?.webhooks ?? [];

  function toggleEvent(event: string) {
    const next = selectedEvents.includes(event)
      ? selectedEvents.filter((e) => e !== event)
      : [...selectedEvents, event];
    // `shouldValidate` so ticking the first box clears the "choose at least
    // one" error immediately, rather than leaving it up until the next submit.
    setValue("events", next, { shouldValidate: true, shouldDirty: true });
  }

  function copySecret() {
    if (newSecret) {
      navigator.clipboard.writeText(newSecret);
      toast.success(t("webhooks_page.secret_copied"));
    }
  }

  return (
    <RoleGate minRole="tenant_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("webhooks_page.title")}
          description={t("webhooks_page.description")}
          actions={
            <Button onClick={() => setCreateOpen(true)}>
              <Plus className="mr-2 h-4 w-4" /> {t("webhooks_page.add_webhook")}
            </Button>
          }
        />

        {newSecret && (
          <Card className="border-2 border-status-warning/40 bg-status-warning/5">
            <CardContent className="p-4">
              <p className="mb-2 text-sm font-semibold text-foreground">
                {t("webhooks_page.save_secret_hint")}
              </p>
              <div className="flex items-center gap-2">
                <code className="flex-1 rounded bg-background px-3 py-2 text-xs font-mono break-all">
                  {newSecret}
                </code>
                <Button size="sm" variant="outline" onClick={copySecret}>
                  <Copy className="h-4 w-4" />
                </Button>
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() => setNewSecret(null)}
                >
                  {t("api_keys_page.dismiss")}
                </Button>
              </div>
            </CardContent>
          </Card>
        )}

        {isLoading ? (
          <div className="space-y-3">
            {Array.from({ length: 3 }).map((_, i) => (
              <Skeleton key={i} className="h-24" />
            ))}
          </div>
        ) : webhooks.length === 0 ? (
          <EmptyState
            icon={Webhook}
            title={t("webhooks_page.no_webhooks")}
            description={t("webhooks_page.no_webhooks_desc")}
          />
        ) : (
          <div className="space-y-3">
            {webhooks.map((w) => (
              <Card key={w.public_id}>
                <CardContent className="p-4">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2">
                        <code className="text-sm font-mono text-foreground truncate">
                          {w.url}
                        </code>
                        <Badge
                          variant="outline"
                          className={
                            w.is_active
                              ? statusBadgeClass("active")
                              : statusBadgeClass("offline")
                          }
                        >
                          {w.is_active
                            ? t("webhooks_page.active")
                            : t("roles_page.inactive")}
                        </Badge>
                        {w.failure_count > 0 && (
                          <Badge
                            variant="outline"
                            className={statusBadgeClass("error")}
                          >
                            {w.failure_count} {t("webhooks_page.failures")}
                          </Badge>
                        )}
                      </div>
                      <div className="mt-2 flex flex-wrap gap-1">
                        {w.events.map((e) => (
                          <Badge
                            key={e}
                            variant="outline"
                            className="text-[10px]"
                          >
                            {e}
                          </Badge>
                        ))}
                      </div>
                      {w.last_triggered_at && (
                        <p className="mt-2 text-xs text-muted-foreground">
                          {t("webhooks_page.last_triggered")}:{" "}
                          {formatDateTime(w.last_triggered_at)}
                        </p>
                      )}
                    </div>
                    <div className="flex gap-1">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setDeliveriesFor(w)}
                        title={t("webhooks_page.deliveries_title")}
                      >
                        <History className="h-4 w-4" />
                        <span className="sr-only">
                          {t("webhooks_page.deliveries_title")}
                        </span>
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => testWebhook.mutate(w.public_id)}
                        disabled={testWebhook.isPending}
                        title={t("webhooks_page.send_test")}
                      >
                        <Send className="h-4 w-4" />
                        <span className="sr-only">
                          {t("webhooks_page.send_test")}
                        </span>
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => deleteWebhook.mutate(w.public_id)}
                        title={t("common.delete")}
                      >
                        <Trash2 className="h-4 w-4 text-destructive" />
                        <span className="sr-only">{t("common.delete")}</span>
                      </Button>
                    </div>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}

        <WebhookDeliveriesDialog
          webhookId={deliveriesFor?.public_id ?? null}
          webhookUrl={deliveriesFor?.url}
          open={!!deliveriesFor}
          onClose={() => setDeliveriesFor(null)}
        />

        <Dialog open={createOpen} onOpenChange={setCreateOpen}>
          <DialogContent className="max-w-md">
            <DialogHeader>
              <DialogTitle>{t("webhooks_page.create_webhook")}</DialogTitle>
            </DialogHeader>
            <form
              onSubmit={submit(
                (values) => createWebhook.mutateAsync(values),
                t("webhooks_page.create_failed"),
              )}
              className="space-y-4"
              noValidate
            >
              <FormErrorSummary message={rootError} />

              <FormField
                id="webhook-url"
                label={t("webhooks_page.url")}
                required
                error={fieldMessage(t, errors.url?.message)}
              >
                <Input
                  {...register("url")}
                  type="url"
                  placeholder="https://example.com/webhook"
                  className="mt-1"
                />
              </FormField>

              <FormField
                id="webhook-events"
                label={t("webhooks_page.events")}
                required
                error={fieldMessage(t, errors.events?.message)}
              >
                {(control) => (
                  // `role="group"` rather than a cloned control: the checkboxes
                  // are the input here, and there is no single element for the
                  // label to point at.
                  <div
                    {...control}
                    role="group"
                    aria-label={t("webhooks_page.events")}
                    className="mt-2 max-h-60 space-y-1 overflow-y-auto rounded-lg border p-2"
                  >
                    {ALL_EVENTS.map((event) => (
                      <label
                        key={event}
                        className="flex cursor-pointer items-center gap-2 rounded p-1 hover:bg-muted/50"
                      >
                        <input
                          type="checkbox"
                          checked={selectedEvents.includes(event)}
                          onChange={() => toggleEvent(event)}
                          className="h-4 w-4 rounded"
                        />
                        <span className="font-mono text-sm">{event}</span>
                      </label>
                    ))}
                  </div>
                )}
              </FormField>

              <DialogFooter>
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setCreateOpen(false)}
                >
                  {t("common.cancel")}
                </Button>
                {/* No longer disabled on an empty event list: a button that is
                    dead with no message next to it is indistinguishable from a
                    broken one. Submitting now says why. */}
                <Button type="submit" disabled={isSubmitting}>
                  {isSubmitting && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  {t("api_keys_page.create")}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>
    </RoleGate>
  );
}
