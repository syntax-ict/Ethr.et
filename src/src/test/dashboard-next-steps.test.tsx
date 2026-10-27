import { describe, it, expect } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { NextStepsCard } from "@/features/next-steps/components/next-steps-card";
import type { NextStep, NextStepsResponse } from "@/features/next-steps/api";

/**
 * The real usePermissions runs against what /auth/me says. The list itself is
 * built by the server, so these tests hand the card a response and check what
 * it draws, what it sends when a row is put away, and that it asks for
 * nothing a platform super admin has no use for.
 */
function me(role: string, permissions: string[] = []) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "01HZUSER", name: "Test", role },
        tenant: { public_id: "T1", name: "Demo" },
        permissions,
      }),
    ),
  );
}

function step(over: Partial<NextStep> & Pick<NextStep, "id">): NextStep {
  return {
    group: "operations",
    severity: "warning",
    count: 1,
    total: null,
    items: null,
    subject: null,
    href: "/",
    signature: String(over.count ?? 1),
    ...over,
  };
}

/** Serve a list that behaves like the server: a dismissed step leaves it. */
function serve(steps: NextStep[], setup: NextStepsResponse["setup"] = null) {
  let current = steps;
  const dismissed: { id: string; body: unknown }[] = [];
  let restored = 0;
  server.use(
    http.get("*/api/v1/next-steps", () =>
      HttpResponse.json({ steps: current, setup }),
    ),
    http.post(
      "*/api/v1/next-steps/:id/dismiss",
      async ({ request, params }) => {
        dismissed.push({ id: String(params.id), body: await request.json() });
        current = current.filter((s) => s.id !== params.id);
        return new HttpResponse(null, { status: 204 });
      },
    ),
    http.delete("*/api/v1/next-steps/dismissals", () => {
      restored += 1;
      current = steps;
      return new HttpResponse(null, { status: 204 });
    }),
  );
  return { dismissed, restored: () => restored };
}

function renderCard() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <NextStepsCard />
    </QueryClientProvider>,
  );
}

/** The rows of the main list, not the checklist links inside one. */
function rows() {
  return Array.from(screen.getAllByRole("list")[0].children);
}

