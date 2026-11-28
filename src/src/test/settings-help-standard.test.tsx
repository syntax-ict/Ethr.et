import { describe, it, expect, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { useState } from "react";
import { server } from "./msw/server";
import { SettingRow } from "@/components/patterns/SettingRow";
import { InputWithUnit } from "@/components/shared/input-with-unit";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { Switch } from "@/components/ui/switch";
import AttendanceSettingsPage from "@/app/(dashboard)/settings/attendance/page";

/**
 * The settings and help-text standard (frontend plan S3): one line of help,
 * the rest behind a closed "How it works", the unit inside the field, and a
 * segmented control for a choice of three.
 */

beforeEach(() => {
  localStorage.clear();
  localStorage.setItem("locale", "en");
});

describe("<SettingRow>", () => {
  it("keeps the details closed, reachable by Tab, and opens them", async () => {
    const user = userEvent.setup();
    render(
      <SettingRow
        id="demo"
        title="Auto check-out"
        description="Closes open check-ins at shift end."
        details="The employee is told and can file a correction."
        badge="Writes attendance"
      >
        <Switch />
      </SettingRow>,
    );

    const detail = screen.getByText(
      "The employee is told and can file a correction.",
    );
    expect(detail).not.toBeVisible();
    expect(screen.getByText("Writes attendance")).toBeVisible();

    // A native summary is a tab stop, and a browser turns Enter or Space on it
    // into a click; jsdom does not, so the click stands in for the keypress.
    await user.tab();
    await user.tab();
    const summary = screen.getByText("How it works").closest("summary");
    expect(summary).toHaveFocus();
    await user.click(summary as HTMLElement);
    expect(detail).toBeVisible();
  });

  it("describes the control by the one-line help only", () => {
    render(
      <SettingRow
        id="demo"
        title="Auto check-out"
        description="Closes open check-ins at shift end."
        details="The employee is told and can file a correction."
      >
        <Switch />
      </SettingRow>,
    );

    const control = screen.getByRole("switch", { name: "Auto check-out" });
    expect(control).toHaveAccessibleDescription(
      "Closes open check-ins at shift end.",
    );
  });

  it("shows a warning as an alert only when given one", () => {
    const { rerender } = render(
      <SettingRow id="demo" title="Presence checks">
        <Switch />
      </SettingRow>,
    );
    expect(screen.queryByRole("status")).toBeNull();

    rerender(
      <SettingRow
        id="demo"
        title="Presence checks"
        warning="Tell your staff in writing first."
      >
        <Switch />
      </SettingRow>,
    );
    expect(screen.getByRole("status")).toHaveTextContent(
      "Tell your staff in writing first.",
    );
  });
});

describe("<InputWithUnit>", () => {
  it("shows the unit and links it to the field", () => {
    render(
      <SettingRow id="cap" title="Daily overtime limit" description="Flagged.">
        <InputWithUnit id="cap" unit="min" type="number" defaultValue={120} />
      </SettingRow>,
    );

    const input = screen.getByRole("spinbutton", {
      name: "Daily overtime limit",
    });
    expect(screen.getByText("min")).toBeVisible();
    expect(input).toHaveAttribute("inputmode", "numeric");
    expect(input).toHaveAccessibleDescription("Flagged. min");
  });
});

// Radix moves focus a tick after the keydown and selects only while the arrow
// is still held, so hold the key across that tick as a person's finger does.
async function pressArrow(
  user: ReturnType<typeof userEvent.setup>,
  key: "ArrowRight" | "ArrowLeft",
) {
  await user.keyboard(`{${key}>}`);
  await new Promise((resolve) => setTimeout(resolve, 20));
  await user.keyboard(`{/${key}}`);
}

describe("the segmented control", () => {
  function Demo() {
    const [value, setValue] = useState("off");
    const effect: Record<string, string> = {
      off: "Runs are approved as today.",
      advisory: "The run lists attendance to settle.",
      required: "A run waits until attendance is settled.",
    };
    return (
      <SettingRow
        id="reconcile"
        title="Reconcile before payroll"
        description={effect[value]}
        stacked
      >
        <RadioGroup variant="segmented" value={value} onValueChange={setValue}>
          <RadioGroupItem value="off">Off</RadioGroupItem>
          <RadioGroupItem value="advisory">Advisory</RadioGroupItem>
          <RadioGroupItem value="required">Required</RadioGroupItem>
        </RadioGroup>
      </SettingRow>
    );
  }

  it("moves with the arrow keys and shows only the selected option's effect", async () => {
    const user = userEvent.setup();
    render(<Demo />);

    const group = screen.getByRole("radiogroup", {
      name: "Reconcile before payroll",
    });
    expect(within(group).getAllByRole("radio")).toHaveLength(3);
    expect(screen.getByRole("radio", { name: "Off" })).toBeChecked();
    expect(screen.getByText("Runs are approved as today.")).toBeVisible();

    await user.click(screen.getByRole("radio", { name: "Off" }));
    await pressArrow(user, "ArrowRight");
    expect(screen.getByRole("radio", { name: "Advisory" })).toBeChecked();
    expect(
      screen.getByText("The run lists attendance to settle."),
    ).toBeVisible();
    expect(screen.queryByText("Runs are approved as today.")).toBeNull();

    await pressArrow(user, "ArrowRight");
    expect(screen.getByRole("radio", { name: "Required" })).toBeChecked();
    expect(
      screen.getByText("A run waits until attendance is settled."),
    ).toBeVisible();
  });
});

const SETTINGS = {
  public_id: "01HZSET0000000000000000001",
  enabled_methods: ["web", "mobile"],
  geofence_required: false,
  mobile_photo_required: false,
  kiosk_pin_required: false,
  qr_expiry_minutes: 30,
  qr_auto_refresh: true,
  qr_single_use_limit: 0,
  mobile_accuracy_threshold_meters: 100,
  offline_sync_enabled: true,
  kiosk_auto_reset_seconds: 4,
  grace_period_minutes: 15,
  ot_daily_cap_minutes: 240,
  confidence_threshold: 60,
  reminder_lead_minutes: 10,
  auto_checkout_after_minutes: 120,
  photo_retention_days: 30,
  risk_review_threshold: 40,
  presence_checks_per_shift: 0,
  device_binding: "off",
  office_networks: [],
  reconciliation_mode: "off",
  lateness_deduction_enabled: false,
  lateness_deduction_basis: null,
  updated_at: "2026-09-01T06:00:00+00:00",
};

describe("/settings/attendance", () => {
  it("saves the same payload from the rewritten rows", async () => {
    const user = userEvent.setup();
    let body: Record<string, unknown> | null = null;
    server.use(
      http.get("*/api/v1/auth/me", () =>
        HttpResponse.json({
          user: { public_id: "U1", name: "Admin", role: "custom" },
          tenant: {
            public_id: "T1",
            name: "Demo",
            timezone: "Africa/Addis_Ababa",
          },
          permissions: ["attendance.manage", "payroll.manageConfig"],
        }),
      ),
      http.get("*/api/v1/attendance/settings", () =>
        HttpResponse.json(SETTINGS),
      ),
      http.get("*/api/v1/attendance/settings/branches", () =>
        HttpResponse.json({ organisation: {}, branches: [] }),
      ),
      http.put("*/api/v1/attendance/settings", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ ...SETTINGS, ...body });
      }),
    );
    const client = new QueryClient({
      defaultOptions: {
        queries: { retry: false },
        mutations: { retry: false },
      },
    });
    render(
      <QueryClientProvider client={client}>
        <AttendanceSettingsPage />
      </QueryClientProvider>,
    );

    const cap = await screen.findByRole("spinbutton", {
      name: "Daily overtime limit",
    });
    expect(cap).toHaveValue(240);
    expect(cap).toHaveAccessibleDescription(
      "Days above this are flagged on the payroll run. min",
    );

    // The long sentences moved behind "How it works", closed.
    expect(
      screen.getByText(/All overtime worked is still paid/),
    ).not.toBeVisible();

    await user.clear(cap);
    await user.type(cap, "180");
    await user.click(screen.getByRole("radio", { name: "Required" }));

    const save = screen.getByRole("button", { name: /Save/ });
    await waitFor(() => expect(save).toBeEnabled());
    await user.click(save);

    await waitFor(() =>
      expect(body).toMatchObject({
        ot_daily_cap_minutes: 180,
        reconciliation_mode: "required",
        confidence_threshold: 60,
        photo_retention_days: 30,
      }),
    );
  });
});
