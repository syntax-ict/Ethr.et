import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { PresenceCard } from "@/features/attendance/components/mobile/presence-card";
import type { DeviceProof } from "@/lib/attendance/passkey";
import type { Fix } from "@/lib/attendance/punch-signals";

/**
 * The card on the phone punch screen that asks "confirm you are at work": what
 * it says while a check waits, and what each answer comes to. It holds no
 * position, signer or connection of its own, so each test hands it those.
 */
const fix: Fix = {
  latitude: 9.02,
  longitude: 38.75,
  accuracy: 12,
  timestamp: Date.now(),
};

function pendingIn(minutes: number) {
  const now = Date.now();
  return {
    asked_at: new Date(now - (15 - minutes) * 60_000).toISOString(),
    expires_at: new Date(now + minutes * 60_000).toISOString(),
  };
}

const check = (over: Record<string, unknown> = {}) => ({
  public_id: "P1",
  status: "confirmed",
  asked_at: "2026-11-18T07:00:00Z",
  expires_at: "2026-11-18T07:15:00Z",
  answered_at: "2026-11-18T07:02:00Z",
  distance_meters: 20,
  late: false,
  ...over,
});

function setup(props: Partial<React.ComponentProps<typeof PresenceCard>> = {}) {
  const sign = vi.fn<() => Promise<{ proof?: DeviceProof; stop: boolean }>>(
    async () => ({ stop: false }),
  );
  const onLocate = vi.fn();
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const view = (p: Partial<React.ComponentProps<typeof PresenceCard>>) => (
    <QueryClientProvider client={client}>
      <PresenceCard
        pending={pendingIn(14)}
        position={fix}
        locating={false}
        problem={null}
        onLocate={onLocate}
        sign={sign}
        isOnline
        {...props}
        {...p}
      />
    </QueryClientProvider>
  );
  const utils = render(view({}));
  return {
    sign,
    onLocate,
    client,
    rerender: (p: Partial<React.ComponentProps<typeof PresenceCard>>) =>
      utils.rerender(view(p)),
  };
}

let posted: Record<string, unknown> | null;
function answerWith(status: number, body: unknown) {
  posted = null;
  server.use(
    http.post("*/api/v1/attendance/presence/confirm", async ({ request }) => {
      posted = (await request.json()) as Record<string, unknown>;
      return HttpResponse.json(body as object, { status });
    }),
  );
}

beforeEach(() => {
  localStorage.setItem("locale", "en");
  posted = null;
});
afterEach(() => vi.clearAllMocks());

