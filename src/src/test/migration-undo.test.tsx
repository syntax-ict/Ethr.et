import { describe, it, expect, vi, beforeEach } from "vitest";
import { toast } from "sonner";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import {
  MigrationUndoButton,
  MigrationUndoNote,
} from "@/features/onboarding/v2/components/migration-undo";
import type {
  MigrationBatch,
  UndoPreview,
} from "@/features/onboarding/v2/types";
import { RouteLocaleProvider } from "@/lib/i18n/route-locale";
import { preloadLocale } from "@/lib/i18n/translations";

/**
 * Undo this list's additions: a preview of who would be archived and who is
 * kept, then the same request sent again until the server says it is done.
 */

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const BATCH_ID = "01JBQ8Z6ZK3M9V2X7Y4W5T6U01";
const UNDO_URL = `*/onboarding/migration/batches/${BATCH_ID}/undo`;

type BatchProps = Pick<
  MigrationBatch,
  "status" | "mode" | "undone_at" | "undo_archived"
>;

const COMMITTED: BatchProps = {
  status: "committed",
  mode: "add",
  undone_at: null,
  undo_archived: 0,
};

function preview(over: Partial<UndoPreview> = {}): UndoPreview {
  return {
    origin: "recorded",
    exact: true,
    added: 5,
    already_archived: 0,
    matched: 3,
    archive_count: 3,
    archive: [
      {
        employee_public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6E01",
        name: "Abebe Kebede",
        employee_code: "EMP-001",
      },
      {
        employee_public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6E02",
        name: "Selam Bekele",
        employee_code: null,
      },
      {
        employee_public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6E03",
        name: "Tigist Haile",
        employee_code: "EMP-003",
      },
    ],
    keep_count: 2,
    keep: [
      {
        employee_public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6E04",
        name: "Dawit Alemu",
        employee_code: "EMP-004",
        reasons: ["attendance", "payroll"],
      },
      {
        employee_public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6E05",
        name: "Meron Tadesse",
        employee_code: "EMP-005",
        reasons: ["something_new"],
      },
    ],
    out_of_reach: 0,
    undone_at: null,
    ...over,
  };
}

function me(permissions: string[]) {
  return http.get("*/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6R80", role: "hr_admin" },
      permissions,
      tenant: null,
    }),
  );
}

function servesPreview(p: UndoPreview) {
  const asked = { count: 0 };
  server.use(
    http.get(UNDO_URL, () => {
      asked.count += 1;
      return HttpResponse.json(p);
    }),
  );
  return asked;
}

function renderButton(batch: BatchProps = COMMITTED, locale?: string) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const tree = (
    <QueryClientProvider client={client}>
      <MigrationUndoNote batch={batch} />
      <MigrationUndoButton batchId={BATCH_ID} batch={batch} />
    </QueryClientProvider>
  );
  return render(
    locale ? (
      <RouteLocaleProvider locale={locale}>{tree}</RouteLocaleProvider>
    ) : (
      tree
    ),
  );
}

const BUTTON = { name: "Undo this list's additions" };

beforeEach(() => {
  vi.mocked(toast.success).mockClear();
  vi.mocked(toast.error).mockClear();
});

