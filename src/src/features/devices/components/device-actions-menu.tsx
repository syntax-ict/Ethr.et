"use client";

import { Fragment, useState, type ReactNode } from "react";
import Link from "next/link";
import {
  Clock,
  Copy,
  Eye,
  History,
  MoreVertical,
  Pencil,
  RefreshCw,
  RotateCw,
  Signal,
  Trash2,
  Users,
} from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import {
  usePullDeviceEvents,
  useRegenerateDeviceToken,
  useSendStaffToDevice,
  useSetDeviceTime,
  useTestDeviceConnection,
  type Device,
  isPulled,
} from "@/features/devices/api";
import { syncErrorHintKeys } from "@/features/devices/components/device-heard";
import { apiErrorMessage } from "@/lib/api/error-message";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";

interface DeviceActionsMenuProps {
  device: Device;
  /** The menu is already on the device's own page: no link back to it. */
  hideDetailsLink?: boolean;
  /** Dialogs live on the page that owns them; an absent callback means the item is not offered. */
  onEdit?: () => void;
  onDelete?: () => void;
  onDiscover?: () => void;
  onImportHistory?: () => void;
  /**
   * Test and Pull default to this menu's own requests. A page that already
   * fires them from a button passes its own, so a click makes one request and
   * the button and the menu show the same pending state.
   */
  onTest?: () => void;
  onPull?: () => void;
  testing?: boolean;
  pulling?: boolean;
}

/**
 * Everything an administrator can do to one device, in one menu, grouped by
 * what it is for. Used on the device list and the device dashboard so the two
 * offer the same actions the same way.
 *
 * A terminal that only sends to ETHR (`receives_only`) is never called, so it
 * is not offered a test, a pull, its enrolled users or its history, and the
 * menu says why.
 */
