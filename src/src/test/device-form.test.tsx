import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, within, cleanup } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { DeviceFormDialog } from "@/features/devices/components/device-form";
import type { Device } from "@/features/devices/api";

/**
 * Register, the list's Edit and the device page's Edit are one dialog, so
 * they cannot drift apart (device setup upgrade, PR 2).
 */
vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const BRANCHES = [{ public_id: "B1", name: "Head office" }];

const PULLED = {
  public_id: "DEV1",
  name: "Main gate",
  location_description: "Front door",
  serial_number: null,
  adapter_type: "hikvision",
  status: "online",
  auto_sync: true,
  commands_enabled: false,
  accepts_commands: false,
  receives_only: false,
  pulled: true,
  sync_interval_minutes: 10,
  timezone: null,
  webhook_ip_allowlist: [],
  branch_public_id: "B1",
} as unknown as Device;

beforeEach(() => {
  localStorage.clear();
  localStorage.setItem("locale", "en");
});

/** The id and the visible label of every field in the dialog. */
function fieldSet(dialog: HTMLElement): string[] {
  return Array.from(
    dialog.querySelectorAll<HTMLElement>("input, textarea, button[role]"),
  )
    .map((el) => {
      const label = el.id
        ? dialog.querySelector(`label[for="${el.id}"]`)?.textContent
        : null;
      return `${el.id || el.getAttribute("aria-label") || ""}|${(label ?? "").replace(" *", "")}`;
    })
    .filter((s) => s !== "|")
    .sort();
}

function open(
  props: Partial<React.ComponentProps<typeof DeviceFormDialog>> = {},
) {
  const onSubmit = vi.fn();
  render(
    <DeviceFormDialog
      mode="create"
      open
      onOpenChange={() => {}}
      branches={BRANCHES}
      onSubmit={onSubmit}
      isPending={false}
      {...props}
    />,
  );
  return { dialog: screen.getByRole("dialog"), onSubmit };
}

describe("<DeviceFormDialog>", () => {
  it("shows the same fields on register and on edit for a pulled Hikvision device", () => {
    const registerFields = fieldSet(open().dialog);
    cleanup();

    const editFields = fieldSet(open({ mode: "edit", device: PULLED }).dialog);

    expect(editFields).toEqual(registerFields);
    expect(registerFields.some((f) => f.startsWith("sync-interval"))).toBe(
      true,
    );
    expect(registerFields.some((f) => f.startsWith("device-timezone"))).toBe(
      true,
    );
    expect(
      registerFields.some((f) => f.startsWith("location-description")),
    ).toBe(true);
  });

  it("starts an edit from the device, and sends everything it holds", async () => {
    const user = userEvent.setup();
    const { dialog, onSubmit } = open({ mode: "edit", device: PULLED });

    expect(within(dialog).getByLabelText(/device name/i)).toHaveValue(
      "Main gate",
    );
    expect(within(dialog).getByLabelText("Location Description")).toHaveValue(
      "Front door",
    );
    await user.click(
      within(dialog).getByRole("button", { name: /save changes/i }),
    );

    const payload = onSubmit.mock.calls[0][0];
    expect(payload).toMatchObject({
      name: "Main gate",
      location_description: "Front door",
      branch_public_id: "B1",
      sync_interval_minutes: 10,
      webhook_ip_allowlist: [],
    });
    expect(payload).not.toHaveProperty("connection_config");
  });

  it("shows the allowlist on ADMS with its optional hint, and no Auto-Sync", async () => {
    const user = userEvent.setup();
    const { dialog } = open();
    expect(within(dialog).queryByLabelText(/allowed ip/i)).toBeNull();

    await user.click(
      within(dialog).getByRole("combobox", { name: /adapter|device type/i }),
    );
    await user.click(
      await screen.findByRole("option", { name: /ZKTeco \(ADMS/ }),
    );

    expect(within(dialog).getByLabelText(/allowed ip/i)).toBeVisible();
    expect(
      within(dialog).getByText(/Optional for a ZKTeco ADMS terminal/),
    ).toBeVisible();
    expect(
      within(dialog).queryByRole("switch", { name: /auto-sync/i }),
    ).toBeNull();
  });

  it("shows a stored allowlist on a pulled device so it can be cleared", async () => {
    const user = userEvent.setup();
    const device = { ...PULLED, webhook_ip_allowlist: ["203.0.113.7"] };
    const { dialog, onSubmit } = open({
      mode: "edit",
      device: device as Device,
    });

    const box = within(dialog).getByLabelText(/allowed ip/i);
    expect(box).toHaveValue("203.0.113.7");
    await user.clear(box);
    await user.click(
      within(dialog).getByRole("button", { name: /save changes/i }),
    );

    expect(onSubmit.mock.calls[0][0].webhook_ip_allowlist).toEqual([]);
  });

  it("fills in the only branch even when the list arrives after the dialog opens", async () => {
    const props = {
      mode: "create" as const,
      open: true,
      onOpenChange: () => {},
      onSubmit: vi.fn(),
      isPending: false,
    };
    const { rerender } = render(<DeviceFormDialog {...props} branches={[]} />);
    rerender(<DeviceFormDialog {...props} branches={BRANCHES} />);

    expect(
      await within(screen.getByRole("dialog")).findByRole("button", {
        name: /^register$/i,
      }),
    ).toBeEnabled();
  });

  it("does not overwrite an allowlist the viewer was not allowed to read", async () => {
    const user = userEvent.setup();
    const device = { ...PULLED } as Record<string, unknown>;
    delete device.webhook_ip_allowlist;
    const { dialog, onSubmit } = open({
      mode: "edit",
      device: device as unknown as Device,
    });

    await user.click(
      within(dialog).getByRole("button", { name: /save changes/i }),
    );

    expect(onSubmit.mock.calls[0][0]).not.toHaveProperty(
      "webhook_ip_allowlist",
    );
  });

  it("offers the commands switch only on edit of a device that accepts them", () => {
    const edited = open({
      mode: "edit",
      device: { ...PULLED, accepts_commands: true } as Device,
    });
    expect(
      within(edited.dialog).getByRole("switch", {
        name: "Send commands to this device",
      }),
    ).toBeInTheDocument();
    cleanup();

    const registered = open();
    expect(
      within(registered.dialog).queryByRole("switch", {
        name: "Send commands to this device",
      }),
    ).toBeNull();
  });
});
