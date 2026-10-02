import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import NotificationTemplatesPage from "@/app/(dashboard)/settings/notification-templates/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/** One row of `NotificationTemplateController::index`'s `templates`. */
const TEMPLATE = {
  type: "leave_approved",
  subject_en: "Your leave request has been approved",
  subject_am: "የፈቃድ ጥያቄዎ ፀደቀ",
  body_en:
    "Your {leave_type} request from {start_date} to {end_date} has been approved.",
  body_am: "ከ{start_date} እስከ {end_date} {leave_type} ጥያቄዎ ፀድቋል።",
  is_customized: false,
  variables: [
    "employee_name",
    "leave_type",
    "start_date",
    "end_date",
    "days",
    "organization_name",
  ],
};

function me(permissions: string[], role = "tenant_admin") {
  return http.get("*/api/v1/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "U1", name: "Test User", role },
      tenant: { public_id: "T1", name: "Demo", timezone: "Africa/Addis_Ababa" },
      permissions,
    }),
  );
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<NotificationTemplatesPage />, { wrapper: Wrapper });
}

describe("<NotificationTemplatesPage>", () => {
  it("names every field of the edit dialog and saves the edit", async () => {
    // The four labels were not tied to their inputs, so none of the fields
    // had an accessible name.
    let sent: unknown = null;
    server.use(
      me(["settings.manage"]),
      http.get("*/api/v1/settings/notification-templates", () =>
        HttpResponse.json({ templates: [TEMPLATE] }),
      ),
      http.put(
        "*/api/v1/settings/notification-templates/leave_approved",
        async ({ request }) => {
          sent = await request.json();
          return HttpResponse.json({
            message: "Template updated.",
            type: "leave_approved",
          });
        },
      ),
    );
    const user = userEvent.setup();
    renderPage();

    await screen.findByText("Leave Approved");
    await user.click(screen.getByRole("button", { name: /^Edit: / }));
    const dialog = await screen.findByRole("dialog");

    const subject = within(dialog).getByRole("textbox", {
      name: "Subject (English)",
    });
    expect(
      within(dialog).getByRole("textbox", { name: "Subject (Amharic)" }),
    ).toHaveValue(TEMPLATE.subject_am);
    expect(
      within(dialog).getByRole("textbox", { name: "Body (English)" }),
    ).toHaveValue(TEMPLATE.body_en);
    expect(
      within(dialog).getByRole("textbox", { name: "Body (Amharic)" }),
    ).toHaveValue(TEMPLATE.body_am);

    await user.clear(subject);
    await user.type(subject, "Leave approved");
    await user.click(within(dialog).getByRole("button", { name: "Save" }));

    await waitFor(() => expect(sent).not.toBeNull());
    expect(sent).toMatchObject({ subject_en: "Leave approved" });
  });

  it("lists the variables the template can use in the edit dialog", async () => {
    server.use(
      me(["settings.manage"]),
      http.get("*/api/v1/settings/notification-templates", () =>
        HttpResponse.json({ templates: [TEMPLATE] }),
      ),
    );
    const user = userEvent.setup();
    renderPage();

    await screen.findByText("Leave Approved");
    await user.click(screen.getByRole("button", { name: /^Edit: / }));
    const dialog = await screen.findByRole("dialog");

    for (const name of TEMPLATE.variables) {
      expect(within(dialog).getByText(`{${name}}`)).toBeInTheDocument();
    }
  });

  it("shows the server's refusal of an unknown variable under that field", async () => {
    // The API refuses a {placeholder} the template does not provide (it would
    // reach employees verbatim). The page used to drop the 422 into a generic
    // "Failed to update template" toast, so the admin could not tell why.
    const message =
      "This template cannot use {employe_name}. Available variables: {employee_name}, {leave_type}.";
    server.use(
      me(["settings.manage"]),
      http.get("*/api/v1/settings/notification-templates", () =>
        HttpResponse.json({ templates: [TEMPLATE] }),
      ),
      http.put("*/api/v1/settings/notification-templates/leave_approved", () =>
        HttpResponse.json(
          {
            type: "validation_error",
            title: "Validation Failed",
            status: 422,
            detail: "The given data was invalid.",
            errors: { body_en: [message] },
          },
          {
            status: 422,
            headers: { "Content-Type": "application/problem+json" },
          },
        ),
      ),
    );
    const user = userEvent.setup();
    renderPage();

    await screen.findByText("Leave Approved");
    await user.click(screen.getByRole("button", { name: /^Edit: / }));
    const dialog = await screen.findByRole("dialog");
    const body = within(dialog).getByRole("textbox", {
      name: "Body (English)",
    });

    await user.clear(body);
    await user.type(body, "Dear {{employe_name}");
    await user.click(within(dialog).getByRole("button", { name: "Save" }));

    expect(await within(dialog).findByText(message)).toBeInTheDocument();
    expect(body).toHaveAttribute("aria-invalid", "true");
    expect(body).toHaveValue("Dear {employe_name}");
  });

  it("says the templates failed to load", async () => {
    server.use(
      me(["settings.manage"]),
      http.get("*/api/v1/settings/notification-templates", () =>
        HttpResponse.json({ title: "Server Error" }, { status: 500 }),
      ),
    );
    renderPage();

    expect(await screen.findByText("Couldn't load this")).toBeInTheDocument();
  });
});
