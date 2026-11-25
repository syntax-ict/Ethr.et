"use client";

import { useEffect, useState } from "react";
import { Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import type { Device } from "@/features/devices/api";
import {
  buildDevicePayload,
  canAcceptCommands,
  canSendToEthr,
  connectionModeFor,
  EMPTY_DEVICE_FORM,
  isIpAdapter,
  MIN_SYNC_INTERVAL_MINUTES,
  SIMULATOR_IN_FORM,
  type ConnectionMode,
  type DeviceFormData,
  type DevicePayload,
} from "@/features/devices/payload";
import { useT } from "@/lib/i18n/useT";

/**
 * The one form for registering and for editing a biometric device, used by the
 * device list (Register and Edit) and by the device's own page (Edit). It is
 * built from four sections, each exported so a guided setup can place them:
 * identity, connection (by how the terminal reaches ETHR), people and
 * commands, and advanced.
 */

type Branch = { public_id: string; name: string };

/** The state every section reads and writes. */
export interface DeviceFormState {
  form: DeviceFormData;
  patch: (changes: Partial<DeviceFormData>) => void;
  mode: "create" | "edit";
  connection: ConnectionMode;
  /** The stored device, on edit. */
  device?: Device;
}

/** What an edit starts from. The stored address and credentials are never returned, so they start blank. */
export function deviceToForm(device: Device): DeviceFormData {
  return {
    ...EMPTY_DEVICE_FORM,
    name: device.name,
    adapter_type: device.adapter_type,
    serial_number: device.serial_number ?? "",
    branch_public_id: device.branch_public_id ?? "",
    // Blank, not "80": a pre-filled default would silently replace the stored
    // port whenever only the IP is changed.
    port: "",
    auto_sync: device.auto_sync,
    sends_to_ethr:
      canSendToEthr(device.adapter_type) && device.receives_only === true,
    location_description: device.location_description ?? "",
    commands_enabled: device.commands_enabled,
    sync_interval_minutes: String(
      Math.max(MIN_SYNC_INTERVAL_MINUTES, device.sync_interval_minutes),
    ),
    timezone: device.timezone ?? "",
    webhook_ip_allowlist: (device.webhook_ip_allowlist ?? []).join("\n"),
  };
}

function useFormState(
  mode: "create" | "edit",
  device: Device | undefined,
): DeviceFormState {
  const [form, setForm] = useState<DeviceFormData>(() =>
    device ? deviceToForm(device) : { ...EMPTY_DEVICE_FORM },
  );
  const state: DeviceFormState = {
    form,
    patch: (changes) => setForm((p) => ({ ...p, ...changes })),
    mode,
    connection: connectionModeFor(form),
    device,
  };
  return state;
}

function Section({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}) {
  return (
    <section className="space-y-4 rounded-lg border p-4">
      <h3 className="text-xs font-medium text-muted-foreground uppercase">
        {title}
      </h3>
      {children}
    </section>
  );
}

function SwitchRow({
  label,
  hint,
  checked,
  onChange,
}: {
  label: string;
  hint: string;
  checked: boolean;
  onChange: (value: boolean) => void;
}) {
  return (
    <div className="flex items-center justify-between gap-3 rounded-lg border p-3">
      <div>
        <p className="text-sm font-medium">{label}</p>
        <p className="text-xs text-muted-foreground">{hint}</p>
      </div>
      <Switch aria-label={label} checked={checked} onCheckedChange={onChange} />
    </div>
  );
}

export function DeviceIdentitySection({
  state,
  branches,
}: {
  state: DeviceFormState;
  branches: Branch[];
}) {
  const { t } = useT();
  const { form, patch, mode } = state;
  const branchRequired = mode === "create";

  return (
    <Section title={t("device_form.section_identity")}>
      <div>
        <Label htmlFor="device-name">{t("devices_page.device_name")}</Label>
        <Input
          id="device-name"
          value={form.name}
          onChange={(e) => patch({ name: e.target.value })}
          required
          placeholder={t("devices_page.device_name_placeholder")}
          className="mt-1"
        />
      </div>
      <div>
        <Label htmlFor="location-description">
          {t("device_detail_page.location_description")}
        </Label>
        <Input
          id="location-description"
          value={form.location_description}
          onChange={(e) => patch({ location_description: e.target.value })}
          placeholder={t("device_detail_page.location_placeholder")}
          className="mt-1"
        />
      </div>
      <div className="grid grid-cols-2 gap-4">
        <div>
          <Label htmlFor="adapter-type">{t("devices_page.adapter_type")}</Label>
          <Select
            value={form.adapter_type}
            onValueChange={(v) => patch({ adapter_type: v })}
          >
            <SelectTrigger id="adapter-type" className="mt-1">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="hikvision">Hikvision</SelectItem>
              <SelectItem value="zkteco">ZKTeco</SelectItem>
              <SelectItem value="zkteco_adms">
                {t("devices_page.zkteco_adms")}
              </SelectItem>
              <SelectItem value="suprema">Suprema</SelectItem>
              {(SIMULATOR_IN_FORM || form.adapter_type === "mock") && (
                <SelectItem value="mock">
                  {t("devices_page.mock_simulator")}
                </SelectItem>
              )}
            </SelectContent>
          </Select>
        </div>
        <div>
          <Label htmlFor="serial-number">
            {t("devices_page.serial_number")}
          </Label>
          <Input
            id="serial-number"
            value={form.serial_number}
            onChange={(e) => patch({ serial_number: e.target.value })}
            placeholder={
              form.adapter_type === "zkteco_adms"
                ? t("devices_page.serial_required")
                : t("leave_page.optional")
            }
            className="mt-1"
          />
        </div>
      </div>
      {branches.length > 0 && (
        <div>
          <Label htmlFor="branch">
            {branchRequired
              ? t("attendance.kiosks_page.branch")
              : t("attendance.kiosks_page.branch").replace(" *", "")}
          </Label>
          <Select
            value={form.branch_public_id}
            onValueChange={(v) => patch({ branch_public_id: v })}
          >
            <SelectTrigger
              id="branch"
              className="mt-1"
              aria-required={branchRequired || undefined}
            >
              <SelectValue
                placeholder={t("attendance.kiosks_page.select_branch")}
              />
            </SelectTrigger>
            <SelectContent>
              {branches.map((b) => (
                <SelectItem key={b.public_id} value={b.public_id}>
                  {b.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )}
    </Section>
  );
}

/**
 * Whether the address fields must be filled: always on register, and on edit
 * when the stored connection no longer fits (another vendor, or a terminal
 * that sent to ETHR and now has to be called).
 */
export function addressIsRequired(state: DeviceFormState): boolean {
  const { mode, device, form } = state;
  if (mode === "create" || !device) return true;
  if (device.adapter_type !== form.adapter_type) return true;
  return (
    canSendToEthr(device.adapter_type) &&
    device.receives_only === true &&
    !form.sends_to_ethr
  );
}

export function DeviceConnectionSection({ state }: { state: DeviceFormState }) {
  const { t } = useT();
  const { form, patch, connection } = state;
  const addressRequired = addressIsRequired(state);

  return (
    <Section title={t("device_form.section_connection")}>
      {connection === "adms" && (
        <p className="text-sm text-muted-foreground">
          {t("devices_page.adms_hint")}
        </p>
      )}

      {form.adapter_type === "mock" && (
        <div className="rounded-lg border border-warning-edge bg-warning-soft p-3 text-sm text-warning-on-soft">
          {t("devices_page.mock_hint")}
        </div>
      )}

      {canSendToEthr(form.adapter_type) && (
        <SwitchRow
          label={t(
            "devices_page.sends_to_ethr",
            "The terminal is on our office network",
          )}
          hint={t(
            "devices_page.sends_to_ethr_hint",
            "It sends each punch to ETHR, so no address is needed. After registering, the device page shows what to enter on the terminal. ETHR cannot read its enrolled users, set its clock or fetch punches it missed.",
          )}
          checked={form.sends_to_ethr}
          onChange={(v) => patch({ sends_to_ethr: v })}
        />
      )}

      {isIpAdapter(form.adapter_type) && connection === "pull" && (
        <div className="space-y-4">
          <p className="text-sm text-muted-foreground">
            {t(
              "devices_page.public_address_hint",
              "ETHR connects to this address over the internet, so it must be a public one. An office-network address such as 192.168.1.100 is refused.",
            )}
          </p>
          {!addressRequired && (
            <p className="text-sm text-muted-foreground">
              {t("devices_page.keep_connection_hint")}
            </p>
          )}
          <div className="grid grid-cols-3 gap-3">
            <div className="col-span-2">
              <Label htmlFor="ip-address">{t("devices_page.ip_address")}</Label>
              <Input
                id="ip-address"
                value={form.ip}
                onChange={(e) => patch({ ip: e.target.value })}
                required={addressRequired}
                placeholder="203.0.113.10"
                className="mt-1"
              />
            </div>
            <div>
              <Label htmlFor="port">{t("devices_page.port")}</Label>
              <Input
                id="port"
                value={form.port}
                onChange={(e) => patch({ port: e.target.value })}
                required={addressRequired || form.ip.trim() !== ""}
                type="number"
                min={1}
                max={65535}
                className="mt-1"
              />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="username">{t("devices_page.username")}</Label>
              <Input
                id="username"
                value={form.username}
                onChange={(e) => patch({ username: e.target.value })}
                placeholder={t("leave_page.optional")}
                className="mt-1"
              />
            </div>
            <div>
              <Label htmlFor="password">{t("auth.password")}</Label>
              <Input
                id="password"
                type="password"
                value={form.password}
                onChange={(e) => patch({ password: e.target.value })}
                placeholder={t("leave_page.optional")}
                className="mt-1"
              />
            </div>
          </div>
          {form.adapter_type === "suprema" && (
            <div>
              <Label htmlFor="api-key">{t("devices_page.api_key")}</Label>
              <Input
                id="api-key"
                value={form.api_key}
                onChange={(e) => patch({ api_key: e.target.value })}
                placeholder={t("devices_page.api_key_placeholder")}
                className="mt-1"
              />
            </div>
          )}
        </div>
      )}

      {connection === "pull" && (
        <SwitchRow
          label={t("device_detail_page.auto_sync")}
          hint={t("device_detail_page.auto_sync_hint")}
          checked={form.auto_sync}
          onChange={(v) => patch({ auto_sync: v })}
        />
      )}
    </Section>
  );
}

/** Whether the commands switch is offered for what the form describes. */
export function offersCommands(state: DeviceFormState): boolean {
  const { mode, device, form } = state;
  if (mode === "edit" && device && device.adapter_type === form.adapter_type) {
    return device.accepts_commands;
  }
  return canAcceptCommands(form.adapter_type);
}

export function DevicePeopleSection({ state }: { state: DeviceFormState }) {
  const { t } = useT();
  const { form, patch } = state;

  if (!offersCommands(state)) return null;

  return (
    <Section title={t("device_form.section_people")}>
      <SwitchRow
        label={t("device_commands.enable")}
        hint={t("device_commands.enable_hint")}
        checked={form.commands_enabled}
        onChange={(v) => patch({ commands_enabled: v })}
      />
    </Section>
  );
}

export function DeviceAdvancedSection({ state }: { state: DeviceFormState }) {
  const { t } = useT();
  const { form, patch, connection } = state;
  const isAdms = connection === "adms";

  return (
    <Section title={t("device_form.section_advanced")}>
      {connection === "pull" && form.auto_sync && (
        <div>
          <Label htmlFor="sync-interval">
            {t("device_detail_page.sync_interval")}
          </Label>
          <Select
            value={form.sync_interval_minutes}
            onValueChange={(v) => patch({ sync_interval_minutes: v })}
          >
            <SelectTrigger id="sync-interval" className="mt-1">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="5">
                {t("device_detail_page.every_5_min")}
              </SelectItem>
              <SelectItem value="10">
                {t("device_detail_page.every_10_min")}
              </SelectItem>
              <SelectItem value="15">
                {t("device_detail_page.every_15_min")}
              </SelectItem>
              <SelectItem value="30">
                {t("device_detail_page.every_30_min")}
              </SelectItem>
              <SelectItem value="60">
                {t("device_detail_page.every_1_hour")}
              </SelectItem>
            </SelectContent>
          </Select>
          <p className="mt-1 text-xs text-muted-foreground">
            {t(
              "device_detail_page.sync_interval_hint",
              "ETHR pulls from this terminal when its scheduler runs, at most this often, and sometimes later. A late pull loses nothing: each punch keeps the time it happened. Use :action when you need them at once.",
              { action: t("devices_page.pull") },
            )}
          </p>
        </div>
      )}

      <div>
        <Label htmlFor="device-timezone">
          {t("device_detail_page.clock_zone", "Device clock time zone")}
        </Label>
        <Select
          value={form.timezone || "__org__"}
          onValueChange={(v) => patch({ timezone: v === "__org__" ? "" : v })}
        >
          <SelectTrigger id="device-timezone" className="mt-1">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="__org__">
              {t(
                "device_detail_page.clock_zone_org",
                "Same as the organisation",
              )}
            </SelectItem>
            <SelectItem value="Africa/Addis_Ababa">
              Africa/Addis_Ababa (EAT)
            </SelectItem>
            <SelectItem value="UTC">UTC</SelectItem>
          </SelectContent>
        </Select>
        <p className="mt-1 text-xs text-muted-foreground">
          {t(
            "device_detail_page.clock_zone_hint",
            "The zone the device's own clock is set to. Its punch times are read in it.",
          )}
        </p>
      </div>

      <div>
        <Label htmlFor="device-allowlist">
          {isAdms
            ? t("device_form.allowlist_optional")
            : t(
                "device_detail_page.ip_allowlist",
                "Webhook allowed IP addresses",
              )}
        </Label>
        <Textarea
          id="device-allowlist"
          value={form.webhook_ip_allowlist}
          onChange={(e) => patch({ webhook_ip_allowlist: e.target.value })}
          rows={2}
          placeholder="203.0.113.7"
          className="mt-1 font-mono"
        />
        <p className="mt-1 text-xs text-muted-foreground">
          {isAdms
            ? t("device_form.allowlist_adms_hint")
            : t(
                "device_detail_page.ip_allowlist_hint",
                "One per line. A device without a webhook token is accepted only from these addresses.",
              )}
        </p>
      </div>
    </Section>
  );
}

export interface DeviceFormProps {
  mode: "create" | "edit";
  /** The stored device. Required on edit. */
  device?: Device;
  branches: Branch[];
  isPending: boolean;
  submitLabel: string;
  onSubmit: (payload: DevicePayload) => void;
  onCancel: () => void;
}

export function DeviceForm({
  mode,
  device,
  branches,
  isPending,
  submitLabel,
  onSubmit,
  onCancel,
}: DeviceFormProps) {
  const { t } = useT();
  const state = useFormState(mode, device);
  const { form, patch } = state;

  const onlyBranch = branches.length === 1 ? branches[0].public_id : null;
  // With no branch to offer the field is not drawn, and the server says why.
  const branchMissing =
    mode === "create" && branches.length > 0 && form.branch_public_id === "";

  // An organisation with one branch has nothing to choose: fill it in, so
  // Register is not held back by a question with one answer.
  useEffect(() => {
    if (mode === "create" && onlyBranch && form.branch_public_id === "") {
      patch({ branch_public_id: onlyBranch });
    }
    // patch is recreated every render and only calls setState.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [mode, onlyBranch, form.branch_public_id]);

  function submit(e: React.FormEvent) {
    e.preventDefault();
    if (mode === "create") {
      onSubmit(buildDevicePayload(form, "create"));
      return;
    }
    onSubmit(
      buildDevicePayload(form, "edit", {
        acceptsCommands: offersCommands(state),
        sendsAllowlist: device?.webhook_ip_allowlist !== undefined,
      }) as DevicePayload,
    );
  }

  return (
    <form onSubmit={submit} className="space-y-4">
      <DeviceIdentitySection state={state} branches={branches} />
      <DeviceConnectionSection state={state} />
      <DevicePeopleSection state={state} />
      <DeviceAdvancedSection state={state} />
      <DialogFooter>
        <Button type="button" variant="outline" onClick={onCancel}>
          {t("common.cancel")}
        </Button>
        <Button type="submit" disabled={isPending || branchMissing}>
          {isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
          {submitLabel}
        </Button>
      </DialogFooter>
    </form>
  );
}

export function DeviceFormDialog({
  open,
  onOpenChange,
  title,
  ...formProps
}: Omit<DeviceFormProps, "onCancel"> & {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
        </DialogHeader>
        <DeviceForm {...formProps} onCancel={() => onOpenChange(false)} />
      </DialogContent>
    </Dialog>
  );
}
