import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import ProfilePage from "@/app/(dashboard)/profile/page";
import PersonalDetailsPage from "@/app/(dashboard)/profile/personal/page";
import ProfileRequestsPage from "@/app/(dashboard)/profile/requests/page";
import ProfilePreferencesPage from "@/app/(dashboard)/profile/preferences/page";
import { apiClient } from "@/api/client";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import type { ProfileUpdateRequest } from "@/features/profile/api";

/**
 * The self-service controls that could not be operated at all before:
 *
 * 1. The photo field sat on `PUT /profile`, which no multipart body can reach —
 *    there was no way for an employee to change their own picture.
 * 2. The edit form opened blank and posted whatever was in it, so saving a phone
 *    number silently blanked the emergency contact next to it.
 * 3. Gated fields (name, TIN, bank details) had a full HR review pipeline behind
 *    them and no UI to submit them from.
 * 4. The language switcher wrote to localStorage only, so the server kept
 *    rendering payslips and SMS in English.
 */
vi.mock("@/api/client", () => ({
  apiClient: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}));

vi.mock("next-themes", () => ({
  useTheme: () => ({ theme: "system", setTheme: vi.fn() }),
}));

function wrapper({ children }: { children: React.ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  // ProfileRequestsPage's date-of-birth field renders DualCalendarDateInput,
  // which needs a CalendarProvider in the tree even when a test never
  // interacts with that field. Force Gregorian mode so it stays a single
  // date input.
  localStorage.setItem("ethr.calendar", "gregorian");
  return (
    <QueryClientProvider client={qc}>
      <CalendarProvider>{children}</CalendarProvider>
    </QueryClientProvider>
  );
}

const profile = {
  user: {
    public_id: "01USER",
    email: "abebe@example.et",
    phone: "+251911223344",
    locale: "en",
    role: "employee",
    status: "active",
    mfa_enabled: false,
    email_verified_at: null,
    last_login_at: null,
  },
  employee: {
    public_id: "01EMP",
    name: "Abebe Kebede",
    name_am: null,
    employee_code: "EMP-001",
    phone: "+251911223344",
    gender: null,
    date_of_birth: "1995-04-12",
    nationality: "Ethiopian",
    marital_status: "married",
    hire_date: "2024-01-15",
    status: "active",
    tin_masked: "••••••5678",
    photo_path: null,
    photo_url: null,
    photo_thumb_url: null,
    department: "Finance",
    position: "Analyst",
    branch: "HQ",
    grade: null,
    supervisor: null,
  },
  preferences: { locale: "en", theme: "system", calendar: "gregorian" },
  emergency_contacts: [
    {
      public_id: "01CONTACT",
      name: "Almaz Tesfaye",
      relationship: "spouse",
      phone: "+251911223355",
      email: null,
      priority: 1,
    },
  ],
  bank_details: [
    {
      public_id: "01BANK",
      bank_name: "Commercial Bank of Ethiopia",
      branch_name: null,
      account_number_masked: "•••••••••6789",
      is_primary: true,
    },
  ],
  // Typed explicitly: a bare [] infers never[], which makes
  // Partial<typeof profile> reject any override element as `never`.
  pending_updates: [] as ProfileUpdateRequest[],
  recent_updates: [] as ProfileUpdateRequest[],
  editable_fields: {
    self: ["phone", "marital_status", "nationality"],
    gated: ["name", "name_am", "tin", "date_of_birth", "bank_account_number"],
  },
};

function mockGet(overrides: Partial<typeof profile> = {}) {
  vi.mocked(apiClient.get).mockImplementation((url: string) => {
    if (url === "/profile")
      return Promise.resolve({ data: { ...profile, ...overrides } });
    if (url === "/auth/me")
      return Promise.resolve({ data: { user: profile.user, tenant: null } });
    return Promise.resolve({ data: {} });
  });
}

beforeEach(() => {
  vi.mocked(apiClient.get).mockReset();
  vi.mocked(apiClient.post).mockReset();
  vi.mocked(apiClient.put).mockReset();
  vi.mocked(apiClient.delete).mockReset();
});

describe("Profile photo", () => {
  it("uploads to POST /profile/photo as multipart, not through PUT /profile", async () => {
    mockGet();
    vi.mocked(apiClient.post).mockResolvedValue({
      data: { photo_path: "p", photo_url: null, photo_thumb_url: null },
    });

    render(<ProfilePage />, { wrapper });

    const input = (await screen.findByLabelText(
      /change photo/i,
    )) as HTMLInputElement;
    const file = new File(["binary"], "me.jpg", { type: "image/jpeg" });
    fireEvent.change(input, { target: { files: [file] } });

    await waitFor(() => expect(apiClient.post).toHaveBeenCalled());

    const [url, body, config] = vi.mocked(apiClient.post).mock.calls[0];
    expect(url).toBe("/profile/photo");
    expect(body).toBeInstanceOf(FormData);
    // Without this header axios serialises the FormData to JSON and the file
    // never leaves the browser.
    expect(config?.headers?.["Content-Type"]).toBe("multipart/form-data");
    expect(apiClient.put).not.toHaveBeenCalled();
  });

  it("rejects a non-image before it reaches the network", async () => {
    mockGet();

    render(<ProfilePage />, { wrapper });

    const input = (await screen.findByLabelText(
      /change photo/i,
    )) as HTMLInputElement;
    fireEvent.change(input, {
      target: {
        files: [new File(["%PDF"], "payslip.pdf", { type: "application/pdf" })],
      },
    });

    await waitFor(() => expect(apiClient.post).not.toHaveBeenCalled());
  });
});

describe("Personal details", () => {
  it("prefills from the server instead of opening blank", async () => {
    mockGet();

    render(<PersonalDetailsPage />, { wrapper });

    // Phone is rendered by PhoneInput, which strips the +251 prefix.
    expect(await screen.findByDisplayValue("911223344")).toBeInTheDocument();
    expect(screen.getByDisplayValue("Ethiopian")).toBeInTheDocument();
  });

  it("lists the emergency contacts already on file", async () => {
    mockGet();

    render(<PersonalDetailsPage />, { wrapper });

    expect(await screen.findByText("Almaz Tesfaye")).toBeInTheDocument();
  });

  it("adds a contact through its own endpoint", async () => {
    mockGet();
    vi.mocked(apiClient.post).mockResolvedValue({ data: {} });

    render(<PersonalDetailsPage />, { wrapper });

    fireEvent.click(
      await screen.findByRole("button", { name: /add contact/i }),
    );
    fireEvent.change(screen.getByLabelText(/emergency contact name/i), {
      target: { value: "Kebede Alemu" },
    });
    fireEvent.change(screen.getByLabelText(/emergency contact phone/i), {
      target: { value: "911998877" },
    });

    // The relationship select is a Radix trigger; set it through the form's
    // submit path by picking the first option.
    fireEvent.click(screen.getByLabelText(/relationship/i));
    fireEvent.click(await screen.findByRole("option", { name: /spouse/i }));

    const saveButtons = screen.getAllByRole("button", { name: /save/i });
    fireEvent.click(saveButtons[saveButtons.length - 1]);

    await waitFor(() =>
      expect(apiClient.post).toHaveBeenCalledWith(
        "/profile/emergency-contacts",
        expect.objectContaining({
          name: "Kebede Alemu",
          phone: "+251911998877",
          relationship: "spouse",
        }),
      ),
    );
  });
});

describe("Gated change requests", () => {
  it("shows what is on record for each gated field", async () => {
    mockGet();

    render(<ProfileRequestsPage />, { wrapper });

    expect(await screen.findByText("Abebe Kebede")).toBeInTheDocument();
    expect(screen.getByText("••••••5678")).toBeInTheDocument();
    expect(screen.getByText("•••••••••6789")).toBeInTheDocument();
  });

  it("submits only the fields that were filled in", async () => {
    mockGet();
    vi.mocked(apiClient.put).mockResolvedValue({
      data: { message: "ok", pending_approval: null, was_duplicate: false },
    });

    render(<ProfileRequestsPage />, { wrapper });

    fireEvent.change(await screen.findByLabelText(/bank account number/i), {
      target: { value: "1000999888777" },
    });
    fireEvent.click(screen.getByRole("button", { name: /send for review/i }));

    await waitFor(() =>
      expect(apiClient.put).toHaveBeenCalledWith("/profile", {
        bank_account_number: "1000999888777",
      }),
    );
  });

  it("withdraws a pending request", async () => {
    mockGet({
      pending_updates: [
        {
          public_id: "01PUR",
          field_name: "bank_account_number",
          old_value: "1000111222333",
          new_value: "1000999888777",
          status: "pending" as const,
          employee_public_id: "01EMP",
          employee_name: "Abebe Kebede",
          requested_by_name: "Abebe Kebede",
          reviewed_by_name: null,
          reviewed_at: null,
          review_notes: null,
          created_at: null,
          updated_at: null,
        },
      ],
    });
    vi.mocked(apiClient.delete).mockResolvedValue({ data: {} });

    render(<ProfileRequestsPage />, { wrapper });

    fireEvent.click(await screen.findByRole("button", { name: /withdraw/i }));
    // Confirm in the dialog.
    const confirms = await screen.findAllByRole("button", {
      name: /withdraw/i,
    });
    fireEvent.click(confirms[confirms.length - 1]);

    await waitFor(() =>
      expect(apiClient.delete).toHaveBeenCalledWith(
        "/profile-update-requests/01PUR",
      ),
    );
  });
});

describe("Preferences", () => {
  it("persists the language to the user record", async () => {
    mockGet();
    vi.mocked(apiClient.put).mockResolvedValue({
      data: { locale: "am", theme: "system", calendar: "gregorian" },
    });

    render(<ProfilePreferencesPage />, { wrapper });

    fireEvent.click(await screen.findByLabelText(/interface language/i));
    fireEvent.click(await screen.findByRole("option", { name: "አማርኛ" }));

    await waitFor(() =>
      expect(apiClient.put).toHaveBeenCalledWith("/profile/preferences", {
        locale: "am",
      }),
    );
  });
});