describe("The undo button", () => {
  it("is offered to someone who may delete employees, on a committed list that adds", async () => {
    server.use(me(["employee.delete"]));
    renderButton();

    expect(await screen.findByRole("button", BUTTON)).toBeInTheDocument();
  });

  it("is absent without the permission to delete employees", async () => {
    let asked = false;
    server.use(
      http.get("*/auth/me", () => {
        asked = true;
        return HttpResponse.json({
          user: { public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6R80", role: "hr_admin" },
          permissions: ["employee.create"],
          tenant: null,
        });
      }),
    );
    renderButton();

    await waitFor(() => expect(asked).toBe(true));
    await new Promise((r) => setTimeout(r, 30));
    expect(screen.queryByRole("button", BUTTON)).toBeNull();
  });

  it("is absent for a list that only completes people already here", async () => {
    server.use(me(["employee.delete"]));
    renderButton({ ...COMMITTED, mode: "complete" });

    await new Promise((r) => setTimeout(r, 30));
    expect(screen.queryByRole("button", BUTTON)).toBeNull();
  });

  it("is absent until the list is committed", async () => {
    server.use(me(["employee.delete"]));
    renderButton({ ...COMMITTED, status: "reviewing" });

    await new Promise((r) => setTimeout(r, 30));
    expect(screen.queryByRole("button", BUTTON)).toBeNull();
  });

  it("says when the additions were undone, and how many people were archived", async () => {
    server.use(me(["employee.delete"]));
    renderButton({
      ...COMMITTED,
      undone_at: "2026-11-02T09:00:00Z",
      undo_archived: 4,
    });

    expect(
      await screen.findByText(
        /This list's additions were undone on .+\. 4 people were archived\./,
      ),
    ).toBeInTheDocument();
  });

  it("says one person as one", async () => {
    server.use(me(["employee.delete"]));
    renderButton({
      ...COMMITTED,
      undone_at: "2026-11-02T09:00:00Z",
      undo_archived: 1,
    });

    expect(
      await screen.findByText(/1 person was archived\./),
    ).toBeInTheDocument();
  });
});

describe("The undo preview", () => {
  it("sends nothing before the person has looked, and nothing until they confirm", async () => {
    server.use(me(["employee.delete"]));
    const asked = servesPreview(preview());
    let posted = 0;
    server.use(
      http.post(UNDO_URL, () => {
        posted += 1;
        return HttpResponse.json({
          done: true,
          archived: 3,
          remaining: 0,
          kept: 2,
          out_of_reach: 0,
        });
      }),
    );
    renderButton();

    await userEvent.click(await screen.findByRole("button", BUTTON));
    await screen.findByText("Will be archived");
    expect(asked.count).toBe(1);
    expect(posted).toBe(0);
  });

  it("shows the counts, the people to archive, the people kept and why", async () => {
    server.use(me(["employee.delete"]));
    servesPreview(preview());
    renderButton();

    await userEvent.click(await screen.findByRole("button", BUTTON));
    const dialog = await screen.findByRole("dialog");

    expect(await within(dialog).findByText("Abebe Kebede")).toBeVisible();
    expect(within(dialog).getByText("EMP-001")).toBeInTheDocument();
    expect(within(dialog).getByText("Selam Bekele")).toBeInTheDocument();
    expect(within(dialog).getByText("Tigist Haile")).toBeInTheDocument();

    // Tiles: added 5, archived 3, kept 2 (2 listed, none out of reach).
    const tiles = within(dialog).getByTestId("undo-tiles");
    expect(within(tiles).getByText("Added by this list")).toBeInTheDocument();
    expect(within(tiles).getByText("5")).toBeInTheDocument();
    expect(within(tiles).getByText("3")).toBeInTheDocument();
    expect(within(tiles).getByText("2")).toBeInTheDocument();
    expect(within(tiles).queryByText("Already archived")).toBeNull();

    // Reasons are words, not codes; an unknown code is a generic label.
    expect(within(dialog).getByText("Dawit Alemu")).toBeInTheDocument();
    expect(
      within(dialog).getByText("Has attendance records, Has been in a payroll"),
    ).toBeInTheDocument();
    expect(within(dialog).getByText("Has been used")).toBeInTheDocument();
    expect(within(dialog).queryByText(/something_new|attendance,/)).toBeNull();

    expect(
      within(dialog).getByText(
        "People this list only matched or updated are not touched: 3.",
      ),
    ).toBeInTheDocument();
    expect(
      within(dialog).getByRole("button", { name: "Archive 3 people" }),
    ).toBeEnabled();
  });

  it("shows Already archived only when someone is", async () => {
    server.use(me(["employee.delete"]));
    servesPreview(preview({ already_archived: 2 }));
    renderButton();

    await userEvent.click(await screen.findByRole("button", BUTTON));
    expect(await screen.findByText("Already archived")).toBeInTheDocument();
  });

  it("counts the people outside what you may archive with the kept, and says so", async () => {
    server.use(me(["employee.delete"]));
    servesPreview(preview({ out_of_reach: 4 }));
    renderButton();

    await userEvent.click(await screen.findByRole("button", BUTTON));
    expect(
      await screen.findByText(
        "4 people are outside the people you may archive. They are kept.",
      ),
    ).toBeInTheDocument();
    const tiles = screen.getByTestId("undo-tiles");
    // keep_count 2 plus out_of_reach 4.
    expect(within(tiles).getByText("6")).toBeInTheDocument();
  });

  it("says how many more there are than the rows shown", async () => {
    server.use(me(["employee.delete"]));
    servesPreview(preview({ archive_count: 230 }));
    renderButton();

    await userEvent.click(await screen.findByRole("button", BUTTON));
    expect(await screen.findByText("and 227 more")).toBeInTheDocument();
  });

  it("notes a list that was added before ETHR recorded who it added", async () => {
    server.use(me(["employee.delete"]));
    servesPreview(preview({ origin: "derived" }));
    renderButton();

    await userEvent.click(await screen.findByRole("button", BUTTON));
    expect(
      await screen.findByText(
        "This list was added before ETHR recorded which people a list added, so they were worked out from its records.",
      ),
    ).toBeInTheDocument();
  });

  it("archives nobody when the records disagree, and says so", async () => {
    server.use(me(["employee.delete"]));
    servesPreview(
      preview({
        origin: "derived",
        exact: false,
        archive_count: 0,
        archive: [],
      }),
    );
    renderButton();

    await userEvent.click(await screen.findByRole("button", BUTTON));
    expect(
      await screen.findByText(
        "The records of this list do not agree on who it added, so nobody is archived. Each person is listed below.",
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByText("Nobody this list added can be archived."),
    ).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /^Archive/ })).toBeDisabled();
  });

  it("shows the error with a way to try again", async () => {
    server.use(me(["employee.delete"]));
    let calls = 0;
    server.use(
      http.get(UNDO_URL, () => {
        calls += 1;
        return calls === 1
          ? HttpResponse.json(
              { type: "x", title: "Server", status: 500, detail: "Broke." },
              { status: 500 },
            )
          : HttpResponse.json(preview());
      }),
    );
    renderButton();

    await userEvent.click(await screen.findByRole("button", BUTTON));
    await userEvent.click(
      await screen.findByRole("button", { name: "Try again" }),
    );
    expect(await screen.findByText("Abebe Kebede")).toBeInTheDocument();
  });

  it("asks again on every opening, and never offers an old count", async () => {
    server.use(me(["employee.delete"]));
    let calls = 0;
    server.use(
      http.get(UNDO_URL, () => {
        calls += 1;
        return HttpResponse.json(
          calls === 1
            ? preview()
            : preview({
                archive_count: 1,
                archive: [preview().archive[0]],
              }),
        );
      }),
    );
    renderButton();

    await userEvent.click(await screen.findByRole("button", BUTTON));
    expect(
      await screen.findByRole("button", { name: "Archive 3 people" }),
    ).toBeEnabled();
    await userEvent.click(screen.getByRole("button", { name: "Cancel" }));
    await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());

    await userEvent.click(screen.getByRole("button", BUTTON));
    expect(
      await screen.findByRole("button", { name: "Archive 1 person" }),
    ).toBeEnabled();
    expect(
      screen.queryByRole("button", { name: "Archive 3 people" }),
    ).toBeNull();
    expect(calls).toBe(2);
  });

  it("will not archive anyone while the records disagree, whatever the count says", async () => {
    server.use(me(["employee.delete"]));
    servesPreview(preview({ exact: false }));
    renderButton();

    await userEvent.click(await screen.findByRole("button", BUTTON));
    expect(
      await screen.findByRole("button", { name: "Archive 3 people" }),
    ).toBeDisabled();
  });
});

