import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { useQuery } from "@tanstack/react-query";
import { act } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { apiClient } from "@/api/client";
import { Providers } from "@/app/providers";
import { RoleGate } from "@/components/shared/role-gate";
import { setLocale } from "@/lib/i18n/translations";

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isAtLeast: () => false,
    hasRole: () => false,
    can: {},
    role: "employee",
  }),
}));

/** Production QA 2026-10-29: text the server wrote kept the old language. */

beforeEach(() => {
  localStorage.setItem("locale", "en");
  // next-themes inside Providers asks for the system theme; jsdom has none.
  window.matchMedia ??= ((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: () => {},
    removeListener: () => {},
    addEventListener: () => {},
    removeEventListener: () => {},
    dispatchEvent: () => false,
  })) as typeof window.matchMedia;
});

afterEach(() => {
  localStorage.setItem("locale", "en");
});

function Summary() {
  const { data } = useQuery({
    queryKey: ["approvals", "pending"],
    queryFn: async () =>
      (await apiClient.get<{ summary: string }>("/approvals/pending")).data,
  });
  return <p data-testid="summary">{data?.summary}</p>;
}

describe("switching language", () => {
  it("refetches text the server composed, in the new language", async () => {
    server.use(
      http.get("*/approvals/pending", ({ request }) =>
        HttpResponse.json({
          summary:
            request.headers.get("Accept-Language") === "am"
              ? "ዓመታዊ ፈቃድ: ኦክተ 05 - ኦክተ 08"
              : "Annual Leave: Oct 05 - Oct 08",
        }),
      ),
    );

    render(
      <Providers>
        <Summary />
      </Providers>,
    );
    await waitFor(() =>
      expect(screen.getByTestId("summary")).toHaveTextContent("Oct 05"),
    );

    act(() => setLocale("am"));

    // Fresh for another minute by staleTime, so without the refetch the
    // English summary stayed on the Amharic screen.
    await waitFor(() =>
      expect(screen.getByTestId("summary")).toHaveTextContent("ኦክተ 05"),
    );
  });
});

describe("the access-denied screen", () => {
  it("names the role by its label, not its code", () => {
    localStorage.setItem("locale", "am");
    render(
      <RoleGate minRole="hr_admin">
        <p>hidden</p>
      </RoleGate>,
    );

    const body = screen.getByTestId("role-gate-denied").textContent ?? "";
    expect(body).toContain("(ሠራተኛ)");
    expect(body).not.toContain("employee");
  });
});