describe("<NextStepsCard>", () => {
  it("asks for nothing and draws nothing for a platform super admin", async () => {
    const hits: string[] = [];
    server.use(
      http.get("*/api/v1/next-steps", ({ request }) => {
        hits.push(request.url);
        return HttpResponse.json({ steps: [], setup: null });
      }),
    );
    me("super_admin");
    const { container } = renderCard();

    await new Promise((r) => setTimeout(r, 200));
    expect(container).toBeEmptyDOMElement();
    expect(hits).toEqual([]);
  });

  it("draws nothing when the server has nothing for this person", async () => {
    me("employee");
    serve([]);
    const { container } = renderCard();

    await new Promise((r) => setTimeout(r, 200));
    expect(container).toBeEmptyDOMElement();
  });

  it("draws nothing, and no error, when the list cannot be loaded", async () => {
    me("employee");
    server.use(
      http.get("*/api/v1/next-steps", () =>
        HttpResponse.json({ title: "Server Error" }, { status: 500 }),
      ),
    );
    const { container } = renderCard();

    await new Promise((r) => setTimeout(r, 200));
    expect(container).toBeEmptyDOMElement();
  });

  it("tells an approver how many requests wait, why, and where to decide", async () => {
    me("supervisor");
    serve([
      step({
        id: "approvals",
        group: "approvals",
        count: 3,
        href: "/approvals",
      }),
      step({
        id: "team_absent",
        severity: "info",
        count: 1,
        href: "/attendance/team",
      }),
    ]);
    renderCard();

    expect(
      await screen.findByText("3 requests are waiting for your decision"),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/from the people you approve for/i),
    ).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /decide/i })).toHaveAttribute(
      "href",
      "/approvals",
    );
    expect(
      screen.getByText("1 of your team is absent today"),
    ).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /see team/i })).toHaveAttribute(
      "href",
      "/attendance/team",
    );
  });

  it("shows an employee how far their profile is, and each part still missing", async () => {
    me("employee");
    serve([
      step({
        id: "complete_profile",
        group: "profile",
        severity: "info",
        count: 4,
        total: 6,
        items: ["photo", "emergency_contact", "tin", "bank"],
        href: "/profile/personal",
      }),
    ]);
    renderCard();

    expect(await screen.findByText("Finish your profile")).toBeInTheDocument();
    expect(screen.getByText(/2 of 6 done/)).toBeInTheDocument();
    expect(
      screen.getByRole("progressbar", { name: "Finish your profile" }),
    ).toHaveAttribute("aria-valuenow", "33");
    expect(screen.getByRole("link", { name: "Add a photo" })).toHaveAttribute(
      "href",
      "/profile",
    );
    expect(
      screen.getByRole("link", { name: "Add your bank account" }),
    ).toHaveAttribute("href", "/profile/personal");
    expect(
      screen.queryByRole("link", { name: "Add your phone number" }),
    ).toBeNull();
    expect(
      screen.getByRole("link", { name: "Update profile" }),
    ).toHaveAttribute("href", "/profile/personal");
  });

  it("tells HR which records are incomplete, in the singular too, and opens the filtered list", async () => {
    me("hr_admin");
    serve([
      step({
        id: "records_missing_tin",
        group: "records",
        count: 1,
        href: "/employees?missing=tin",
      }),
      step({
        id: "records_missing_phone",
        group: "records",
        severity: "info",
        count: 3,
        href: "/employees?missing=phone",
      }),
    ]);
    renderCard();

    expect(
      await screen.findByText("1 employee has no TIN"),
    ).toBeInTheDocument();
    expect(
      screen.getByText("3 employees have no phone number"),
    ).toBeInTheDocument();
    const links = screen.getAllByRole("link", { name: "Review" });
    expect(links.map((a) => a.getAttribute("href"))).toEqual([
      "/employees?missing=tin",
      "/employees?missing=phone",
    ]);
  });

  it("names the payroll period and where to open the run", async () => {
    me("finance_admin");
    serve([
      step({
        id: "payroll_approve",
        subject: "Tikimt 2019",
        href: "/payroll/01HZRUN",
        signature: "01HZRUN:completed",
      }),
    ]);
    renderCard();

    expect(
      await screen.findByText(
        "The Tikimt 2019 payroll run is waiting for approval",
      ),
    ).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /open run/i })).toHaveAttribute(
      "href",
      "/payroll/01HZRUN",
    );
  });

  it("shows three rows, then the rest when asked, then three again", async () => {
    me("tenant_admin");
    serve([
      step({ id: "integrity", count: 7, href: "/attendance/integrity" }),
      step({ id: "approvals", count: 2, href: "/approvals" }),
      step({ id: "contracts_ending", count: 2, href: "/employees/contracts" }),
      step({ id: "setup_branch", group: "setup", href: "/organization" }),
      step({
        id: "unmatched_badges",
        severity: "info",
        count: 1,
        href: "/devices",
      }),
    ]);
    const user = userEvent.setup();
    renderCard();

    await screen.findByText("7 punches to review");
    expect(rows()).toHaveLength(3);
    expect(screen.queryByText("Add your first branch")).toBeNull();

    const more = screen.getByRole("button", { name: /show 2 more/i });
    expect(more).toHaveAttribute("aria-expanded", "false");
    await user.click(more);

    expect(rows()).toHaveLength(5);
    expect(screen.getByText("Add your first branch")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /show fewer/i }));
    expect(rows()).toHaveLength(3);
  });

  it("puts a row away by sending the signature it saw, and the row goes at once", async () => {
    me("supervisor");
    const sent = serve([
      step({
        id: "approvals",
        group: "approvals",
        count: 3,
        signature: "3",
        href: "/approvals",
      }),
      step({
        id: "team_absent",
        severity: "info",
        count: 1,
        href: "/attendance/team",
      }),
    ]);
    const user = userEvent.setup();
    renderCard();

    await screen.findByText("3 requests are waiting for your decision");
    await user.click(
      screen.getByRole("button", {
        name: "Put away: 3 requests are waiting for your decision",
      }),
    );

    await waitFor(() =>
      expect(screen.queryByText(/waiting for your decision/)).toBeNull(),
    );
    expect(sent.dismissed).toEqual([
      { id: "approvals", body: { signature: "3" } },
    ]);
    expect(
      screen.getByText("1 of your team is absent today"),
    ).toBeInTheDocument();
  });

  it("only snoozes a blocker, for the seven days the server allows", async () => {
    me("finance_admin");
    const sent = serve([
      step({
        id: "payroll_failed",
        severity: "blocker",
        subject: "Tikimt 2019",
        signature: "01HZRUN:failed",
        href: "/payroll/01HZRUN",
      }),
    ]);
    const user = userEvent.setup();
    renderCard();

    await screen.findByText("The Tikimt 2019 payroll run failed");
    expect(screen.queryByRole("button", { name: /^Put away/ })).toBeNull();
    await user.click(
      screen.getByRole("button", {
        name: "Remind me in 7 days: The Tikimt 2019 payroll run failed",
      }),
    );

    await waitFor(() => expect(sent.dismissed).toHaveLength(1));
    expect(sent.dismissed[0]).toEqual({
      id: "payroll_failed",
      body: { signature: "01HZRUN:failed", snooze_days: 7 },
    });
  });

  it("puts the row back when the server refuses to put it away", async () => {
    me("supervisor");
    server.use(
      http.get("*/api/v1/next-steps", () =>
        HttpResponse.json({
          steps: [step({ id: "approvals", count: 3, href: "/approvals" })],
          setup: null,
        }),
      ),
      http.post("*/api/v1/next-steps/:id/dismiss", () =>
        HttpResponse.json({ title: "Unprocessable" }, { status: 422 }),
      ),
    );
    const user = userEvent.setup();
    renderCard();

    await screen.findByText("3 requests are waiting for your decision");
    await user.click(screen.getByRole("button", { name: /^Put away/ }));

    expect(
      await screen.findByText("3 requests are waiting for your decision"),
    ).toBeInTheDocument();
  });

  it("offers the way back after something is put away, and uses it", async () => {
    me("supervisor");
    const sent = serve([
      step({ id: "approvals", count: 3, href: "/approvals" }),
    ]);
    const user = userEvent.setup();
    renderCard();

    await screen.findByText("3 requests are waiting for your decision");
    expect(screen.queryByRole("button", { name: /bring back/i })).toBeNull();

    await user.click(screen.getByRole("button", { name: /^Put away/ }));
    expect(
      await screen.findByText("Everything is put away for now."),
    ).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /bring back/i }));
    expect(
      await screen.findByText("3 requests are waiting for your decision"),
    ).toBeInTheDocument();
    expect(sent.restored()).toBe(1);
    expect(
      within(screen.getAllByRole("list")[0]).getAllByRole("listitem"),
    ).toHaveLength(1);
  });
});
