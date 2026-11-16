import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { MigrationTerminals } from "@/features/onboarding/v2/components/migration-terminals";
import { readWorkbook } from "@/features/onboarding/v2/xlsx-grid";
import type {
  MigrationTerminalDevice,
  MigrationTerminals as Terminals,
} from "@/features/onboarding/v2/types";

/**
 * After a list is added: what the terminals hold for the people it brought
 * in, what can be done about a refusal, and who to enrol by hand at a
 * terminal ETHR cannot write to.
 */

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const saved: { name: string; blob: Blob }[] = [];
vi.mock("@/lib/utils/csv-export", () => ({
  saveBlob: (name: string, blob: Blob) => saved.push({ name, blob }),
  saveCsv: vi.fn(),
}));

const BATCH = "01JBQ8Z6ZK3M9V2X7Y4W5T6R8S";
const DEVICE = "01JBQ8Z6ZK3M9V2X7Y4W5T6D01";

function renderPanel() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <MigrationTerminals batchId={BATCH} />
    </QueryClientProvider>,
  );
}

function me(permissions: string[]) {
  return http.get("*/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6R80", role: "tenant_admin" },
      permissions,
      tenant: null,
    }),
  );
}

function device(
  over: Partial<MigrationTerminalDevice> = {},
): MigrationTerminalDevice {
  return {
    device_public_id: DEVICE,
    device_name: "Front gate",
    adapter_type: "zkteco_adms",
    writable: true,
    receives_only: false,
    queued: 0,
    done: 0,
    failed: 0,
    not_sent: 0,
    last_error: null,
    enrol_by_hand: [],
    ...over,
  };
}

function terminals(over: Partial<Terminals> = {}): Terminals {
  return {
    visible: true,
    devices: [device()],
    without_number: 0,
    without_branch: 0,
    ...over,
  };
}

function answer(body: Terminals) {
  return http.get(`*/onboarding/migration/batches/${BATCH}/terminals`, () =>
    HttpResponse.json(body),
  );
}

