import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { InvitationProgress } from "@/features/users/components/invitation-progress";
import { invitationPollInterval } from "@/features/users/api";

/**
 * The progress card when an organisation's daily ceiling holds some
 * invitations for a later morning. Held ones are not "still to go today", so
 * the card says today's are sent and names the day the rest go out, in the
 * calm information style. Only real failures use the warning style.
 */

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const BATCH = "01JBQ8Z6ZK3M9V2X7Y4W5T6R8S";

const BASE = {
  batch: BATCH,
  total: 5,
  queued: 0,
  held: 3,
  held_until: "2026-11-20T05:00:00Z",
  daily_limit: 100,
  sent: 2,
  failed: 0,
  skipped: 0,
  failures: [] as unknown[],
};

function renderCard(body: Record<string, unknown>) {
  server.use(
    http.get(`*/api/v1/users/invitations/${BATCH}`, () =>
      HttpResponse.json({ ...BASE, ...body }),
    ),
  );
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <InvitationProgress batch={BATCH} />
    </QueryClientProvider>,
  );
}

describe("invitation progress with held invitations", () => {
  it("says today's are sent, how many are held, until when, and the limit", async () => {
    const { container } = renderCard({});

    expect(
      await screen.findByText("Today's invitations are sent"),
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        /^3 are held and go out over the next days, the last on \S.*\.$/,
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        "Your organisation can send 100 invitation emails a day, so sign-in emails keep reaching everyone.",
      ),
    ).toBeInTheDocument();
    expect(screen.getByText("2 of 5 sent")).toBeInTheDocument();
    expect(container.querySelector(".border-warning-edge")).toBeNull();
  });

  it("reads naturally for one held invitation and omits a missing limit", async () => {
    renderCard({ held: 1, daily_limit: null });

    expect(
      await screen.findByText(/^1 is held and goes out on \S.*\.$/),
    ).toBeInTheDocument();
    expect(
      screen.queryByText(/invitation emails a day/),
    ).not.toBeInTheDocument();
  });

  it("shows no held line when nothing is held", async () => {
    const { container } = renderCard({
      held: 0,
      held_until: null,
      total: 2,
    });

    expect(await screen.findByText("Invitations sent")).toBeInTheDocument();
    expect(screen.queryByText(/are held|is held/)).not.toBeInTheDocument();
    expect(
      screen.queryByText("Today's invitations are sent"),
    ).not.toBeInTheDocument();
    expect(container.querySelector(".border-warning-edge")).toBeNull();
  });

  it("keeps the old title while invitations are still going out today", async () => {
    renderCard({ queued: 2, held: 3 });

    expect(
      await screen.findByText("Invitations are being sent"),
    ).toBeInTheDocument();
    expect(screen.getByText(/^3 are held/)).toBeInTheDocument();
  });

  it("shows the failure warning and the held line together", async () => {
    const { container } = renderCard({
      sent: 1,
      failed: 1,
      failures: [{ user_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6R81" }],
    });

    expect(await screen.findByText("1 could not be sent")).toBeInTheDocument();
    expect(screen.getByText(/^3 are held/)).toBeInTheDocument();
    expect(container.querySelector(".border-warning-edge")).not.toBeNull();
    expect(
      screen.getByRole("button", { name: /Send them again/ }),
    ).toBeInTheDocument();
  });
});

describe("polling a batch", () => {
  it("keeps asking every 15 seconds while something is still to go today", () => {
    expect(invitationPollInterval(undefined)).toBe(15_000);
    expect(invitationPollInterval({ queued: 2 })).toBe(15_000);
  });

  it("stops once nothing is to go today, even with invitations held for later", () => {
    expect(invitationPollInterval({ queued: 0 })).toBe(false);
  });
});
