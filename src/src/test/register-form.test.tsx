import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import RegisterPage from "@/app/(auth)/register/page";

const push = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push, back: vi.fn(), replace: vi.fn() }),
}));

const CHECK_URL = "*/api/v1/register/check-subdomain";

function mockSubdomain(available: boolean) {
  server.use(http.get(CHECK_URL, () => HttpResponse.json({ available })));
}

function field(container: HTMLElement, id: string) {
  const el = container.querySelector(`#${id}`);
  if (!el) throw new Error(`missing field #${id}`);
  return el as HTMLInputElement;
}

beforeEach(() => {
  push.mockReset();
});

describe("Registration form (S07)", () => {
  it("suggests a subdomain live from the organization name and checks availability", async () => {
    mockSubdomain(true);
    const { container } = render(<RegisterPage />);

    fireEvent.change(field(container, "org_name"), {
      target: { value: "Acme Corp" },
    });

    // Subdomain preview updates live: derived slug appears in the input.
    await waitFor(() =>
      expect(field(container, "subdomain").value).toBe("acme-corp"),
    );

    // Availability resolves to "available" — the Next button is usable.
    await waitFor(() =>
      expect(screen.getByRole("button", { name: /Next/i })).not.toBeDisabled(),
    );
  });

  it("blocks progress and shows an error when the subdomain is taken", async () => {
    mockSubdomain(false);
    const { container } = render(<RegisterPage />);

    fireEvent.change(field(container, "org_name"), {
      target: { value: "Taken Org" },
    });

    expect(
      await screen.findByText("This subdomain is not available"),
    ).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Next/i })).toBeDisabled();
  });

  it("rates password strength as the user meets more requirements", async () => {
    mockSubdomain(true);
    const { container } = render(<RegisterPage />);

    // Step 1 — organization
    fireEvent.change(field(container, "org_name"), {
      target: { value: "Acme Corp" },
    });
    await waitFor(() =>
      expect(field(container, "subdomain").value).toBe("acme-corp"),
    );
    await waitFor(() =>
      expect(screen.getByRole("button", { name: /Next/i })).not.toBeDisabled(),
    );
    fireEvent.click(screen.getByRole("button", { name: /Next/i }));

    // Step 2 — admin account
    fireEvent.change(await screen.findByLabelText(/Full Name/i), {
      target: { value: "Test Admin" },
    });
    fireEvent.change(screen.getByLabelText(/Email Address/i), {
      target: { value: "admin@acme.et" },
    });
    await waitFor(() =>
      expect(screen.getByRole("button", { name: /Next/i })).not.toBeDisabled(),
    );
    fireEvent.click(screen.getByRole("button", { name: /Next/i }));

    // Step 3 — security: a trivial password is weak…
    const password = await screen.findByLabelText("Password");
    fireEvent.change(password, { target: { value: "abc" } });
    expect(await screen.findByText("Weak")).toBeInTheDocument();

    // …a long, mixed password is strong.
    fireEvent.change(password, { target: { value: "Abcdefgh1!xyz" } });
    expect(await screen.findByText("Strong")).toBeInTheDocument();
  });

  it("warns when the password confirmation does not match", async () => {
    mockSubdomain(true);
    const { container } = render(<RegisterPage />);

    fireEvent.change(field(container, "org_name"), {
      target: { value: "Acme Corp" },
    });
    await waitFor(() =>
      expect(screen.getByRole("button", { name: /Next/i })).not.toBeDisabled(),
    );
    fireEvent.click(screen.getByRole("button", { name: /Next/i }));

    fireEvent.change(await screen.findByLabelText(/Full Name/i), {
      target: { value: "Test Admin" },
    });
    fireEvent.change(screen.getByLabelText(/Email Address/i), {
      target: { value: "admin@acme.et" },
    });
    fireEvent.click(screen.getByRole("button", { name: /Next/i }));

    fireEvent.change(await screen.findByLabelText("Password"), {
      target: { value: "Abcdefgh1!" },
    });
    fireEvent.change(screen.getByLabelText("Confirm Password"), {
      target: { value: "Different1!" },
    });

    expect(
      await screen.findByText("Passwords do not match"),
    ).toBeInTheDocument();
  });
});
