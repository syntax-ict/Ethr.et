import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
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

const KINDS_URL = "*/api/v1/organization-types";

/** The page as the app mounts it: inside a query client, kinds loaded. */
function renderRegister() {
  server.use(
    http.get(KINDS_URL, () =>
      HttpResponse.json({
        data: [
          {
            code: "private",
            name: "Other private employer",
            name_am: "የግል ድርጅት",
            description: null,
            group: "private",
            parent_code: null,
          },
          {
            code: "share_company",
            name: "Share company (S.C.)",
            name_am: "አክሲዮን ማኅበር",
            description: null,
            group: "private",
            parent_code: "private",
          },
          {
            code: "government",
            name: "Federal government office",
            name_am: "የመንግሥት መሥሪያ ቤት",
            description: null,
            group: "public",
            parent_code: null,
          },
        ],
      }),
    ),
  );
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <RegisterPage />
    </QueryClientProvider>,
  );
}

/** Answers "Kind of organisation in law", once the list has loaded. */
async function chooseKind(container: HTMLElement, code = "share_company") {
  await waitFor(() =>
    expect(
      container.querySelector(`#org_kind option[value="${code}"]`),
    ).not.toBeNull(),
  );
  fireEvent.change(field(container, "org_kind"), { target: { value: code } });
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
  it("asks the kind of organisation in law, grouped, and holds Next until it is chosen", async () => {
    mockSubdomain(true);
    const { container } = renderRegister();

    fireEvent.change(field(container, "org_name"), {
      target: { value: "Acme Corp" },
    });
    await waitFor(() =>
      expect(field(container, "subdomain").value).toBe("acme-corp"),
    );
    expect(screen.getByRole("button", { name: /Next/i })).toBeDisabled();
    await chooseKind(container, "government");
    expect(
      container.querySelector('#org_kind optgroup[label="Private sector"]'),
    ).not.toBeNull();

    await waitFor(() =>
      expect(screen.getByRole("button", { name: /Next/i })).not.toBeDisabled(),
    );
  });

  it("suggests a subdomain live from the organization name and checks availability", async () => {
    mockSubdomain(true);
    const { container } = renderRegister();

    fireEvent.change(field(container, "org_name"), {
      target: { value: "Acme Corp" },
    });
    await chooseKind(container);

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
    const { container } = renderRegister();

    fireEvent.change(field(container, "org_name"), {
      target: { value: "Taken Org" },
    });

    expect(
      await screen.findByText("This subdomain is not available"),
    ).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Next/i })).toBeDisabled();
  });

  it("keeps an address typed by hand when the organisation name changes", async () => {
    mockSubdomain(true);
    const { container } = renderRegister();

    fireEvent.change(field(container, "org_name"), {
      target: { value: "Acme Corp" },
    });
    await waitFor(() =>
      expect(field(container, "subdomain").value).toBe("acme-corp"),
    );

    fireEvent.change(field(container, "subdomain"), {
      target: { value: "acme" },
    });
    fireEvent.change(field(container, "org_name"), {
      target: { value: "Acme Corporation" },
    });
    expect(field(container, "subdomain").value).toBe("acme");

    // Emptying the address hands it back to the name.
    fireEvent.change(field(container, "subdomain"), { target: { value: "" } });
    fireEvent.change(field(container, "org_name"), {
      target: { value: "Acme Group" },
    });
    await waitFor(() =>
      expect(field(container, "subdomain").value).toBe("acme-group"),
    );
  });

  it("holds the admin step until a phone number is given, and says what is wrong with one", async () => {
    mockSubdomain(true);
    const { container } = renderRegister();

    fireEvent.change(field(container, "org_name"), {
      target: { value: "Acme Corp" },
    });
    await chooseKind(container);
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

    // Name and email alone used to open the last step, where the missing
    // phone then stopped the submit without a word.
    const phone = screen.getByLabelText("Phone");
    expect(screen.getByRole("button", { name: /Next/i })).toBeDisabled();

    fireEvent.change(phone, { target: { value: "12345" } });
    fireEvent.blur(phone);
    expect(
      await screen.findByText(
        "Enter a valid Ethiopian phone number, e.g. 0911223344",
      ),
    ).toBeInTheDocument();
    expect(phone).toHaveAttribute("aria-invalid", "true");
    expect(screen.getByRole("button", { name: /Next/i })).toBeDisabled();

    // Written the way people write a number, spaces included.
    fireEvent.change(phone, { target: { value: "0912 345 678" } });
    await waitFor(() =>
      expect(screen.getByRole("button", { name: /Next/i })).not.toBeDisabled(),
    );
    // The error goes as the number is corrected, without leaving the field.
    await waitFor(() =>
      expect(
        screen.queryByText(
          "Enter a valid Ethiopian phone number, e.g. 0911223344",
        ),
      ).not.toBeInTheDocument(),
    );
    fireEvent.click(screen.getByRole("button", { name: /Next/i }));
    expect(await screen.findByLabelText("Password")).toBeInTheDocument();
  });

  it("rates password strength as the user meets more requirements", async () => {
    mockSubdomain(true);
    const { container } = renderRegister();

    // Step 1 — organization
    fireEvent.change(field(container, "org_name"), {
      target: { value: "Acme Corp" },
    });
    await chooseKind(container);
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
    fireEvent.change(screen.getByLabelText("Phone"), {
      target: { value: "0912345678" },
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
    const { container } = renderRegister();

    fireEvent.change(field(container, "org_name"), {
      target: { value: "Acme Corp" },
    });
    await chooseKind(container);
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
    fireEvent.change(screen.getByLabelText("Phone"), {
      target: { value: "0912345678" },
    });
    await waitFor(() =>
      expect(screen.getByRole("button", { name: /Next/i })).not.toBeDisabled(),
    );
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