describe("Archiving", () => {
  it("sends the confirmation, repeats while the server is not done, then says so and closes", async () => {
    server.use(me(["employee.delete"]));
    servesPreview(preview());
    const bodies: unknown[] = [];
    const answers = [
      { done: false, archived: 2, remaining: 1, kept: 2, out_of_reach: 0 },
      { done: true, archived: 3, remaining: 0, kept: 2, out_of_reach: 0 },
    ];
    server.use(
      http.post(UNDO_URL, async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json(answers[bodies.length - 1]);
      }),
    );
    renderButton();

    await userEvent.click(await screen.findByRole("button", BUTTON));
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(
      await within(dialog).findByRole("button", { name: "Archive 3 people" }),
    );

    await waitFor(() => expect(bodies).toHaveLength(2));
    expect(bodies).toEqual([{ confirm: true }, { confirm: true }]);
    await waitFor(() =>
      expect(toast.success).toHaveBeenCalledWith(
        "3 people were archived. They are archived, not deleted.",
      ),
    );
    await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
  });

  it("says one person as one", async () => {
    server.use(me(["employee.delete"]));
    servesPreview(
      preview({
        archive_count: 1,
        archive: [preview().archive[0]],
      }),
    );
    server.use(
      http.post(UNDO_URL, () =>
        HttpResponse.json({
          done: true,
          archived: 1,
          remaining: 0,
          kept: 2,
          out_of_reach: 0,
        }),
      ),
    );
    renderButton();

    await userEvent.click(await screen.findByRole("button", BUTTON));
    await userEvent.click(
      await screen.findByRole("button", { name: "Archive 1 person" }),
    );

    await waitFor(() =>
      expect(toast.success).toHaveBeenCalledWith(
        "1 person was archived. They are archived, not deleted.",
      ),
    );
  });

  it("shows the server's words when it refuses, and keeps the dialog open", async () => {
    server.use(me(["employee.delete"]));
    servesPreview(preview());
    server.use(
      http.post(UNDO_URL, () =>
        HttpResponse.json(
          {
            type: "conflict",
            title: "Conflict",
            status: 409,
            detail:
              "This list has not been added yet, so there is nothing to undo.",
          },
          { status: 409 },
        ),
      ),
    );
    renderButton();

    await userEvent.click(await screen.findByRole("button", BUTTON));
    await userEvent.click(
      await screen.findByRole("button", { name: "Archive 3 people" }),
    );

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith(
        "This list has not been added yet, so there is nothing to undo.",
      ),
    );
    expect(screen.getByRole("dialog")).toBeInTheDocument();
  });

  it("stops when the server keeps answering without the list getting shorter", async () => {
    server.use(me(["employee.delete"]));
    servesPreview(preview());
    let posts = 0;
    server.use(
      http.post(UNDO_URL, () => {
        posts += 1;
        return HttpResponse.json({
          done: false,
          archived: 0,
          remaining: 3,
          kept: 2,
          out_of_reach: 0,
        });
      }),
    );
    renderButton();

    await userEvent.click(await screen.findByRole("button", BUTTON));
    await userEvent.click(
      await screen.findByRole("button", { name: "Archive 3 people" }),
    );

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith(
        "The undo stopped moving. Some people may already be archived. Try again.",
      ),
    );
    // The first answer, then two that did not go down: three requests.
    expect(posts).toBe(3);
    expect(toast.success).not.toHaveBeenCalled();
    expect(screen.getByRole("dialog")).toBeInTheDocument();
  });
});

describe("In Amharic", () => {
  it("reads the button, the tiles and the reasons in Amharic", async () => {
    await preloadLocale("am");
    server.use(me(["employee.delete"]));
    servesPreview(preview());
    renderButton(COMMITTED, "am");

    const button = await screen.findByRole("button", {
      name: "የዚህን ዝርዝር ጭማሪ ይቀልብሱ",
    });
    await userEvent.click(button);
    const dialog = await screen.findByRole("dialog");
    expect(await within(dialog).findByText("Abebe Kebede")).toBeInTheDocument();
    expect(within(dialog).getByText("ወደ ማህደር የሚወሰዱ")).toBeInTheDocument();
    expect(within(dialog).queryByText("Will be archived")).toBeNull();
  });
});
