import { describe, it, expect } from "vitest";
import { renderHook, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";

function createWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  // Named declaration rather than an anonymous arrow: react/display-name
  // needs a name to report, and an unnamed wrapper shows up as <Unknown> in
  // React DevTools and in test failure output.
  function TestQueryWrapper({ children }: { children: React.ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
  }

  return TestQueryWrapper;
}

describe("useCurrentUser", () => {
  it("fetches and returns the current user", async () => {
    server.use(
      http.get("*/api/v1/auth/me", () =>
        HttpResponse.json({
          user: {
            public_id: "01HZUSER0000000000000001",
            name: "Abebe Kebede",
            email: "abebe@acme.et",
            role: "tenant_admin",
          },
          tenant: {
            public_id: "01HZTENANT00000000000001",
            name: "Acme Corp",
            subdomain: "acme",
            status: "active",
          },
        }),
      ),
    );

    const { useCurrentUser } = await import("@/features/auth/api");
    const { result } = renderHook(() => useCurrentUser(), {
      wrapper: createWrapper(),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.name).toBe("Abebe Kebede");
    expect(result.current.data?.role).toBe("tenant_admin");
  });

  it("handles 401 unauthenticated response", async () => {
    server.use(
      http.get("*/api/v1/auth/me", () =>
        HttpResponse.json(
          {
            type: "unauthenticated",
            title: "Unauthenticated",
            status: 401,
            detail: "You are not authenticated.",
          },
          { status: 401 },
        ),
      ),
    );

    const { useCurrentUser } = await import("@/features/auth/api");
    const { result } = renderHook(() => useCurrentUser(), {
      wrapper: createWrapper(),
    });

    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(result.current.data).toBeUndefined();
  });
});

describe("useCurrentTenant", () => {
  it("returns tenant data from the me endpoint", async () => {
    server.use(
      http.get("*/api/v1/auth/me", () =>
        HttpResponse.json({
          user: {
            public_id: "01HZUSER0000000000000001",
            name: "Test User",
            email: "test@acme.et",
            role: "employee",
          },
          tenant: {
            public_id: "01HZTENANT00000000000001",
            name: "Acme Corp",
            subdomain: "acme",
            status: "active",
            logo_path: null,
            theme: { primary_color: "#0F4C75" },
          },
        }),
      ),
    );

    const { useCurrentTenant } = await import("@/features/auth/api");
    const { result } = renderHook(() => useCurrentTenant(), {
      wrapper: createWrapper(),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.name).toBe("Acme Corp");
    expect(result.current.data?.subdomain).toBe("acme");
    expect(result.current.data?.theme?.primary_color).toBe("#0F4C75");
  });
});

describe("useLogout", () => {
  it("calls logout endpoint and clears query cache", async () => {
    server.use(
      http.post("*/api/v1/auth/logout", () =>
        HttpResponse.json({ message: "Logged out" }),
      ),
    );

    const originalLocation = window.location;
    Object.defineProperty(window, "location", {
      writable: true,
      value: {
        ...originalLocation,
        href: "http://localhost:3000/",
        origin: "http://localhost:3000",
        host: "localhost:3000",
        hostname: "localhost",
        protocol: "http:",
        pathname: "/",
      },
    });

    const { useLogout } = await import("@/features/auth/api");
    const { result } = renderHook(() => useLogout(), {
      wrapper: createWrapper(),
    });

    result.current.mutate();

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(window.location.href).toBe("/login");

    Object.defineProperty(window, "location", {
      writable: true,
      value: originalLocation,
    });
  });
});

describe("Login API interaction", () => {
  it("returns MFA required response", async () => {
    server.use(
      http.post("*/api/v1/auth/login", () =>
        HttpResponse.json({ mfa_required: true }),
      ),
    );

    const { apiClient } = await import("@/api/client");
    const response = await apiClient.post("/auth/login", {
      email: "admin@acme.et",
      password: "password123",
      tenant: "acme",
    });

    expect(response.data.mfa_required).toBe(true);
  });

  it("returns 422 for invalid credentials", async () => {
    server.use(
      http.post("*/api/v1/auth/login", () =>
        HttpResponse.json(
          {
            type: "validation_error",
            title: "Validation Failed",
            status: 422,
            detail: "Invalid credentials.",
            errors: { email: ["The provided credentials are incorrect."] },
          },
          { status: 422 },
        ),
      ),
    );

    const { apiClient } = await import("@/api/client");

    await expect(
      apiClient.post("/auth/login", {
        email: "wrong@acme.et",
        password: "wrong",
        tenant: "acme",
      }),
    ).rejects.toMatchObject({
      response: { status: 422 },
    });
  });
});

describe("Registration API interaction", () => {
  it("registers a new tenant successfully", async () => {
    server.use(
      http.post("*/api/v1/auth/register", () =>
        HttpResponse.json({
          message: "Registration successful",
          tenant: { subdomain: "neworg" },
        }),
      ),
    );

    const { apiClient } = await import("@/api/client");
    const response = await apiClient.post("/auth/register", {
      organization_name: "New Org",
      organization_type: "general",
      subdomain: "neworg",
      admin_name: "Admin User",
      admin_email: "admin@neworg.et",
      password: "StrongPass123!",
      password_confirmation: "StrongPass123!",
    });

    expect(response.data.message).toBe("Registration successful");
  });

  it("returns field errors for duplicate subdomain", async () => {
    server.use(
      http.post("*/api/v1/auth/register", () =>
        HttpResponse.json(
          {
            type: "validation_error",
            title: "Validation Failed",
            status: 422,
            detail: "The given data was invalid.",
            errors: {
              subdomain: ["The subdomain has already been taken."],
            },
          },
          { status: 422 },
        ),
      ),
    );

    const { apiClient } = await import("@/api/client");

    await expect(
      apiClient.post("/auth/register", {
        organization_name: "Existing Org",
        organization_type: "general",
        subdomain: "existing",
        admin_name: "Admin",
        admin_email: "admin@existing.et",
        password: "StrongPass123!",
        password_confirmation: "StrongPass123!",
      }),
    ).rejects.toMatchObject({
      response: {
        status: 422,
        data: {
          errors: {
            subdomain: ["The subdomain has already been taken."],
          },
        },
      },
    });
  });
});
