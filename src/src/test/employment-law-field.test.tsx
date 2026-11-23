import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { EmploymentLawField } from "@/features/settings/components/employment-law-field";
import type { EmploymentFrameworkState } from "@/features/settings/components/employment-law-field";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function renderField(state: EmploymentFrameworkState) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <EmploymentLawField state={state} />
    </QueryClientProvider>,
  );
}

const ETHR_READING = {
  framework: "labour_proclamation",
  confirmed: false,
  source: "ethr",
  legal_reference: "Proclamation 1156/2019, Art. 3",
  has_statutory_rules: true,
  ethr_reading: "labour_proclamation",
} as EmploymentFrameworkState;

describe("<EmploymentLawField>", () => {
  it("says ETHR's reading is not confirmed yet, and names its legal reference", () => {
    renderField(ETHR_READING);

    expect(screen.getByTestId("employment-law-current")).toHaveTextContent(
      "Labour Proclamation",
    );
    expect(screen.getByText(/has not confirmed it yet/)).toBeInTheDocument();
    expect(
      screen.getByText(/Proclamation 1156\/2019, Art\. 3/),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /use ETHR's reading/i }),
    ).not.toBeInTheDocument();
  });

  it("shows the organisation's own choice, with a way back to ETHR's reading, and warns when ETHR has no rules for it", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.put("*/settings/organization", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ message: "Organization updated" });
      }),
    );

    renderField({
      framework: "unspecified",
      confirmed: true,
      source: "organisation",
      legal_reference: null,
      has_statutory_rules: false,
      ethr_reading: "labour_proclamation",
    } as EmploymentFrameworkState);

    expect(screen.getByText("Recorded by your organisation.")).toBeVisible();
    expect(screen.queryByText(/has not confirmed it yet/)).toBeNull();
    expect(
      screen.getByText(/ETHR has no rules for this law yet/),
    ).toBeVisible();

    await userEvent.click(
      screen.getByRole("button", { name: "Use ETHR's reading" }),
    );

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toEqual({ employment_framework: null });
  });

  it("says no employment law is on file, and that ETHR will ask", () => {
    renderField({
      framework: null,
      confirmed: false,
      source: null,
      legal_reference: null,
      has_statutory_rules: false,
      ethr_reading: null,
    } as EmploymentFrameworkState);

    expect(
      screen.getByText(/No employment law is on file for this kind/),
    ).toBeVisible();
    expect(screen.getByText(/ETHR will ask rather than assume/)).toBeVisible();
    expect(screen.queryByText(/has not confirmed it yet/)).toBeNull();
  });

  it("sends the chosen framework as employment_framework, and nothing else", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.put("*/settings/organization", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ message: "Organization updated" });
      }),
    );

    renderField(ETHR_READING);

    await userEvent.click(screen.getByRole("combobox"));
    await userEvent.click(
      await screen.findByRole("option", { name: "Federal civil service law" }),
    );

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toEqual({ employment_framework: "federal_civil_service" });
  });
});
