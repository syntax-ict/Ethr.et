"use client";

import { useState } from "react";
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
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import type { Device } from "@/features/devices/api";
import {
  buildDevicePayload,
  canSendToEthr,
  EMPTY_DEVICE_FORM,
  isIpAdapter,
  MIN_SYNC_INTERVAL_MINUTES,
  SIMULATOR_IN_FORM,
  type DeviceFormData,
  type DevicePayload,
} from "@/features/devices/payload";
import { useT } from "@/lib/i18n/useT";

type Branch = { public_id: string; name: string };

/** Starting values: blank on create; the device's own on edit. */
function initialForm(
  mode: "create" | "edit",
  device: Device | undefined,
  branches: Branch[],
): DeviceFormData {
  if (mode === "create" || !device) {
    // An organisation with one branch has nothing to choose: fill it in, so
    // Register is not held back by a question with one answer.
    return {
      ...EMPTY_DEVICE_FORM,
      branch_public_id: branches.length === 1 ? branches[0].public_id : "",
    };
  }
  return {
    ...EMPTY_DEVICE_FORM,
    name: device.name,
    adapter_type: device.adapter_type,
    serial_number: device.serial_number ?? "",
    branch_public_id: device.branch_public_id ?? "",
    location_description: device.location_description ?? "",
    auto_sync: device.auto_sync,
    commands_enabled: device.commands_enabled,
    sync_interval_minutes: String(
      Math.max(MIN_SYNC_INTERVAL_MINUTES, device.sync_interval_minutes ?? 0),
    ),
    timezone: device.timezone ?? "",
    webhook_ip_allowlist: (device.webhook_ip_allowlist ?? []).join("\n"),
    // How the terminal reaches ETHR. The stored address is never returned (it
    // sits beside credentials), so the address fields start blank: blank keeps
    // it, and a blank port stops a default from replacing the stored one.
    sends_to_ethr:
      canSendToEthr(device.adapter_type) && device.receives_only === true,
    ip: "",
    port: "",
    username: "",
    password: "",
    api_key: "",
  };
}

function SectionHeading({ children }: { children: React.ReactNode }) {
  return (
    <p className="text-xs font-medium text-muted-foreground uppercase">
      {children}
    </p>
  );
}

/**
 * The one device form: Register, the list's Edit and the device page's Edit
 * all open this, so the three can no longer drift apart. The fields live in
 * an inner component that mounts with the dialog's content, so each opening
 * starts from the device (or from blank) rather than from the last edit.
 */
export function DeviceFormDialog({
  mode,
  device,
  open,
  onOpenChange,
  branches,
  onSubmit,
  isPending,
}: {
  mode: "create" | "edit";
  device?: Device;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  branches: Branch[];
  onSubmit: (payload: DevicePayload) => void;
  isPending: boolean;
}) {
  const { t } = useT();
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>
            {mode === "create"
              ? t("devices_page.register_device")
              : t("devices_page.edit_device")}
          </DialogTitle>
        </DialogHeader>
        <DeviceFormFields
          mode={mode}
          device={device}
          branches={branches}
          onCancel={() => onOpenChange(false)}
          onSubmit={onSubmit}
          isPending={isPending}
        />
      </DialogContent>
    </Dialog>
  );
}

