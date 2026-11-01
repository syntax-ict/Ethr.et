import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { MigrationStep } from "@/features/onboarding/v2/components/migration-step";
import type { MigrationBatch } from "@/features/onboarding/v2/types";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function renderWithClient(ui: React.ReactElement) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{ui}</QueryClientProvider>,
  );
}

const AMBIGUOUS_BATCH: MigrationBatch = {
  public_id: "01HZBATCH0000000000000001",
  source_type: "csv",
  source_ref: null,
  status: "reviewing",
  totals: null,
  summary: { ambiguous: 1 },
  rows: [
    {
      public_id: "01HZROW00000000000000001",
      display_name: "New Arrival",
      external_identifier: null,
      match_outcome: "ambiguous",
      match_confidence: 0.7,
      candidates: [
        {
          employee_public_id: "01HZEMP0000000000000001",
          employee_name: "Person A",
          score: 0.7,
          reasons: ["phone"],
        },
        {
          employee_public_id: "01HZEMP0000000000000002",
          employee_name: "Person B",
          score: 0.7,
          reasons: ["phone"],
        },
      ],
      resolved_employee_public_id: null,
      action: "defer",
      processed_at: null,
    },
  ],
};

describe("<MigrationStep>", () => {
  it("stages rows and surfaces candidate names, scores, and reasons for an ambiguous match", async () => {
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
      http.post("*/onboarding/migration/rows", () =>
        HttpResponse.json(AMBIGUOUS_BATCH, { status: 201 }),
      ),
      http.get("*/onboarding/migration/batches/:batchId", () =>
        HttpResponse.json(AMBIGUOUS_BATCH),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);

    const textarea = container.querySelector("textarea") as HTMLTextAreaElement;
    fireEvent.change(textarea, {
      target: { value: "New Arrival, , 0911000000" },
    });
    fireEvent.click(screen.getByText("Stage for review"));

    expect(await screen.findByText("Person A")).toBeInTheDocument();
    expect(screen.getByText("Person B")).toBeInTheDocument();
    expect(screen.getAllByText(/70%/).length).toBeGreaterThan(0);
    expect(screen.getAllByText(/phone/).length).toBeGreaterThan(0);
  });

  it("lets a reviewer pick a candidate to resolve an ambiguous row", async () => {
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
      http.post("*/onboarding/migration/rows", () =>
        HttpResponse.json(AMBIGUOUS_BATCH, { status: 201 }),
      ),
    );

    let patched: Record<string, unknown> | null = null;
    server.use(
      http.patch("*/onboarding/migration/rows/:rowId", async ({ request }) => {
        patched = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({
          ...AMBIGUOUS_BATCH.rows[0],
          resolved_employee_public_id: patched.employee_public_id,
          action: "merge",
        });
      }),
      http.get("*/onboarding/migration/batches/:batchId", () =>
        HttpResponse.json(AMBIGUOUS_BATCH),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);

    const textarea = container.querySelector("textarea") as HTMLTextAreaElement;
    fireEvent.change(textarea, { target: { value: "New Arrival" } });
    fireEvent.click(screen.getByText("Stage for review"));

    await screen.findByText("Person A");
    const useButtons = screen.getAllByText("Use");
    fireEvent.click(useButtons[0]);

    await waitFor(() => expect(patched).not.toBeNull());
    expect(patched).toMatchObject({
      action: "merge",
      employee_public_id: "01HZEMP0000000000000001",
    });
  });

  it("points to adding a device when there is none, and picks the only one", async () => {
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
    );
    const first = renderWithClient(<MigrationStep />);
    expect(await screen.findByText("Connect a device")).toHaveAttribute(
      "href",
      "/devices",
    );
    first.unmount();

    let staged = "";
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({
          data: [{ public_id: "01HZDEV1", name: "Gate terminal" }],
          meta: {},
          links: {},
        }),
      ),
      http.post("*/onboarding/migration/devices/:device", ({ params }) => {
        staged = String(params.device);
        return HttpResponse.json(AMBIGUOUS_BATCH, { status: 201 });
      }),
      http.get("*/onboarding/migration/batches/:batchId", () =>
        HttpResponse.json(AMBIGUOUS_BATCH),
      ),
    );
    renderWithClient(<MigrationStep />);
    const discover = await screen.findByText("Discover enrolled users");
    await waitFor(() => expect(discover.closest("button")).toBeEnabled());
    fireEvent.click(discover);
    await waitFor(() => expect(staged).toBe("01HZDEV1"));
  });

  it("shows who is ready and which lines need fixing, and stages nothing until they are fixed", async () => {
    let posted = false;
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
      http.post("*/onboarding/migration/rows", () => {
        posted = true;
        return HttpResponse.json(AMBIGUOUS_BATCH, { status: 201 });
      }),
    );

    const { container } = renderWithClient(<MigrationStep />);
    const textarea = container.querySelector("textarea") as HTMLTextAreaElement;
    fireEvent.change(textarea, {
      target: {
        value: [
          "Name\tHire date",
          "Abebe Kebede\t2019-07-08",
          "Hanna Girma\t08/07/2019",
        ].join("\n"),
      },
    });

    expect(screen.getByText(/1 person ready/)).toBeInTheDocument();
    expect(screen.getByText(/Columns: Name, Hire date/)).toBeInTheDocument();
    expect(
      screen.getByText(
        /Line 3: write the hire date 08\/07\/2019 as YYYY-MM-DD/,
      ),
    ).toBeInTheDocument();
    const stage = screen.getByText("Stage for review").closest("button")!;
    expect(stage).toBeDisabled();
    fireEvent.click(stage);
    expect(posted).toBe(false);
  });

  it("reads a chosen CSV file into the list", async () => {
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);
    const input = screen.getByTestId("people-file") as HTMLInputElement;
    const file = new File(
      ["Name,Email\nAbebe Kebede,abebe@acme.et"],
      "staff.csv",
      {
        type: "text/csv",
      },
    );
    fireEvent.change(input, { target: { files: [file] } });

    await waitFor(() =>
      expect(
        (container.querySelector("textarea") as HTMLTextAreaElement).value,
      ).toContain("Abebe Kebede"),
    );
    expect(screen.getByText(/1 person ready/)).toBeInTheDocument();
  });
});
