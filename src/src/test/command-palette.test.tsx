import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import {
  CommandPalette,
  openCommandPalette,
} from "@/components/shared/command-palette";
import { act } from "react";

const pushMock = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: pushMock }),
}));

function mockUser(role: string, permissions: string[] = []) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "01HZUSER", name: "Test", role },
        permissions,
        tenant: null,
      }),
    ),
  );
}

function mockDirectory(people: Array<Record<string, unknown>>) {
  server.use(
    http.get("*/api/v1/directory", () => HttpResponse.json({ data: people })),
  );
}

function renderPalette() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>
      <CommandPalette />
    </QueryClientProvider>,
  );
}

describe("CommandPalette global search", () => {
  beforeEach(() => pushMock.mockReset());

  it("finds a person by name and navigates to their record (supervisor+)", async () => {
    mockUser("hr_admin");
    mockDirectory([
      {
        public_id: "01HZEMP1",
        name: "Abebe Kebede",
        email: "abebe@demo.et",
        position: "Officer",
        department: "Finance",
        photo_thumb_url: null,
      },
    ]);

    const user = userEvent.setup();
    renderPalette();

    await user.keyboard("{Meta>}k{/Meta}");
    const input = await screen.findByPlaceholderText(/type a command/i);
    await user.type(input, "abebe");

    const person = await screen.findByText("Abebe Kebede");
    await user.click(person);

    await waitFor(() =>
      expect(pushMock).toHaveBeenCalledWith("/employees/01HZEMP1"),
    );
  });

  it("opens from openCommandPalette() without a keypress (header/mobile trigger)", async () => {
    mockUser("employee");
    renderPalette();

    // Closed initially.
    expect(
      screen.queryByPlaceholderText(/type a command/i),
    ).not.toBeInTheDocument();

    act(() => openCommandPalette());

    expect(
      await screen.findByPlaceholderText(/type a command/i),
    ).toBeInTheDocument();
  });

  it("does not offer people search to a plain employee", async () => {
    mockUser("employee");
    const directoryHit = vi.fn();
    server.use(
      http.get("*/api/v1/directory", () => {
        directoryHit();
        return HttpResponse.json({ data: [] });
      }),
    );

    const user = userEvent.setup();
    renderPalette();

    await user.keyboard("{Meta>}k{/Meta}");
    const input = await screen.findByPlaceholderText(/type a command/i);
    await user.type(input, "abebe");

    // Give any debounced request a chance to fire, then assert it never did.
    await new Promise((r) => setTimeout(r, 350));
    expect(directoryHit).not.toHaveBeenCalled();
  });
});
