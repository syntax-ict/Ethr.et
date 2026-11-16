import { describe, it, expect, vi } from "vitest";
import { toast } from "sonner";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { MigrationListsInProgress } from "@/features/onboarding/v2/components/migration-lists-in-progress";
import type { MigrationListInProgress } from "@/features/onboarding/v2/types";

/**
 * The organisation's lists that are not finished, above the box a new one is
 * pasted into: found again, opened, or discarded.
 */

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function list(over: Partial<MigrationListInProgress>): MigrationListInProgress {
  return {
    public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6L01",
    status: "reviewing",
    mode: "add",
    source_type: "csv",
    rows: 12,
    created_at: new Date(Date.now() - 5 * 60_000).toISOString(),
    updated_at: new Date(Date.now() - 5 * 60_000).toISOString(),
    created_by_name: null,
    mine: true,
    ...over,
  };
}

const REVIEWING = list({});
const STAGING = list({
  public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6L02",
  status: "staging",
  rows: 1,
  mine: false,
  created_by_name: "Selam Bekele",
});
const COMMITTING = list({
  public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6L03",
  status: "committing",
  mode: "complete",
  rows: 40,
  mine: false,
  created_by_name: null,
});

function me(permissions: string[]) {
  return http.get("*/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6R80", role: "hr_admin" },
      permissions,
      tenant: null,
    }),
  );
}

function serves(lists: MigrationListInProgress[]) {
  const asked = { count: 0 };
  server.use(
    http.get("*/onboarding/migration/batches", () => {
      asked.count += 1;
      return HttpResponse.json({ data: lists });
    }),
  );
  return asked;
}

function renderCard(
  props: Partial<Parameters<typeof MigrationListsInProgress>[0]> = {},
) {
  const onOpen = vi.fn();
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  render(
    <QueryClientProvider client={client}>
      <MigrationListsInProgress onOpen={onOpen} {...props} />
    </QueryClientProvider>,
  );
  return { onOpen };
}