describe("the presence card", () => {
  it("shows the time left and one button while a check is pending", () => {
    setup();

    expect(screen.getByText("Confirm you are at work")).toBeInTheDocument();
    expect(screen.getByRole("timer")).toHaveTextContent(/^1[34]:\d\d$/);
    expect(screen.getByRole("button", { name: "I am here" })).toBeEnabled();
  });

  it("counts down every second", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      setup({ pending: pendingIn(1) });
      const before = screen.getByRole("timer").textContent;
      await vi.advanceTimersByTimeAsync(3000);
      expect(screen.getByRole("timer").textContent).not.toBe(before);
    } finally {
      vi.useRealTimers();
    }
  });

  it("renders nothing visible when no check is pending", () => {
    const { container } = render(
      <QueryClientProvider client={new QueryClient()}>
        <PresenceCard
          pending={null}
          position={fix}
          locating={false}
          problem={null}
          onLocate={() => {}}
          sign={async () => ({ stop: false })}
          isOnline
        />
      </QueryClientProvider>,
    );
    expect(screen.queryByRole("button", { name: "I am here" })).toBeNull();
    // The live region stays mounted so the next check is announced.
    expect(container.querySelector("[aria-live='polite']")).not.toBeNull();
  });

  it("says so when there is no position, and offers to find it", async () => {
    const user = userEvent.setup();
    const { onLocate } = setup({ position: null });

    expect(
      screen.getByText("We need your location to confirm you are at work."),
    ).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "I am here" })).toBeDisabled();
    await user.click(screen.getByRole("button", { name: "Find my location" }));
    expect(onLocate).toHaveBeenCalledOnce();
  });

  it("says it cannot be confirmed offline and that a late answer is recorded", () => {
    setup({ isOnline: false });

    expect(screen.getByText(/cannot be confirmed now/)).toBeInTheDocument();
    expect(screen.getByText(/a late answer is still recorded/)).toBeVisible();
    expect(screen.getByRole("button", { name: "I am here" })).toBeDisabled();
  });

  it("sends the position and the phone's proof, and says confirmed", async () => {
    const user = userEvent.setup();
    const { sign } = setup();
    sign.mockResolvedValue({
      stop: false,
      proof: { id: "cred" } as unknown as DeviceProof,
    });
    answerWith(200, check());

    await user.click(screen.getByRole("button", { name: "I am here" }));

    expect(
      await screen.findByText("Confirmed. Thank you."),
    ).toBeInTheDocument();
    expect(posted).toMatchObject({
      latitude: 9.02,
      longitude: 38.75,
      accuracy: 12,
      device_proof: { id: "cred" },
    });
    expect(String(posted?.idempotency_key)).toMatch(/^presence-/);
  });

  it("says how far away an answer from outside was", async () => {
    const user = userEvent.setup();
    setup();
    answerWith(200, check({ status: "outside", distance_meters: 450 }));

    await user.click(screen.getByRole("button", { name: "I am here" }));

    expect(
      await screen.findByText(/You are 450 m from your workplace/),
    ).toBeInTheDocument();
  });

  it("says an unverified phone is recorded for review", async () => {
    const user = userEvent.setup();
    setup();
    answerWith(200, check({ status: "unverified", distance_meters: null }));

    await user.click(screen.getByRole("button", { name: "I am here" }));

    expect(
      await screen.findByText(/not from your registered phone/),
    ).toBeInTheDocument();
  });

  it("says a late answer is recorded as late", async () => {
    const user = userEvent.setup();
    setup();
    answerWith(200, check({ status: "missed", late: true }));

    await user.click(screen.getByRole("button", { name: "I am here" }));

    expect(
      await screen.findByText(/Answered after the time was up/),
    ).toBeInTheDocument();
  });

  it("shows the server's own message when it refuses, and allows another try", async () => {
    const user = userEvent.setup();
    setup();
    answerWith(403, {
      type: "https://ethr.et/errors/forbidden",
      title: "Forbidden",
      status: 403,
      detail: "This is not your registered phone.",
    });

    await user.click(screen.getByRole("button", { name: "I am here" }));

    expect(
      await screen.findByText("This is not your registered phone."),
    ).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "I am here" })).toBeEnabled();
  });

  it("goes quietly when there is nothing left to confirm", async () => {
    const user = userEvent.setup();
    setup();
    answerWith(404, {
      type: "https://ethr.et/errors/not-found",
      title: "Not Found",
      status: 404,
      detail: "There is no presence check to confirm.",
    });

    await user.click(screen.getByRole("button", { name: "I am here" }));

    expect(
      await screen.findByText(/There is nothing to confirm now/),
    ).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "I am here" })).toBeNull();
    expect(screen.queryByRole("alert")).toBeNull();
  });

  it("does not submit when the person cancels the passkey prompt", async () => {
    const user = userEvent.setup();
    const { sign } = setup();
    sign.mockResolvedValue({ stop: true });
    answerWith(200, check());

    await user.click(screen.getByRole("button", { name: "I am here" }));

    expect(
      await screen.findByText(/Unlock your phone when it asks/),
    ).toBeInTheDocument();
    await waitFor(() => expect(sign).toHaveBeenCalledOnce());
    expect(posted).toBeNull();
  });

  it("still lets a late answer be sent once the time is up", () => {
    setup({
      pending: {
        asked_at: new Date(Date.now() - 16 * 60_000).toISOString(),
        expires_at: new Date(Date.now() - 60_000).toISOString(),
      },
    });

    expect(screen.getByText(/it is recorded as late/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "I am here" })).toBeEnabled();
  });
});
