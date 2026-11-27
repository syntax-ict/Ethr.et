import { describe, it, expect } from "vitest";
import { act, renderHook, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { useBringInPunches } from "@/features/devices/bring-in";

/**
 * A badge the server holds back for unread warnings (422 with `warnings`) is
 * left for the Devices page, and the run goes on to the next badge.
 */

const jobs = ["1111", "2222", "3333"].map((badge) => ({
  device_public_id: "01HZDEV0000000000000000001",
  badge,
  employee_public_id: "01HZEMP0000000000000000001",
  punches: 1,
}));

function setup() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return renderHook(() => useBringInPunches(), { wrapper });
}

describe("useBringInPunches", () => {
  it("leaves a badge with unread warnings for a check and carries on to the next", async () => {
    const badges: string[] = [];
    server.use(
      http.post("*/devices/unmatched-punches/assign", async ({ request }) => {
        const body = (await request.json()) as { badge: string };
        badges.push(body.badge);
        if (body.badge === "2222") {
          return HttpResponse.json(
            {
              message: "Confirm that this is the right person.",
              errors: { accept_warnings: ["Confirm."] },
              warnings: ["other_badge"],
            },
            { status: 422 },
          );
        }
        return HttpResponse.json({ replayed: 1, refused: 0, remaining: 0 });
      }),
    );
    const { result } = setup();

    act(() => result.current.start(jobs));

    await waitFor(() => expect(result.current.state.phase).toBe("done"));
    expect(badges).toEqual(["1111", "2222", "3333"]);
    expect(result.current.state.replayed).toBe(2);
    expect(result.current.state.needsCheck).toBe(1);
  });

  it("does not resend a badge that was sent with the warnings accepted", async () => {
    let calls = 0;
    server.use(
      http.post("*/devices/unmatched-punches/assign", () => {
        calls += 1;
        return HttpResponse.json(
          { message: "Changed.", warnings: ["hired_after"] },
          { status: 422 },
        );
      }),
    );
    const { result } = setup();

    act(() => result.current.start([{ ...jobs[0]!, acceptWarnings: true }]));

    await waitFor(() => expect(result.current.state.phase).toBe("done"));
    expect(calls).toBe(1);
    expect(result.current.state.needsCheck).toBe(1);
  });

  it("still ends failed on any other error, and a new start resets the count", async () => {
    server.use(
      http.post("*/devices/unmatched-punches/assign", () =>
        HttpResponse.json({ detail: "Broken." }, { status: 500 }),
      ),
    );
    const { result } = setup();

    act(() => result.current.start(jobs));

    await waitFor(() => expect(result.current.state.phase).toBe("failed"));
    expect(result.current.state.needsCheck).toBe(0);
    expect(result.current.state.error).toBe("Broken.");
  });
});
