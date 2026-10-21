"use client";

import { useId, useState } from "react";
import { MessageSquareText, Send, Save, Trash2 } from "lucide-react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { RoleGate } from "@/components/shared/role-gate";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  useRemoveSmsGateway,
  useSaveSmsGateway,
  useSmsDelivery,
  useTestSmsGateway,
  useUpdateSmsSettings,
  type SaveSmsGatewayPayload,
  type SmsDeliveryState,
  type SmsGatewayState,
  type SmsSlot,
} from "@/features/admin/sms";
import { useT } from "@/lib/i18n/useT";

/**
 * SMS delivery for the whole platform: the gateway a provider's documentation
 * describes, a test send that proves it, and only then the switch.
 *
 * Nothing here is provider-specific. Ethio Telecom publishes no HTTP SMS API
 * (it offers SMPP), so the form is a neutral "custom HTTP gateway": the
 * operator copies the provider's endpoint, authentication and field names from
 * its documentation, and the test button is what shows it works.
 */
export default function SmsDeliveryPage() {
  const { t } = useT();
  const query = useSmsDelivery();

  return (
    <RoleGate minRole="super_admin">
      <div className="space-y-6">
        <QueryBoundary
          query={query}
          loading={<Skeleton className="h-96 w-full" />}
          isEmpty={() => false}
        >
          {(state) => (
            <div className="space-y-6">
              <StatusCard state={state} />
              <GatewayCard slot="primary" state={state} />
              {(state.route_prefix !== null || state.secondary !== null) && (
                <GatewayCard slot="secondary" state={state} />
              )}
              <p className="text-xs text-muted-foreground">
                {t(
                  "admin_sms.smpp_note",
                  "Only HTTP gateways are supported. SMPP needs a permanent connection, which this hosting cannot keep open.",
                )}
              </p>
            </div>
          )}
        </QueryBoundary>
      </div>
    </RoleGate>
  );
}