describe("Lists in progress", () => {
  it("names each list by what it is and where it stands, never who is on it", async () => {
    server.use(me(["employee.create"]));
    serves([REVIEWING, STAGING, COMMITTING]);
    renderCard();

    const rows = await screen.findAllByTestId("list-in-progress");
    expect(rows).toHaveLength(3);

    expect(within(rows[0]).getByText("Adding people")).toBeInTheDocument();
    expect(
      within(rows[0]).getByText("Waiting for your review"),
    ).toBeInTheDocument();
    expect(
      within(rows[0]).getByText(/Started 5 min ago by you/),
    ).toBeInTheDocument();
    expect(within(rows[0]).getByText(/12 lines/)).toBeInTheDocument();

    expect(within(rows[1]).getByText("Still being read")).toBeInTheDocument();
    expect(within(rows[1]).getByText(/by Selam Bekele/)).toBeInTheDocument();
    // One line, said as one.
    expect(within(rows[1]).getByText(/· 1 line$/)).toBeInTheDocument();

    expect(
      within(rows[2]).getByText("Completing people who are already here"),
    ).toBeInTheDocument();
    expect(within(rows[2]).getByText("Being added")).toBeInTheDocument();
    expect(within(rows[2]).getByText(/by someone else/)).toBeInTheDocument();
  });

  it("gives the full date as a title on when it was started", async () => {
    server.use(me(["employee.create"]));
    serves([REVIEWING]);
    renderCard();

    const row = await screen.findByTestId("list-in-progress");
    const when = within(row).getByText(/Started 5 min ago/);
    expect(when.getAttribute("title")).toBeTruthy();
  });

  it("shows nothing when there are no lists", async () => {
    server.use(me(["employee.create"]));
    const asked = serves([]);
    renderCard();

    await waitFor(() => expect(asked.count).toBe(1));
    expect(screen.queryByText("Lists in progress")).toBeNull();
  });

  it("opens a list by handing it to the step", async () => {
    server.use(me(["employee.create"]));
    serves([REVIEWING]);
    const { onOpen } = renderCard();

    const row = await screen.findByTestId("list-in-progress");
    await userEvent.click(within(row).getByRole("button", { name: "Open" }));

    expect(onOpen).toHaveBeenCalledWith(REVIEWING);
  });

  it("asks first, then discards, and shows the card as the server now has it", async () => {
    server.use(me(["employee.create"]));
    let lists = [REVIEWING, STAGING];
    let deleted: string | null = null;
    server.use(
      http.get("*/onboarding/migration/batches", () =>
        HttpResponse.json({ data: lists }),
      ),
      http.delete("*/onboarding/migration/batches/:id", ({ params }) => {
        deleted = String(params.id);
        lists = lists.filter((l) => l.public_id !== deleted);
        return new HttpResponse(null, { status: 204 });
      }),
    );
    renderCard();

    const rows = await screen.findAllByTestId("list-in-progress");
    await userEvent.click(
      within(rows[0]).getByRole("button", { name: "Discard" }),
    );

    expect(await screen.findByText("Discard this list?")).toBeInTheDocument();
    expect(
      screen.getByText(
        "The 12 lines on it are removed. Nobody on it has been added.",
      ),
    ).toBeInTheDocument();
    // Nothing is sent until it is confirmed.
    expect(deleted).toBeNull();

    const dialog = screen.getByRole("dialog");
    await userEvent.click(
      within(dialog).getByRole("button", { name: "Discard" }),
    );

    await waitFor(() => expect(deleted).toBe(REVIEWING.public_id));
    await waitFor(() =>
      expect(screen.getAllByTestId("list-in-progress")).toHaveLength(1),
    );
    expect(toast.success).toHaveBeenCalledWith("The list was discarded.");
  });

  it("says one line as one in the question", async () => {
    server.use(me(["employee.create"]));
    serves([STAGING]);
    renderCard();

    const row = await screen.findByTestId("list-in-progress");
    await userEvent.click(within(row).getByRole("button", { name: "Discard" }));

    expect(
      await screen.findByText(
        "The 1 line on it is removed. Nobody on it has been added.",
      ),
    ).toBeInTheDocument();
  });

  it("sends nothing when the question is answered no", async () => {
    server.use(me(["employee.create"]));
    serves([REVIEWING]);
    let sent = false;
    server.use(
      http.delete("*/onboarding/migration/batches/:id", () => {
        sent = true;
        return new HttpResponse(null, { status: 204 });
      }),
    );
    renderCard();

    const row = await screen.findByTestId("list-in-progress");
    await userEvent.click(within(row).getByRole("button", { name: "Discard" }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(
      within(dialog).getByRole("button", { name: "Cancel" }),
    );

    expect(sent).toBe(false);
    expect(screen.getByTestId("list-in-progress")).toBeInTheDocument();
  });

  it("says why when the server refuses, in its words", async () => {
    server.use(me(["employee.create"]));
    serves([REVIEWING]);
    server.use(
      http.delete("*/onboarding/migration/batches/:id", () =>
        HttpResponse.json(
          {
            type: "conflict",
            title: "Conflict",
            status: 409,
            detail: "This list is being added, so it cannot be discarded.",
          },
          { status: 409 },
        ),
      ),
    );
    renderCard();

    const row = await screen.findByTestId("list-in-progress");
    await userEvent.click(within(row).getByRole("button", { name: "Discard" }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(
      within(dialog).getByRole("button", { name: "Discard" }),
    );

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith(
        "This list is being added, so it cannot be discarded.",
      ),
    );
  });

  it("offers no way to discard a list that is being added", async () => {
    server.use(me(["employee.create"]));
    serves([COMMITTING]);
    renderCard();

    const row = await screen.findByTestId("list-in-progress");
    expect(
      within(row).getByRole("button", { name: "Open" }),
    ).toBeInTheDocument();
    expect(within(row).queryByRole("button", { name: "Discard" })).toBeNull();
  });

  it("offers no way to discard to someone who may not add employees", async () => {
    server.use(me(["employee.viewAny"]));
    serves([REVIEWING]);
    renderCard();

    const row = await screen.findByTestId("list-in-progress");
    await waitFor(() =>
      expect(
        within(row).getByRole("button", { name: "Open" }),
      ).toBeInTheDocument(),
    );
    expect(within(row).queryByRole("button", { name: "Discard" })).toBeNull();
  });

  it("says how far matching got on the list being opened", async () => {
    server.use(me(["employee.create"]));
    serves([STAGING]);
    renderCard({
      openingId: STAGING.public_id,
      openingText: "Matching people: 3 of 9",
    });

    expect(
      await screen.findByRole("button", { name: "Matching people: 3 of 9" }),
    ).toBeDisabled();
  });
});
