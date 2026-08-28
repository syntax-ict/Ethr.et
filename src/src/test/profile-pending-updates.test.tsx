import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import ProfilePage from "@/app/(dashboard)/profile/page";
import NotificationPreferencesPage from "@/app/(dashboard)/notifications/preferences/page";
import { apiClient } from "@/api/client";

/**
 * Two dead controls, covered here so they stay alive:
 *
 * 1. `PUT /profile` used to report gated changes as "pending HR approval" and
 *    then discard them. The profile page now shows what is actually queued.
 * 2. The preferences matrix offered an SMS column with no delivery path behind
 *    it at all; it is now disabled unless the server says a gateway exists.
 */
vi.mock("@/api/client", () => ({
  apiClient: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}));

function wrapper({ children }: { children: React.ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

const currentUser = {
  public_id: "01USER",
  email: "abebe@example.et",
  role: "employee",
  status: "active",
  mfa_enabled: false,
  phone: "+251911223344",
};

const profileWithPending = {
  user: {
    public_id: "01USER",
    email: "abebe@example.et",
    phone: "+251911223344",
    locale: "en",
  },
  employee: {
    public_id: "01EMP",
    name: "Abebe Kebede",
    name_am: null,
    phone: "+251911223344",
    gender: null,
    date_of_birth: null,
    nationality: null,
    marital_status: null,
    hire_date: null,
    photo_path: null,
    photo_url: null,
    photo_thumb_url: null,
    department: "Finance",
    position: "Analyst",
    branch: "HQ",
    grade: null,
  },
  pending_updates: [
    {
      public_id: "01PUR",
      field_name: "bank_account_number",
      old_value: "1000111222333",
      new_value: "1000999888777",
      status: "pending",
      employee_public_id: "01EMP",
      employee_name: "Abebe Kebede",
      requested_by_name: "Abebe Kebede",
      reviewed_by_name: null,
      reviewed_at: null,
      review_notes: null,
      created_at: "2026-08-01T09:00:00Z",
      updated_at: "2026-08-01T09:00:00Z",
    },
  ],
};

beforeEach(() => {
  vi.mocked(apiClient.get).mockReset();
});

describe("Profile — pending gated changes", () => {
  it("shows the queued change with its old and new value", async () => {
    vi.mocked(apiClient.get).mockImplementation((url: string) => {
      if (url === "/profile")
        return Promise.resolve({ data: profileWithPending });
      if (url === "/auth/me")
        return Promise.resolve({ data: { user: currentUser } });
      return Promise.resolve({ data: {} });
    });

    render(<ProfilePage />, { wrapper });

    expect(
      await screen.findByText(/Changes awaiting HR review/i),
    ).toBeInTheDocument();
    expect(await screen.findByText(/bank account number/i)).toBeInTheDocument();
    expect(screen.getByText("1000111222333")).toBeInTheDocument();
    expect(screen.getByText("1000999888777")).toBeInTheDocument();
  });

  it("shows no review panel when nothing is queued", async () => {
    vi.mocked(apiClient.get).mockImplementation((url: string) => {
      if (url === "/profile")
        return Promise.resolve({
          data: { ...profileWithPending, pending_updates: [] },
        });
      if (url === "/auth/me")
        return Promise.resolve({ data: { user: currentUser } });
      return Promise.resolve({ data: {} });
    });

    render(<ProfilePage />, { wrapper });

    expect(await screen.findByText("Abebe Kebede")).toBeInTheDocument();
    expect(
      screen.queryByText(/Changes awaiting HR review/i),
    ).not.toBeInTheDocument();
  });
});

describe("Notification preferences — channel availability", () => {
  const basePrefs = {
    notification_types: ["leave_approved"],
    channels: ["in_app", "email", "sms"],
    preferences: {
      leave_approved: { in_app: true, email: true, sms: false },
    },
  };

  it("disables the SMS column when no gateway is configured", async () => {
    vi.mocked(apiClient.get).mockResolvedValue({
      data: {
        ...basePrefs,
        channel_availability: { in_app: true, email: true, sms: false },
      },
    });

    render(<NotificationPreferencesPage />, { wrapper });

    expect(await screen.findByText(/Not configured/i)).toBeInTheDocument();

    const checkboxes = await screen.findAllByRole("checkbox");
    // in_app is always-on, email is available, sms is unavailable.
    expect(checkboxes).toHaveLength(3);
    expect(checkboxes[1]).not.toBeDisabled();
    expect(checkboxes[2]).toBeDisabled();
  });

  it("leaves the SMS column usable when a gateway is configured", async () => {
    vi.mocked(apiClient.get).mockResolvedValue({
      data: {
        ...basePrefs,
        channel_availability: { in_app: true, email: true, sms: true },
      },
    });

    render(<NotificationPreferencesPage />, { wrapper });

    const checkboxes = await screen.findAllByRole("checkbox");
    expect(checkboxes[2]).not.toBeDisabled();
    expect(screen.queryByText(/Not configured/i)).not.toBeInTheDocument();
  });

  it("treats an older server that omits the field as fully available", async () => {
    vi.mocked(apiClient.get).mockResolvedValue({ data: basePrefs });

    render(<NotificationPreferencesPage />, { wrapper });

    const checkboxes = await screen.findAllByRole("checkbox");
    expect(checkboxes[2]).not.toBeDisabled();
  });
});