function DeviceFormFields({
  mode,
  device,
  branches,
  onCancel,
  onSubmit,
  isPending,
}: {
  mode: "create" | "edit";
  device?: Device;
  branches: Branch[];
  onCancel: () => void;
  onSubmit: (payload: DevicePayload) => void;
  isPending: boolean;
}) {
  const { t } = useT();
  const [form, setForm] = useState<DeviceFormData>(() =>
    initialForm(mode, device, branches),
  );
  const set = (key: keyof DeviceFormData, value: string | boolean) =>
    setForm((p) => ({ ...p, [key]: value }));

  const creating = mode === "create";
  const adapter = form.adapter_type;
  const isAdms = adapter === "zkteco_adms";
  const isMock = adapter === "mock";
  const sendsToEthr = canSendToEthr(adapter) && form.sends_to_ethr;
  const wasPush = !creating && device?.receives_only === true;
  // A blank address keeps the stored connection, unless there is none worth
  // keeping: a new device, a changed adapter (the stored settings belong to
  // another vendor), or a terminal that sent and is now switched to an address.
  const addressRequired =
    creating ||
    device?.adapter_type !== adapter ||
    (wasPush && canSendToEthr(adapter));
  // The branch list may arrive after the dialog opens: with one branch there
  // is nothing to choose, so it stands in for a blank choice.
  const onlyBranch = branches.length === 1 ? branches[0].public_id : null;
  const branchValue =
    form.branch_public_id || (creating && onlyBranch ? onlyBranch : "");
  const branchRequired = creating;
  const branchMissing =
    branchRequired && branches.length > 0 && branchValue === "";
  const showCommands = !creating && device?.accepts_commands === true;
  const pulledAdapter = !sendsToEthr && !isAdms;
  const hasStoredAllowlist =
    !creating && (device?.webhook_ip_allowlist?.length ?? 0) > 0;
  const showAllowlist = isAdms || sendsToEthr || hasStoredAllowlist;
  const showConnection =
    isAdms || isMock || canSendToEthr(adapter) || isIpAdapter(adapter);

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        const payload = buildDevicePayload(
          { ...form, branch_public_id: branchValue },
          mode,
          {
            acceptsCommands: showCommands,
          },
        );
        // A viewer who may edit but not read the allowlist is sent none, so
        // an untouched box must not overwrite what is stored with [].
        if (
          !creating &&
          device?.webhook_ip_allowlist === undefined &&
          form.webhook_ip_allowlist.trim() === ""
        ) {
          delete payload.webhook_ip_allowlist;
        }
        onSubmit(payload);
      }}
      className="space-y-4"
    >
      <SectionHeading>{t("devices_page.section_identity")}</SectionHeading>
      <div>
        <Label htmlFor="device-name">{t("devices_page.device_name")}</Label>
        <Input
          id="device-name"
          value={form.name}
          onChange={(e) => set("name", e.target.value)}
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
          onChange={(e) => set("location_description", e.target.value)}
          placeholder={t("device_detail_page.location_placeholder")}
          className="mt-1"
        />
      </div>
      <div className="grid grid-cols-2 gap-4">
        <div>
          <Label htmlFor="adapter-type">{t("devices_page.adapter_type")}</Label>
          <Select value={adapter} onValueChange={(v) => set("adapter_type", v)}>
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
              {(SIMULATOR_IN_FORM || isMock) && (
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
            onChange={(e) => set("serial_number", e.target.value)}
            placeholder={
              isAdms
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
            value={branchValue}
            onValueChange={(v) => set("branch_public_id", v)}
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

      {showConnection && (
        <SectionHeading>{t("devices_page.section_connection")}</SectionHeading>
      )}
      {isAdms && (
        <p className="rounded-lg border p-3 text-sm text-muted-foreground">
          {t("devices_page.adms_hint")}
        </p>
      )}
      {isMock && (
        <div className="rounded-lg border border-warning-edge bg-warning-soft p-3 text-sm text-warning-on-soft">
          {t("devices_page.mock_hint")}
        </div>
      )}
      {canSendToEthr(adapter) && (
        <div className="flex items-center justify-between gap-3 rounded-lg border p-3">
          <div>
            <p className="text-sm font-medium">
              {t(
                "devices_page.sends_to_ethr",
                "The terminal is on our office network",
              )}
            </p>
            <p className="text-xs text-muted-foreground">
              {t(
                "devices_page.sends_to_ethr_hint",
                "It sends each punch to ETHR, so no address is needed. After registering, the device page shows what to enter on the terminal. ETHR cannot read its enrolled users, set its clock or fetch punches it missed.",
              )}
            </p>
          </div>
          <Switch
            aria-label={t(
              "devices_page.sends_to_ethr",
              "The terminal is on our office network",
            )}
            checked={form.sends_to_ethr}
            onCheckedChange={(v) => set("sends_to_ethr", v)}
          />
        </div>
      )}
      {isIpAdapter(adapter) && !sendsToEthr && (
        <div className="space-y-4 rounded-lg border p-4">
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
                onChange={(e) => set("ip", e.target.value)}
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
                onChange={(e) => set("port", e.target.value)}
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
                onChange={(e) => set("username", e.target.value)}
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
                onChange={(e) => set("password", e.target.value)}
                placeholder={t("leave_page.optional")}
                className="mt-1"
              />
            </div>
          </div>
          {adapter === "suprema" && (
            <div>
              <Label htmlFor="api-key">{t("devices_page.api_key")}</Label>
              <Input
                id="api-key"
                value={form.api_key}
                onChange={(e) => set("api_key", e.target.value)}
                placeholder={t("devices_page.api_key_placeholder")}
                className="mt-1"
              />
            </div>
          )}
        </div>
      )}

      {showCommands && (
        <>
          <SectionHeading>{t("devices_page.section_commands")}</SectionHeading>
          <div className="flex items-center justify-between gap-3 rounded-lg border p-3">
            <div>
              <p className="text-sm font-medium">
                {t("device_commands.enable")}
              </p>
              <p className="text-xs text-muted-foreground">
                {t("device_commands.enable_hint")}
              </p>
            </div>
            <Switch
              aria-label={t("device_commands.enable")}
              checked={form.commands_enabled}
              onCheckedChange={(v) => set("commands_enabled", v)}
            />
          </div>
        </>
      )}

      <SectionHeading>{t("devices_page.section_advanced")}</SectionHeading>
      {pulledAdapter && (
        <div className="flex items-center justify-between rounded-lg border p-3">
          <div>
            <p className="text-sm font-medium">
              {t("device_detail_page.auto_sync")}
            </p>
            <p className="text-xs text-muted-foreground">
              {t("device_detail_page.auto_sync_hint")}
            </p>
          </div>
          <Switch
            aria-label={t("device_detail_page.auto_sync")}
            checked={form.auto_sync}
            onCheckedChange={(v) => set("auto_sync", v)}
          />
        </div>
      )}
      {pulledAdapter && form.auto_sync && (
        <div>
          <Label htmlFor="sync-interval">
            {t("device_detail_page.sync_interval")}
          </Label>
          <Select
            value={form.sync_interval_minutes}
            onValueChange={(v) => set("sync_interval_minutes", v)}
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
          onValueChange={(v) => set("timezone", v === "__org__" ? "" : v)}
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
      {showAllowlist && (
        <div>
          <Label htmlFor="device-allowlist">
            {t(
              "device_detail_page.ip_allowlist",
              "Webhook allowed IP addresses",
            )}
          </Label>
          <Textarea
            id="device-allowlist"
            value={form.webhook_ip_allowlist}
            onChange={(e) => set("webhook_ip_allowlist", e.target.value)}
            rows={2}
            placeholder="203.0.113.7"
            className="mt-1 font-mono"
          />
          <p className="mt-1 text-xs text-muted-foreground">
            {t(
              "device_detail_page.ip_allowlist_hint",
              "One per line. A device without a webhook token is accepted only from these addresses.",
            )}
          </p>
          {isAdms && (
            <p className="mt-1 text-xs text-muted-foreground">
              {t("devices_page.allowlist_adms_optional")}
            </p>
          )}
        </div>
      )}

      <DialogFooter>
        <Button type="button" variant="outline" onClick={onCancel}>
          {t("common.cancel")}
        </Button>
        <Button type="submit" disabled={isPending || branchMissing}>
          {isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
          {creating
            ? t("devices_page.register")
            : t("leave_types_page.save_changes")}
        </Button>
      </DialogFooter>
    </form>
  );
}