describe("<MigrationTerminals>", () => {
  it("shows nothing to someone who may not see devices", async () => {
    let asked = false;
    server.use(
      me([]),
      http.get(`*/onboarding/migration/batches/${BATCH}/terminals`, () => {
        asked = true;
        return HttpResponse.json(terminals({ visible: false, devices: [] }));
      }),
    );
    const { container } = renderPanel();

    await waitFor(() => expect(asked).toBe(true));
    await new Promise((r) => setTimeout(r, 50));
    expect(container).toBeEmptyDOMElement();
  });

  it("shows nothing when there are no terminals and nobody is left out", async () => {
    server.use(me([]), answer(terminals({ devices: [] })));
    const { container } = renderPanel();

    await new Promise((r) => setTimeout(r, 100));
    expect(container).toBeEmptyDOMElement();
  });

  it("says, for each terminal, how many were sent or are waiting", async () => {
    server.use(
      me([]),
      answer(
        terminals({
          devices: [
            device({ queued: 2, done: 3 }),
            device({
              device_public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6D02",
              device_name: "Back door",
              queued: 0,
              done: 1,
            }),
          ],
        }),
      ),
    );
    renderPanel();

    expect(await screen.findByText("Front gate")).toBeInTheDocument();
    expect(screen.getByText("5 sent or waiting")).toBeInTheDocument();
    expect(screen.getByText("Back door")).toBeInTheDocument();
    expect(screen.getByText("1 sent or waiting")).toBeInTheDocument();
  });

  it("offers Try again for the people a terminal refused, sends this list only, and reads the section again", async () => {
    let posted: unknown = null;
    let reads = 0;
    server.use(
      me(["device.update", "device.viewAny"]),
      http.get(`*/onboarding/migration/batches/${BATCH}/terminals`, () => {
        reads++;
        return HttpResponse.json(
          reads === 1
            ? terminals({
                devices: [
                  device({
                    failed: 2,
                    done: 1,
                    last_error: "The device answered with error 4.",
                  }),
                ],
              })
            : terminals({ devices: [device({ queued: 2, done: 1 })] }),
        );
      }),
      http.post(`*/devices/${DEVICE}/commands/retry`, async ({ request }) => {
        posted = await request.json();
        return HttpResponse.json({ queued: 2, skipped: 0 }, { status: 202 });
      }),
    );
    renderPanel();

    expect(
      await screen.findByText("2 not accepted by the terminal."),
    ).toBeInTheDocument();
    expect(
      screen.getByText("Its last answer: The device answered with error 4."),
    ).toBeInTheDocument();

    await userEvent.click(
      await screen.findByRole("button", { name: "Try again on Front gate" }),
    );

    await waitFor(() => expect(posted).toEqual({ batch_public_id: BATCH }));
    expect(await screen.findByText("3 sent or waiting")).toBeInTheDocument();
    expect(screen.queryByText(/not accepted/)).not.toBeInTheDocument();
    expect(reads).toBeGreaterThanOrEqual(2);
  });

  it("does not offer Try again to someone who may not update devices", async () => {
    server.use(
      me(["device.viewAny"]),
      answer(terminals({ devices: [device({ failed: 1 })] })),
    );
    renderPanel();

    expect(
      await screen.findByText("1 not accepted by the terminal."),
    ).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Try again/ })).toBeNull();
  });

  it("says when a number is not one the terminal takes, with a way to the employee list", async () => {
    server.use(
      me([]),
      answer(terminals({ devices: [device({ not_sent: 3, done: 1 })] })),
    );
    renderPanel();

    expect(
      await screen.findByText(
        /3 not sent\. Their number is not one this terminal takes\./,
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "Open the employee list" }),
    ).toHaveAttribute("href", "/employees");
  });

  it("lists who to enrol by hand at a terminal that only sends, and downloads them with their numbers", async () => {
    saved.length = 0;
    server.use(
      me([]),
      answer(
        terminals({
          devices: [
            device({
              device_name: "Hikvision gate",
              adapter_type: "hikvision",
              writable: false,
              receives_only: true,
              enrol_by_hand: [
                {
                  employee_public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6E01",
                  name: "Abebe Kebede",
                  device_user_id: "0042",
                },
                {
                  employee_public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6E02",
                  name: "Hanna Girma",
                  device_user_id: "H-7",
                },
              ],
            }),
          ],
        }),
      ),
    );
    renderPanel();

    expect(
      await screen.findByText(
        "This terminal only sends. Enrol these 2 people at the terminal with these numbers.",
      ),
    ).toBeInTheDocument();
    expect(screen.queryByText(/sent or waiting/)).toBeNull();

    await userEvent.click(
      screen.getByRole("button", {
        name: "Download the list for Hikvision gate",
      }),
    );

    await waitFor(() => expect(saved).toHaveLength(1), { timeout: 10_000 });
    expect(saved[0].name).toBe("ethr-terminal-enrolment.xlsx");
    expect(await readWorkbook(await saved[0].blob.arrayBuffer())).toEqual([
      ["Number", "Name"],
      ["0042", "Abebe Kebede"],
      ["H-7", "Hanna Girma"],
    ]);
  });

  it("says in plain words when commands are off for a terminal that could take them", async () => {
    server.use(
      me([]),
      answer(
        terminals({
          devices: [
            device({
              writable: false,
              enrol_by_hand: [
                {
                  employee_public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6E01",
                  name: "Abebe Kebede",
                  device_user_id: "42",
                },
              ],
            }),
          ],
        }),
      ),
    );
    renderPanel();

    expect(
      await screen.findByText(
        "ETHR does not send people to this terminal. Enrol these 1 people at the terminal with these numbers.",
      ),
    ).toBeInTheDocument();
  });

  it("says how many people will not reach a terminal, for want of a number or a branch", async () => {
    server.use(
      me([]),
      answer(terminals({ devices: [], without_number: 4, without_branch: 2 })),
    );
    renderPanel();

    expect(
      await screen.findByText(
        /4 people have no badge number, so they will not reach a terminal\./,
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        /2 people have no branch, so they will not reach a terminal\./,
      ),
    ).toBeInTheDocument();
    expect(
      screen.getAllByRole("link", { name: "Open the employee list" }),
    ).toHaveLength(2);
  });
});