function StatusCard({ state }: { state: SmsDeliveryState }) {
  const { t } = useT();
  const update = useUpdateSmsSettings();
  const capId = useId();
  const [cap, setCap] = useState(String(state.daily_cap));
  const primaryVerified = state.primary?.verified === true;

  function save(
    payload: Partial<
      Pick<SmsDeliveryState, "enabled" | "route_prefix" | "daily_cap">
    >,
  ) {
    update.mutate(payload, {
      onSuccess: () => toast.success(t("admin_sms.saved", "Saved")),
      onError: (err: unknown) =>
        toast.error(
          (err as { response?: { data?: { detail?: string } } }).response?.data
            ?.detail ?? t("admin_sms.save_failed", "Could not save"),
        ),
    });
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <MessageSquareText className="h-5 w-5" />
          {t("admin_sms.status_title", "Status")}
          {state.available ? (
            <Badge variant="success">{t("admin_sms.state_on", "On")}</Badge>
          ) : (
            <Badge variant="neutral">{t("admin_sms.state_off", "Off")}</Badge>
          )}
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-5">
        {state.source === "env" && (
          <p className="rounded-lg bg-info-soft p-3 text-sm text-info-on-soft">
            {t(
              "admin_sms.source_env",
              "SMS is currently configured in the server's .env file. Saving a gateway here takes over from it.",
            )}
          </p>
        )}

        <div className="flex items-center justify-between gap-4">
          <div>
            <p className="text-sm font-medium text-foreground">
              {t("admin_sms.switch_label", "Send SMS")}
            </p>
            <p className="text-xs text-muted-foreground">
              {primaryVerified
                ? t(
                    "admin_sms.switch_help",
                    "When off, sign-in codes go by email only.",
                  )
                : t(
                    "admin_sms.switch_locked",
                    "Send a successful test from the primary gateway first.",
                  )}
            </p>
          </div>
          <Switch
            checked={state.enabled}
            disabled={(!primaryVerified && !state.enabled) || update.isPending}
            onCheckedChange={(enabled) => save({ enabled })}
            aria-label={t("admin_sms.switch_label", "Send SMS")}
          />
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-2">
            <Label>{t("admin_sms.route_label", "Second gateway for")}</Label>
            <Select
              value={state.route_prefix ?? "none"}
              onValueChange={(value) =>
                save({
                  route_prefix: value === "none" ? null : (value as "7" | "9"),
                })
              }
            >
              <SelectTrigger
                aria-label={t("admin_sms.route_label", "Second gateway for")}
              >
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="none">
                  {t("admin_sms.route_none", "No second gateway")}
                </SelectItem>
                <SelectItem value="7">
                  {t("admin_sms.route_7", "Safaricom numbers (+2517…)")}
                </SelectItem>
                <SelectItem value="9">
                  {t("admin_sms.route_9", "Ethio Telecom numbers (+2519…)")}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-2">
            <Label htmlFor={capId}>
              {t("admin_sms.cap_label", "Daily limit (whole platform)")}
            </Label>
            <div className="flex gap-2">
              <Input
                id={capId}
                type="number"
                min={0}
                value={cap}
                onChange={(e) => setCap(e.target.value)}
              />
              <Button
                variant="outline"
                disabled={update.isPending || cap === String(state.daily_cap)}
                onClick={() => save({ daily_cap: Number(cap) })}
              >
                {t("admin_sms.save", "Save")}
              </Button>
            </div>
            <p className="text-xs text-muted-foreground">
              {t("admin_sms.sent_today", ":sent sent today", {
                sent: state.sent_today,
              })}
            </p>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

type GatewayForm = Required<{
  [K in keyof SaveSmsGatewayPayload]: string;
}>;

function seed(gateway: SmsGatewayState | null): GatewayForm {
  return {
    label: gateway?.label ?? "",
    endpoint: gateway?.endpoint ?? "",
    method: gateway?.method ?? "json",
    auth_type: gateway?.auth_type ?? "header",
    auth_header: gateway?.auth_header ?? "",
    auth_username: gateway?.auth_username ?? "",
    auth_secret: "",
    username_field: gateway?.username_field ?? "",
    password_field: gateway?.password_field ?? "",
    recipient_field: gateway?.recipient_field ?? "to",
    message_field: gateway?.message_field ?? "message",
    sender_field: gateway?.sender_field ?? "",
    sender_id: gateway?.sender_id ?? "",
    number_format: gateway?.number_format ?? "international",
    success_json_path: gateway?.success_json_path ?? "",
    success_json_value: gateway?.success_json_value ?? "",
    success_body_contains: gateway?.success_body_contains ?? "",
    timeout_seconds: String(gateway?.timeout_seconds ?? 10),
  };
}

function GatewayCard({
  slot,
  state,
}: {
  slot: SmsSlot;
  state: SmsDeliveryState;
}) {
  const { t } = useT();
  const gateway = state[slot];
  const save = useSaveSmsGateway();
  const remove = useRemoveSmsGateway();
  const [form, setForm] = useState<GatewayForm>(() => seed(gateway));
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const set = (key: keyof GatewayForm) => (value: string) =>
    setForm((prev) => ({ ...prev, [key]: value }));

  function submit() {
    const payload: Record<string, string | number | null> = {};
    for (const [key, value] of Object.entries(form)) {
      payload[key] = value === "" ? null : value;
    }
    payload.timeout_seconds = Number(form.timeout_seconds) || 10;

    save.mutate(
      { slot, payload: payload as SaveSmsGatewayPayload },
      {
        onSuccess: () => {
          setErrors({});
          setForm((prev) => ({ ...prev, auth_secret: "" }));
          toast.success(t("admin_sms.saved", "Saved"));
        },
        onError: (err: unknown) => {
          const data = (
            err as {
              response?: { data?: { errors?: Record<string, string[]> } };
            }
          ).response?.data;
          setErrors(data?.errors ?? {});
          toast.error(t("admin_sms.save_failed", "Could not save"));
        },
      },
    );
  }

  const title =
    slot === "primary"
      ? t("admin_sms.primary_title", "Primary gateway: all numbers")
      : t("admin_sms.secondary_title", "Second gateway: routed numbers");

  const needsUser = form.auth_type === "fields" || form.auth_type === "basic";

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex flex-wrap items-center gap-2">
          {title}
          {gateway &&
            (gateway.verified ? (
              <Badge variant="success">
                {t("admin_sms.verified", "Verified")}
              </Badge>
            ) : (
              <Badge variant="warning">
                {t("admin_sms.not_verified", "Not tested")}
              </Badge>
            ))}
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-6">
        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label={t("admin_sms.label", "Name")}
            value={form.label}
            onChange={set("label")}
            error={errors.label}
          />
          <Field
            label={t("admin_sms.endpoint", "Endpoint URL")}
            value={form.endpoint}
            onChange={set("endpoint")}
            placeholder="https://"
            error={errors.endpoint}
          />
          <Choice
            label={t("admin_sms.method", "Request")}
            value={form.method}
            onChange={set("method")}
            options={[
              ["json", t("admin_sms.method_json", "POST, JSON body")],
              ["form", t("admin_sms.method_form", "POST, form body")],
              ["get", t("admin_sms.method_get", "GET, query string")],
            ]}
          />
          <Choice
            label={t("admin_sms.auth_type", "Authentication")}
            value={form.auth_type}
            onChange={set("auth_type")}
            options={[
              ["none", t("admin_sms.auth_none", "None")],
              ["header", t("admin_sms.auth_header", "API key in a header")],
              ["bearer", t("admin_sms.auth_bearer", "Bearer token")],
              [
                "fields",
                t("admin_sms.auth_fields", "Username and password fields"),
              ],
              ["basic", t("admin_sms.auth_basic", "HTTP basic")],
            ]}
          />
          {form.auth_type === "header" && (
            <Field
              label={t("admin_sms.auth_header_name", "Header name")}
              value={form.auth_header}
              onChange={set("auth_header")}
              placeholder="KEY"
              error={errors.auth_header}
            />
          )}
          {needsUser && (
            <Field
              label={t("admin_sms.auth_username", "Username")}
              value={form.auth_username}
              onChange={set("auth_username")}
              error={errors.auth_username}
            />
          )}
          {form.auth_type === "fields" && (
            <>
              <Field
                label={t("admin_sms.username_field", "Username field name")}
                value={form.username_field}
                onChange={set("username_field")}
                error={errors.username_field}
              />
              <Field
                label={t("admin_sms.password_field", "Password field name")}
                value={form.password_field}
                onChange={set("password_field")}
                error={errors.password_field}
              />
            </>
          )}
          {form.auth_type !== "none" && (
            <Field
              label={t("admin_sms.auth_secret", "API key, token or password")}
              value={form.auth_secret}
              onChange={set("auth_secret")}
              type="password"
              autoComplete="new-password"
              placeholder={
                gateway?.secret_set
                  ? t("admin_sms.secret_set", "Set. Leave empty to keep it")
                  : t("admin_sms.secret_not_set", "Not set")
              }
              error={errors.auth_secret}
            />
          )}
          <Field
            label={t("admin_sms.recipient_field", "Recipient field name")}
            value={form.recipient_field}
            onChange={set("recipient_field")}
            error={errors.recipient_field}
          />
          <Field
            label={t("admin_sms.message_field", "Message field name")}
            value={form.message_field}
            onChange={set("message_field")}
            error={errors.message_field}
          />
          <Field
            label={t("admin_sms.sender_field", "Sender ID field name")}
            value={form.sender_field}
            onChange={set("sender_field")}
            error={errors.sender_field}
          />
          <Field
            label={t("admin_sms.sender_id", "Sender ID")}
            value={form.sender_id}
            onChange={set("sender_id")}
            placeholder="ETHR"
            error={errors.sender_id}
          />
          <Choice
            label={t("admin_sms.number_format", "Number format")}
            value={form.number_format}
            onChange={set("number_format")}
            options={[
              ["international", "2519…"],
              ["e164", "+2519…"],
              ["local", "09…"],
            ]}
          />
          <Field
            label={t("admin_sms.timeout", "Timeout (seconds)")}
            value={form.timeout_seconds}
            onChange={set("timeout_seconds")}
            type="number"
            error={errors.timeout_seconds}
          />
        </div>

        <div className="space-y-2">
          <p className="text-sm font-medium text-foreground">
            {t("admin_sms.success_title", "Counts as sent when")}
          </p>
          <p className="text-xs text-muted-foreground">
            {t(
              "admin_sms.success_help",
              "The answer is HTTP 2xx and, if set, the JSON value or text below appears. Many gateways answer 200 to a rejected message.",
            )}
          </p>
          <div className="grid gap-4 sm:grid-cols-3">
            <Field
              label={t("admin_sms.success_json_path", "JSON path")}
              value={form.success_json_path}
              onChange={set("success_json_path")}
              placeholder="status"
              error={errors.success_json_path}
            />
            <Field
              label={t("admin_sms.success_json_value", "Equals")}
              value={form.success_json_value}
              onChange={set("success_json_value")}
              placeholder="success"
              error={errors.success_json_value}
            />
            <Field
              label={t(
                "admin_sms.success_body_contains",
                "Or the body contains",
              )}
              value={form.success_body_contains}
              onChange={set("success_body_contains")}
              error={errors.success_body_contains}
            />
          </div>
        </div>

        <div className="flex flex-wrap gap-2">
          <Button onClick={submit} disabled={save.isPending}>
            <Save className="mr-2 h-4 w-4" />
            {t("admin_sms.save_gateway", "Save gateway")}
          </Button>
          {gateway && (
            <Button
              variant="outline"
              disabled={remove.isPending}
              onClick={() =>
                remove.mutate(slot, {
                  onSuccess: () => {
                    setForm(seed(null));
                    toast.success(t("admin_sms.removed", "Gateway removed"));
                  },
                })
              }
            >
              <Trash2 className="mr-2 h-4 w-4" />
              {t("admin_sms.remove", "Remove")}
            </Button>
          )}
        </div>

        {gateway && <TestPanel slot={slot} gateway={gateway} />}
      </CardContent>
    </Card>
  );
}

function TestPanel({
  slot,
  gateway,
}: {
  slot: SmsSlot;
  gateway: SmsGatewayState;
}) {
  const { t } = useT();
  const test = useTestSmsGateway();
  const phoneId = useId();
  const [phone, setPhone] = useState("");
  const [error, setError] = useState("");

  function send() {
    setError("");
    test.mutate(
      { slot, phone },
      {
        onError: (err: unknown) => {
          const data = (
            err as {
              response?: {
                status?: number;
                data?: { errors?: Record<string, string[]>; detail?: string };
              };
            }
          ).response;
          setError(
            data?.data?.errors?.phone?.[0] ??
              (data?.status === 429
                ? t(
                    "admin_sms.test_throttled",
                    "Too many tests. Try again later.",
                  )
                : (data?.data?.detail ??
                  t("admin_sms.test_failed", "The test could not be sent"))),
          );
        },
      },
    );
  }

  return (
    <div className="space-y-3 rounded-lg border border-border p-4">
      <Label htmlFor={phoneId}>
        {t("admin_sms.test_label", "Send a test SMS to")}
      </Label>
      <div className="flex flex-col gap-2 sm:flex-row">
        <Input
          id={phoneId}
          value={phone}
          onChange={(e) => setPhone(e.target.value)}
          placeholder="09XXXXXXXX / 07XXXXXXXX"
          inputMode="tel"
        />
        <Button
          variant="outline"
          disabled={test.isPending || phone.trim() === ""}
          onClick={send}
        >
          <Send className="mr-2 h-4 w-4" />
          {t("admin_sms.test_send", "Send test")}
        </Button>
      </div>
      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}

      {gateway.last_test_at && (
        <div className="space-y-1 text-sm">
          <p className="flex flex-wrap items-center gap-2">
            {gateway.last_test_ok ? (
              <Badge variant="success">
                {t("admin_sms.test_ok", "Delivered to the gateway")}
              </Badge>
            ) : (
              <Badge variant="warning">
                {t("admin_sms.test_not_ok", "Not accepted")}
              </Badge>
            )}
            <span className="text-muted-foreground">
              {t("admin_sms.test_status", "HTTP :status", {
                status: gateway.last_test_status ?? "—",
              })}
              {" · "}
              {new Date(gateway.last_test_at).toLocaleString()}
            </span>
          </p>
          {gateway.last_test_response && (
            <pre className="max-h-40 overflow-auto whitespace-pre-wrap break-all rounded-md bg-muted p-2 font-mono text-xs text-foreground">
              {gateway.last_test_response}
            </pre>
          )}
        </div>
      )}
    </div>
  );
}

function Field({
  label,
  value,
  onChange,
  error,
  ...rest
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  error?: string[];
} & Omit<React.InputHTMLAttributes<HTMLInputElement>, "onChange" | "value">) {
  const id = useId();
  return (
    <div className="space-y-2">
      <Label htmlFor={id}>{label}</Label>
      <Input
        id={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        aria-invalid={error ? true : undefined}
        {...rest}
      />
      {error?.[0] && <p className="text-xs text-destructive">{error[0]}</p>}
    </div>
  );
}

function Choice({
  label,
  value,
  onChange,
  options,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  options: Array<[string, string]>;
}) {
  return (
    <div className="space-y-2">
      <Label>{label}</Label>
      <Select value={value} onValueChange={onChange}>
        <SelectTrigger aria-label={label}>
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          {options.map(([key, text]) => (
            <SelectItem key={key} value={key}>
              {text}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  );
}