export function DeviceActionsMenu({
  device,
  hideDetailsLink = false,
  onEdit,
  onDelete,
  onDiscover,
  onImportHistory,
  onTest,
  onPull,
  testing,
  pulling,
}: DeviceActionsMenuProps) {
  const { t } = useT();
  const { hasPermission } = usePermissions();
  const canUpdate = hasPermission("device.update");
  const canDelete = hasPermission("device.delete");

  const testMutation = useTestDeviceConnection();
  const pullMutation = usePullDeviceEvents();
  const regenMutation = useRegenerateDeviceToken();
  const setTime = useSetDeviceTime();
  const sendStaff = useSendStaffToDevice();
  const [confirmRegen, setConfirmRegen] = useState(false);

  const id = device.public_id;
  const sendsOnly = device.receives_only === true;
  const pulled = isPulled(device);
  const takesCommands =
    device.accepts_commands === true && device.commands_enabled === true;

  function ownTest() {
    testMutation.mutate(id, {
      onSuccess: ({ status, error_code }) => {
        if (status === "online") {
          toast.success(t("devices_page.online_responding"));
        } else {
          const hint = syncErrorHintKeys(error_code, device.adapter_type)
            .map((k) => t(k))
            .join(" ");
          toast.error(
            `${t("devices_page.device_status", "The device is :status", {
              status: t(`devices_page.${status}`, status),
            })}${hint ? ` ${hint}` : ""}`,
          );
        }
      },
      onError: (err) =>
        toast.error(apiErrorMessage(err, t("devices_page.test_failed"))),
    });
  }

  function ownPull() {
    pullMutation.mutate(id, {
      onSuccess: () => toast.success(t("devices_page.pull_initiated")),
      onError: (err) =>
        toast.error(apiErrorMessage(err, t("devices_page.pull_failed"))),
    });
  }

  async function copyAddress() {
    try {
      await navigator.clipboard.writeText(device.webhook_url ?? "");
      toast.success(t("attendance.kiosks_page.copied"));
    } catch {
      toast.error(t("devices_page.copy_failed"));
    }
  }

  function regenerate() {
    regenMutation.mutate(id, {
      onSuccess: () => {
        toast.success(t("device_detail_page.token_regenerated"));
        setConfirmRegen(false);
      },
      onError: (err) => {
        toast.error(
          apiErrorMessage(err, t("device_detail_page.regenerate_failed")),
        );
        setConfirmRegen(false);
      },
    });
  }

  const icon = "mr-2 h-4 w-4";
  const groups: { key: string; label?: string; items: ReactNode[] }[] = [];
  const add = (
    key: string,
    label: string | undefined,
    items: (ReactNode | false)[],
  ) => {
    const shown = items.filter(Boolean) as ReactNode[];
    if (shown.length > 0) groups.push({ key, label, items: shown });
  };

  add("open", undefined, [
    !hideDetailsLink && (
      <DropdownMenuItem key="details" asChild>
        <Link href={`/devices/${id}`}>
          <Eye className={icon} aria-hidden="true" />
          {t("devices_page.actions_view_details")}
        </Link>
      </DropdownMenuItem>
    ),
  ]);

  add("connection", t("devices_page.actions_group_connection"), [
    !sendsOnly && (
      <DropdownMenuItem
        key="test"
        onSelect={onTest ?? ownTest}
        disabled={testing ?? testMutation.isPending}
      >
        <Signal className={icon} aria-hidden="true" />
        {t("devices_page.test_connection")}
      </DropdownMenuItem>
    ),
    pulled && canUpdate && (
      <DropdownMenuItem
        key="pull"
        onSelect={onPull ?? ownPull}
        disabled={pulling ?? pullMutation.isPending}
      >
        <RefreshCw className={icon} aria-hidden="true" />
        {t("devices_page.pull_records")}
      </DropdownMenuItem>
    ),
  ]);

  add("address", t("devices_page.actions_group_address"), [
    Boolean(device.webhook_url) && (
      <DropdownMenuItem key="copy" onSelect={() => void copyAddress()}>
        <Copy className={icon} aria-hidden="true" />
        {t("device_detail_page.copy_webhook_url", "Copy webhook URL")}
      </DropdownMenuItem>
    ),
    Boolean(device.webhook_url) && canUpdate && (
      <DropdownMenuItem
        key="regen"
        onSelect={() => setConfirmRegen(true)}
        disabled={regenMutation.isPending}
      >
        <RotateCw className={icon} aria-hidden="true" />
        {t("device_detail_page.regenerate_token")}
      </DropdownMenuItem>
    ),
  ]);

  add("staff", t("devices_page.actions_group_staff"), [
    !sendsOnly && onDiscover && (
      <DropdownMenuItem key="discover" onSelect={onDiscover}>
        <Users className={icon} aria-hidden="true" />
        {t("devices_page.discover_users", "Discover enrolled users")}
      </DropdownMenuItem>
    ),
    pulled && canUpdate && onImportHistory && (
      <DropdownMenuItem key="history" onSelect={onImportHistory}>
        <History className={icon} aria-hidden="true" />
        {t("devices_page.import_history", "Import attendance history")}
      </DropdownMenuItem>
    ),
    takesCommands && canUpdate && (
      <DropdownMenuItem
        key="set-time"
        disabled={setTime.isPending}
        onSelect={() =>
          setTime.mutate(id, {
            onSuccess: () =>
              toast.success(t("device_commands.set_time_queued")),
            onError: (e) =>
              toast.error(apiErrorMessage(e, t("device_commands.failed"))),
          })
        }
      >
        <Clock className={icon} aria-hidden="true" />
        {t("device_commands.set_time")}
      </DropdownMenuItem>
    ),
    takesCommands && canUpdate && (
      <DropdownMenuItem
        key="send-staff"
        disabled={sendStaff.isPending}
        onSelect={() =>
          sendStaff.mutate(id, {
            onSuccess: (r) =>
              toast.success(
                t("device_commands.send_staff_queued", undefined, {
                  queued: r.queued,
                  skipped: r.skipped,
                }),
              ),
            onError: (e) =>
              toast.error(apiErrorMessage(e, t("device_commands.failed"))),
          })
        }
      >
        <Users className={icon} aria-hidden="true" />
        {t("device_commands.send_staff")}
      </DropdownMenuItem>
    ),
  ]);

  add("manage", t("devices_page.actions_group_manage"), [
    canUpdate && onEdit && (
      <DropdownMenuItem key="edit" onSelect={onEdit}>
        <Pencil className={icon} aria-hidden="true" />
        {t("common.edit")}
      </DropdownMenuItem>
    ),
    canDelete && onDelete && (
      <DropdownMenuItem
        key="delete"
        onSelect={onDelete}
        className="text-destructive focus:text-destructive"
      >
        <Trash2 className={icon} aria-hidden="true" />
        {t("common.delete")}
      </DropdownMenuItem>
    ),
  ]);

  if (groups.length === 0) return null;

  return (
    <>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            variant="ghost"
            size="icon"
            className="h-7 w-7"
            aria-label={t("common.actions", "Actions")}
          >
            <MoreVertical className="h-4 w-4" aria-hidden="true" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="min-w-52">
          {groups.map((group, i) => (
            <Fragment key={group.key}>
              {i > 0 && <DropdownMenuSeparator />}
              {group.label && (
                <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
                  {group.label}
                </DropdownMenuLabel>
              )}
              {group.items}
            </Fragment>
          ))}
          {sendsOnly && (
            <>
              <DropdownMenuSeparator />
              <p className="max-w-56 px-2 py-1.5 text-xs text-muted-foreground">
                {t("devices_page.actions_sends_only_note")}
              </p>
            </>
          )}
        </DropdownMenuContent>
      </DropdownMenu>

      {/* Outside the menu content, so closing the menu does not unmount it. */}
      <ConfirmDialog
        open={confirmRegen}
        onOpenChange={setConfirmRegen}
        title={t("devices_page.regenerate_confirm_title")}
        description={t("devices_page.regenerate_confirm_desc")}
        confirmLabel={t("device_detail_page.regenerate_token")}
        variant="destructive"
        loading={regenMutation.isPending}
        onConfirm={regenerate}
      />
    </>
  );
}
