import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { DocumentsTab } from "@/features/employees/components/documents-tab";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { apiClient } from "@/api/client";

const EMPLOYEE_ID = "01HZEMPLOYEE0000000000001";
const DOCUMENTS_URL = `*/api/v1/employees/${EMPLOYEE_ID}/documents`;

function renderTab() {
  // The expiry field is a DualCalendarDateInput, which defaults to Ethiopian
  // entry mode (three selects) unless a preference is stored.
  localStorage.setItem("ethr.calendar", "gregorian");
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  return render(
    (
      <QueryClientProvider client={queryClient}>
        <CalendarProvider>
          <DocumentsTab employeeId={EMPLOYEE_ID} />
        </CalendarProvider>
      </QueryClientProvider>
    ) as ReactNode,
  );
}

function emptyList() {
  server.use(http.get(DOCUMENTS_URL, () => HttpResponse.json({ data: [] })));
}

describe("DocumentsTab upload", () => {
  it("posts the fields StoreDocumentRequest actually requires", async () => {
    // Regression: this form used to send `document_type` (free text) and no
    // `title` at all, while the endpoint requires `title` plus a `type` from a
    // fixed enum. Every upload 422'd, reported only as a generic toast — the
    // feature had never worked.
    emptyList();

    // Asserted at the axios call rather than on the wire. The client sets an
    // explicit `Content-Type: multipart/form-data` (this codebase's
    // established pattern — a real browser replaces it with a
    // boundary-carrying value), and under jsdom the body does not survive as
    // parseable multipart. What matters here is which fields the component
    // builds, and that is exactly what the FormData argument holds.
    const post = vi
      .spyOn(apiClient, "post")
      .mockResolvedValue({ data: { public_id: "01HZDOC0000000000000001" } });

    const user = userEvent.setup();
    renderTab();

    await user.click(await screen.findByRole("button", { name: /Upload/i }));

    await user.type(
      await screen.findByLabelText("Title"),
      "Employment contract",
    );

    const file = new File(["hello"], "contract.pdf", {
      type: "application/pdf",
    });
    await user.upload(screen.getByLabelText("File"), file);

    // Scoped to the dialog: the card header also carries an "Upload" button,
    // and clicking that one just re-opens the dialog and posts nothing.
    await user.click(
      within(screen.getByRole("dialog")).getByRole("button", {
        name: /^Upload$/,
      }),
    );

    await waitFor(() => expect(post).toHaveBeenCalled());

    const sent = post.mock.calls[0][1] as FormData;
    expect(sent.get("title")).toBe("Employment contract");
    // `type`, not `document_type` — and one of the six values the enum accepts.
    expect(sent.get("type")).toBe("contract");
    expect(sent.get("document_type")).toBeNull();
    expect(sent.get("file")).toBeInstanceOf(File);

    post.mockRestore();
  });

  it("refuses a file over the server's 10 MB cap, before uploading it", async () => {
    // The `accept` attribute already keeps a disallowed *extension* out of the
    // picker, so size is the limit a user actually reaches: a large scan is an
    // ordinary thing to try, and previously the only feedback was a failed
    // request after the whole file had been sent.
    emptyList();

    let called = false;
    server.use(
      http.post(DOCUMENTS_URL, () => {
        called = true;
        return HttpResponse.json({});
      }),
    );

    const user = userEvent.setup();
    renderTab();

    await user.click(await screen.findByRole("button", { name: /Upload/i }));
    await user.type(await screen.findByLabelText("Title"), "Passport scan");

    const oversized = new File(["x"], "scan.pdf", { type: "application/pdf" });
    Object.defineProperty(oversized, "size", { value: 11 * 1024 * 1024 });
    await user.upload(screen.getByLabelText("File"), oversized);

    expect(await screen.findByText(/10 MB or smaller/i)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /^Upload$/ }));
    await waitFor(() =>
      expect(screen.getByLabelText("File")).toHaveAttribute(
        "aria-invalid",
        "true",
      ),
    );
    expect(called).toBe(false);
  });

  it("requires a title, and says so on the field", async () => {
    emptyList();

    let called = false;
    server.use(
      http.post(DOCUMENTS_URL, () => {
        called = true;
        return HttpResponse.json({});
      }),
    );

    const user = userEvent.setup();
    renderTab();

    await user.click(await screen.findByRole("button", { name: /Upload/i }));

    const file = new File(["hello"], "contract.pdf", {
      type: "application/pdf",
    });
    await user.upload(await screen.findByLabelText("File"), file);
    await user.click(screen.getByRole("button", { name: /^Upload$/ }));

    await waitFor(() =>
      expect(screen.getByLabelText("Title")).toHaveAttribute(
        "aria-invalid",
        "true",
      ),
    );
    expect(called).toBe(false);
  });
});
