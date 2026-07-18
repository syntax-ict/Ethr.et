import { describe, it, expect } from "vitest";
import { renderHook, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { buildEmployee } from "./msw/handlers";
import { useEmployees, useEmployee } from "@/features/employees/api";

function makeWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        {children}
      </QueryClientProvider>
    );
  };
}

describe("employees api (MSW, generated OpenAPI types)", () => {
  it("fetches the paginated employee list through the real apiClient", async () => {
    const { result } = renderHook(() => useEmployees(), {
      wrapper: makeWrapper(),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(result.current.data?.data).toHaveLength(1);
    expect(result.current.data?.data[0].name).toBe("Abebe Kebede");
    expect(result.current.data?.meta.total).toBe(1);
  });

  it("fetches a single employee, exposing position.title (not .name) per the API schema", async () => {
    server.use(
      http.get("*/api/v1/employees/:publicId", ({ params }) =>
        HttpResponse.json(
          buildEmployee({
            public_id: params.publicId as string,
            position: {
              public_id: "01HZPOSITION00000000000001",
              title: "Software Engineer",
              title_am: null,
              code: null,
              description: null,
              is_active: true,
              created_at: "2024-01-01T00:00:00Z",
              updated_at: "2024-01-01T00:00:00Z",
            },
          }),
        ),
      ),
    );

    const { result } = renderHook(() => useEmployee("01HZEMPLOYEE0000000000099"), {
      wrapper: makeWrapper(),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(result.current.data?.public_id).toBe("01HZEMPLOYEE0000000000099");
    expect(result.current.data?.position?.title).toBe("Software Engineer");
  });

  it("surfaces a server error through the query's error state", async () => {
    server.use(
      http.get("*/api/v1/employees", () =>
        HttpResponse.json(
          {
            type: "server_error",
            title: "Internal Server Error",
            status: 500,
            detail: "Something went wrong.",
          },
          { status: 500 },
        ),
      ),
    );

    const { result } = renderHook(() => useEmployees(), {
      wrapper: makeWrapper(),
    });

    await waitFor(() => expect(result.current.isError).toBe(true));
  });
});
