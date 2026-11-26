import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import {
  SaveStatusProvider,
  useSaveStatus,
} from "@/components/shared/save-status";
import { ActivationCodeCard } from "@/features/settings/components/activation-code-card";
import { PasswordPolicyCard } from "@/features/settings/components/password-policy-card";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/**
 * Settings forms warn before the tab is closed while they hold unsaved
 * changes, and one hidden live region announces "Saved" and save errors to
 * screen readers (settings review 2026-11-22, F10; W-21, W-22, W-42).
 */

function renderCard(node: ReactNode) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>
      <SaveStatusProvider>{node}</SaveStatusProvider>
    </QueryClientProvider>,
  );
}

function leavePage() {
  const event = new Event("beforeunload", {
    cancelable: true,
  }) as BeforeUnloadEvent;
  window.dispatchEvent(event);
  return event;
}

function serveSettingsPut(
  status = 200,
  body: Record<string, unknown> = { settings: {} },
) {
  server.use(
    http.put("*/api/v1/settings", () => HttpResponse.json(body, { status })),
  );
}

describe("unsaved changes in a settings card", () => {
  it("warns once the form differs from what was loaded, and stops after a save", async () => {
    serveSettingsPut();
    const user = userEvent.setup();
    renderCard(<ActivationCodeCard hours={24} />);

    expect(leavePage().defaultPrevented).toBe(false);

    const input = screen.getByLabelText("Code lifetime (hours)");
    await user.clear(input);
    await user.type(input, "48");
    expect(leavePage().defaultPrevented).toBe(true);

    await user.click(
      screen.getByRole("button", { name: "Save code lifetime" }),
    );

    await waitFor(() => expect(leavePage().defaultPrevented).toBe(false));
  });

  it("does not warn when the saved value is typed back in", async () => {
    const user = userEvent.setup();
    renderCard(<ActivationCodeCard hours={24} />);

    const input = screen.getByLabelText("Code lifetime (hours)");
    await user.clear(input);
    await user.type(input, "48");
    expect(leavePage().defaultPrevented).toBe(true);

    await user.clear(input);
    await user.type(input, "24");
    expect(leavePage().defaultPrevented).toBe(false);
  });

  it("keeps warning when a save is refused, because nothing was stored", async () => {
    serveSettingsPut(422, {
      detail: "The given data was invalid.",
      errors: { "settings.activation_code_hours": ["Too long."] },
    });
    const user = userEvent.setup();
    renderCard(<ActivationCodeCard hours={24} />);

    const input = screen.getByLabelText("Code lifetime (hours)");
    await user.clear(input);
    await user.type(input, "48");
    await user.click(
      screen.getByRole("button", { name: "Save code lifetime" }),
    );

    await screen.findByText("Too long.");
    expect(leavePage().defaultPrevented).toBe(true);
  });

  it("warns for a toggled rule and not once it is toggled back", async () => {
    const user = userEvent.setup();
    renderCard(
      <PasswordPolicyCard
        policy={{
          min_length: 8,
          require_uppercase: false,
          require_lowercase: false,
          require_number: false,
          require_symbol: false,
        }}
      />,
    );

    const upper = screen.getByRole("switch", {
      name: "Require an uppercase letter",
    });
    await user.click(upper);
    expect(leavePage().defaultPrevented).toBe(true);

    await user.click(upper);
    expect(leavePage().defaultPrevented).toBe(false);
  });
});

describe("the settings save status", () => {
  it("announces Saved, in one hidden polite live region, after a successful save", async () => {
    serveSettingsPut();
    const user = userEvent.setup();
    renderCard(<ActivationCodeCard hours={24} />);

    const region = screen.getByTestId("save-status");
    expect(region).toHaveAttribute("role", "status");
    expect(region).toHaveAttribute("aria-live", "polite");
    expect(region).toHaveClass("sr-only");
    expect(region).toHaveTextContent("");

    const input = screen.getByLabelText("Code lifetime (hours)");
    await user.clear(input);
    await user.type(input, "48");
    await user.click(
      screen.getByRole("button", { name: "Save code lifetime" }),
    );

    await waitFor(() => expect(region).toHaveTextContent("Settings saved"));
  });

  it("announces the server's reason when a save fails", async () => {
    serveSettingsPut(422, {
      detail: "The given data was invalid.",
      errors: { "settings.activation_code_hours": ["Too long."] },
    });
    const user = userEvent.setup();
    renderCard(<ActivationCodeCard hours={24} />);

    const input = screen.getByLabelText("Code lifetime (hours)");
    await user.clear(input);
    await user.type(input, "48");
    await user.click(
      screen.getByRole("button", { name: "Save code lifetime" }),
    );

    await waitFor(() =>
      expect(screen.getByTestId("save-status")).toHaveTextContent("Too long."),
    );
  });

  it("says a second identical message again, not only the first", async () => {
    function Probe() {
      const { announceSaved } = useSaveStatus();
      return <button onClick={() => announceSaved()}>save</button>;
    }
    const user = userEvent.setup();
    render(
      <SaveStatusProvider>
        <Probe />
      </SaveStatusProvider>,
    );

    const region = screen.getByTestId("save-status");
    const seen: string[] = [];
    new MutationObserver(() => seen.push(region.textContent ?? "")).observe(
      region,
      { childList: true, characterData: true, subtree: true },
    );

    await user.click(screen.getByRole("button", { name: "save" }));
    await waitFor(() => expect(region).toHaveTextContent("Settings saved"));
    await user.click(screen.getByRole("button", { name: "save" }));
    await waitFor(() =>
      expect(seen.filter((text) => text === "Settings saved")).toHaveLength(2),
    );
  });

  it("is a harmless no-op for a card rendered outside the settings pages", async () => {
    serveSettingsPut();
    const user = userEvent.setup();
    const queryClient = new QueryClient({
      defaultOptions: { mutations: { retry: false } },
    });
    render(
      <QueryClientProvider client={queryClient}>
        <ActivationCodeCard hours={24} />
      </QueryClientProvider>,
    );

    const input = screen.getByLabelText("Code lifetime (hours)");
    await user.clear(input);
    await user.type(input, "48");
    await user.click(
      screen.getByRole("button", { name: "Save code lifetime" }),
    );

    await waitFor(() => expect(leavePage().defaultPrevented).toBe(false));
    expect(screen.queryByTestId("save-status")).not.toBeInTheDocument();
  });
});
