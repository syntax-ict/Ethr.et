import { describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { AxiosError, AxiosHeaders } from "axios";
import { z } from "zod";

import { FormField } from "@/components/patterns/FormField";
import {
  FormErrorSummary,
  summaryItems,
} from "@/components/patterns/FormErrorSummary";
import { Input } from "@/components/ui/input";
import { applyServerErrors } from "@/lib/forms/apply-server-errors";
import { rules, dateRangeRefinement, fieldMessage } from "@/lib/forms/rules";
import { useZodForm } from "@/lib/forms/use-zod-form";

/** A 422 shaped exactly like `bootstrap/app.php`'s ValidationException renderer. */
function validationError(errors: Record<string, string[]>) {
  const err = new AxiosError("Request failed with status code 422");
  err.response = {
    status: 422,
    statusText: "Unprocessable Entity",
    headers: {},
    config: { headers: new AxiosHeaders() },
    data: {
      type: "https://ethr.et/errors/validation",
      title: "Validation Failed",
      status: 422,
      detail: "The given data was invalid.",
      errors,
    },
  };
  return err;
}

function httpError(status: number, detail: string) {
  const err = new AxiosError(`Request failed with status code ${status}`);
  err.response = {
    status,
    statusText: "Error",
    headers: {},
    config: { headers: new AxiosHeaders() },
    data: { type: "x", title: "Error", status, detail },
  };
  return err;
}

describe("rules — the value that actually reaches the server", () => {
  // These rules do not only accept or reject: `.trim()` and `.toLowerCase()`
  // are transforms, so the parsed output is what the form submits. Nothing
  // pinned that until 2026-09-18, which meant a validator swap could have
  // changed what the API received while every accept/reject test stayed green.
  // A mangled subdomain is the worst case: it is the tenant routing key.

  it("trims the fields whose stored value must not carry whitespace", () => {
    expect(rules.name().parse("  Abebe  ")).toBe("Abebe");
    expect(rules.text().parse("  hi  ")).toBe("hi");
    expect(rules.requiredText().parse("  hi  ")).toBe("hi");
    expect(rules.email().parse(" A@B.CO ")).toBe("A@B.CO");
    expect(rules.phone().parse(" 0911223344 ")).toBe("0911223344");
    expect(rules.url().parse(" HTTPS://x.com ")).toBe("HTTPS://x.com");
  });

  it("lowercases the subdomain, because it is the tenant routing key", () => {
    expect(rules.subdomain().parse("  ACME-1 ")).toBe("acme-1");
    expect(rules.subdomain().parse("Acme")).toBe("acme");
  });

  it("preserves case everywhere else, so a login is not silently rewritten", () => {
    // email and url are trimmed but NOT lowercased: the local part of an
    // address is case-sensitive per RFC 5321, and a path is always so.
    expect(rules.email().parse("Abebe.T@Example.CO")).toBe(
      "Abebe.T@Example.CO",
    );
    expect(rules.url().parse("https://x.com/A/b")).toBe("https://x.com/A/b");
  });

  it("does not trim a password, where spaces are part of the secret", () => {
    // Deliberate asymmetry with every other rule. " short " is 7 characters
    // and fails the 8-character floor precisely because the spaces count; if a
    // refactor adds .trim() here this assertion is what catches it.
    const spaced = rules.password().safeParse(" short ");
    expect(spaced.success).toBe(false);
    expect(rules.password().parse("  abcdefgh  ")).toBe("  abcdefgh  ");
  });

  it("carries i18n keys rather than English, for every failure mode", () => {
    const key = (
      r: {
        safeParse: (v: string) => {
          success: boolean;
          error?: { issues: { message: string }[] };
        };
      },
      v: string,
    ) => r.safeParse(v).error?.issues[0]?.message;
    expect(key(rules.name(), "a")).toBe("validation.name_min");
    expect(key(rules.email(), "nope")).toBe("validation.email");
    expect(key(rules.phone(), "123")).toBe("validation.phone");
    expect(key(rules.subdomain(), "ad")).toBe("validation.subdomain_min");
    expect(key(rules.subdomain(), "admin")).toBe(
      "validation.subdomain_reserved",
    );
    expect(key(rules.password(), "short")).toBe("validation.password_min");
    expect(key(rules.select(), "")).toBe("validation.select_required");
    expect(key(rules.publicId(), "tooshort")).toBe(
      "validation.select_required",
    );
    expect(key(rules.etb(), "1.555")).toBe("validation.amount");
    expect(key(rules.integer(), "1.5")).toBe("validation.integer");
    expect(key(rules.date(), "21/08/2026")).toBe("validation.date");
    expect(key(rules.url(), "ftp://x.com")).toBe("validation.url_scheme");
  });
});

describe("rules — client mirrors of the backend FormRequests", () => {
  it("accepts every Ethiopian phone shape the canonicalizer accepts", () => {
    // The registration bug this mirrors: the browser rejected `0911…`, which is
    // how Ethiopians write their own number, so a valid user could not sign up.
    for (const value of [
      "0911223344",
      "+251911223344",
      "251911223344",
      "911223344",
      "0711223344",
    ]) {
      expect(rules.phone().safeParse(value).success, value).toBe(true);
    }
  });

  it("rejects numbers the backend regex would also reject", () => {
    for (const value of ["091122334", "09112233445", "0011223344", "abc"]) {
      expect(rules.phone().safeParse(value).success, value).toBe(false);
    }
  });

  it("treats an empty optional phone as provided-nothing, not invalid", () => {
    expect(rules.optionalPhone().safeParse("").success).toBe(true);
    expect(rules.optionalPhone().safeParse("bogus").success).toBe(false);
  });

  it("rejects reserved subdomains before the request is sent", () => {
    expect(rules.subdomain().safeParse("admin").success).toBe(false);
    expect(rules.subdomain().safeParse("api").success).toBe(false);
    expect(rules.subdomain().safeParse("acme").success).toBe(true);
  });

  it("enforces the subdomain format rule from RegisterTenantRequest", () => {
    expect(rules.subdomain().safeParse("-acme").success).toBe(false);
    expect(rules.subdomain().safeParse("acme-").success).toBe(false);
    expect(rules.subdomain().safeParse("ac").success).toBe(false);
    expect(rules.subdomain().safeParse("ac-me1").success).toBe(true);
  });

  it("holds money to two decimal places so cents are never truncated", () => {
    expect(rules.etb().safeParse("1500").success).toBe(true);
    expect(rules.etb().safeParse("1500.50").success).toBe(true);
    expect(rules.etb().safeParse("1500.555").success).toBe(false);
    expect(rules.etb().safeParse("").success).toBe(false);
    expect(rules.etb().safeParse("-5").success).toBe(false);
  });

  it("applies min/max bounds to money", () => {
    const schema = rules.etb({ min: 100, max: 1000 });
    expect(schema.safeParse("99").success).toBe(false);
    expect(schema.safeParse("100").success).toBe(true);
    expect(schema.safeParse("1001").success).toBe(false);
  });

  it("accepts only the ISO date format every date control emits", () => {
    expect(rules.date().safeParse("2026-08-21").success).toBe(true);
    expect(rules.date().safeParse("21/08/2026").success).toBe(false);
    expect(rules.optionalDate().safeParse("").success).toBe(true);
  });

  it("requires an http(s) scheme on URLs", () => {
    expect(rules.url().safeParse("https://example.com/hook").success).toBe(
      true,
    );
    expect(rules.url().safeParse("ftp://example.com").success).toBe(false);
  });

  it("puts a reversed date range on the end field, where it can be fixed", () => {
    const schema = z
      .object({ start_date: rules.date(), end_date: rules.date() })
      .superRefine(dateRangeRefinement("start_date", "end_date"));

    const result = schema.safeParse({
      start_date: "2026-08-21",
      end_date: "2026-08-01",
    });

    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0].path).toEqual(["end_date"]);
      expect(result.error.issues[0].message).toBe("validation.date_range");
    }
  });

  it("resolves schema message keys through i18n and passes server text through", () => {
    const t = (key: string, fallback?: string) =>
      key === "validation.email"
        ? "Enter a valid email address"
        : (fallback ?? key);

    expect(fieldMessage(t, "validation.email")).toBe(
      "Enter a valid email address",
    );
    // A server message is already localized by the backend lang files.
    expect(fieldMessage(t, "That subdomain is taken.")).toBe(
      "That subdomain is taken.",
    );
    expect(fieldMessage(t, undefined)).toBeUndefined();
  });
});

describe("applyServerErrors — RFC-7807 onto the offending inputs", () => {
  it("pins each 422 field message to its own control", () => {
    const setError = vi.fn();
    const outcome = applyServerErrors(
      setError,
      validationError({
        email: ["The email has already been taken."],
        subdomain: ["That subdomain is reserved."],
      }),
      ["email", "subdomain"],
      "fallback",
    );

    expect(outcome.applied).toEqual(["email", "subdomain"]);
    expect(outcome.rootMessage).toBeNull();
    expect(setError).toHaveBeenCalledWith(
      "email",
      { type: "server", message: "The email has already been taken." },
      { shouldFocus: true },
    );
    // Only the first rejected field takes focus.
    expect(setError).toHaveBeenCalledWith(
      "subdomain",
      { type: "server", message: "That subdomain is reserved." },
      { shouldFocus: false },
    );
  });

  it("sends an error for a field this form does not render to the summary", () => {
    // Otherwise setError registers an error on a control that never blurs and
    // can never be cleared, permanently blocking submit with nothing on screen.
    const setError = vi.fn();
    const outcome = applyServerErrors(
      setError,
      validationError({ internal_code: ["Internal code is invalid."] }),
      ["email"],
      "fallback",
    );

    expect(setError).not.toHaveBeenCalled();
    expect(outcome.unmatched).toEqual(["internal_code"]);
    expect(outcome.rootMessage).toBe("Internal code is invalid.");
  });

  it("surfaces a non-validation failure as the form-level message", () => {
    const setError = vi.fn();
    const outcome = applyServerErrors(
      setError,
      httpError(403, "Your plan allows at most 10 employees."),
      ["name"],
      "fallback",
    );

    expect(setError).not.toHaveBeenCalled();
    expect(outcome.rootMessage).toBe("Your plan allows at most 10 employees.");
  });

  it("falls back when the server said nothing usable", () => {
    const outcome = applyServerErrors(
      vi.fn(),
      new Error("boom"),
      ["name"],
      "Could not save. Try again.",
    );
    expect(outcome.rootMessage).toBe("Could not save. Try again.");
  });
});

describe("summaryItems", () => {
  it("flattens nested RHF errors into a labelled list", () => {
    const items = summaryItems(
      {
        email: { type: "required", message: "This field is required" },
        steps: {
          0: { shift_id: { type: "custom", message: "Choose a shift" } },
        },
      },
      { email: "Email" },
    );

    expect(items).toEqual([
      { field: "email", message: "This field is required", label: "Email" },
      {
        field: "steps.0.shift_id",
        message: "Choose a shift",
        label: undefined,
      },
    ]);
  });

  it("returns nothing for a clean form", () => {
    expect(summaryItems({})).toEqual([]);
  });
});

describe("FormErrorSummary", () => {
  it("announces the failure and takes focus so a submit never dead-ends", () => {
    render(<FormErrorSummary message="Could not save." title="Problem" />);
    const alert = screen.getByRole("alert");
    expect(alert).toHaveTextContent("Could not save.");
    expect(alert).toHaveFocus();
  });

  it("renders nothing when the form is clean", () => {
    const { container } = render(<FormErrorSummary message={null} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("moves focus to the field a summary entry names", async () => {
    const user = userEvent.setup();
    render(
      <>
        <input id="email" aria-label="Email" />
        <FormErrorSummary
          items={[{ field: "email", message: "Required", label: "Email" }]}
        />
      </>,
    );

    await user.click(screen.getByRole("button", { name: /Email: Required/ }));
    expect(screen.getByLabelText("Email")).toHaveFocus();
  });
});

/** A form built the way every migrated form in the app is built. */
const schema = z.object({
  name: rules.name(),
  email: rules.email(),
});
type Values = z.infer<typeof schema>;

function TestForm({ onSave }: { onSave: (v: Values) => Promise<unknown> }) {
  const {
    register,
    submit,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<Values>({
    schema,
    defaultValues: { name: "", email: "" },
  });

  return (
    <form onSubmit={submit(onSave, "Could not save. Try again.")} noValidate>
      <FormErrorSummary message={rootError} />
      <FormField id="name" label="Name" required error={errors.name?.message}>
        <Input {...register("name")} />
      </FormField>
      <FormField
        id="email"
        label="Email"
        required
        error={errors.email?.message}
      >
        <Input {...register("email")} />
      </FormField>
      <button type="submit" disabled={isSubmitting}>
        Save
      </button>
    </form>
  );
}

describe("useZodForm — the house form contract", () => {
  it("blocks submit and marks the invalid field, without calling the API", async () => {
    const user = userEvent.setup();
    const onSave = vi.fn().mockResolvedValue(undefined);
    render(<TestForm onSave={onSave} />);

    await user.type(screen.getByLabelText(/Name/), "A");
    await user.type(screen.getByLabelText(/Email/), "not-an-email");
    await user.click(screen.getByRole("button", { name: "Save" }));

    await waitFor(() => {
      expect(screen.getByLabelText(/Name/)).toHaveAttribute(
        "aria-invalid",
        "true",
      );
    });
    expect(screen.getByLabelText(/Email/)).toHaveAttribute(
      "aria-invalid",
      "true",
    );
    expect(onSave).not.toHaveBeenCalled();
  });

  it("validates a field when it first blurs, not only on submit", async () => {
    const user = userEvent.setup();
    render(<TestForm onSave={vi.fn()} />);

    await user.click(screen.getByLabelText(/Email/));
    await user.tab();

    await waitFor(() => {
      expect(screen.getByLabelText(/Email/)).toHaveAttribute(
        "aria-invalid",
        "true",
      );
    });
  });

  it("routes a server 422 back onto the field that caused it", async () => {
    const user = userEvent.setup();
    const onSave = vi
      .fn()
      .mockRejectedValue(
        validationError({ email: ["The email has already been taken."] }),
      );
    render(<TestForm onSave={onSave} />);

    await user.type(screen.getByLabelText(/Name/), "Abebe Kebede");
    await user.type(screen.getByLabelText(/Email/), "taken@example.com");
    await user.click(screen.getByRole("button", { name: "Save" }));

    expect(
      await screen.findByText("The email has already been taken."),
    ).toBeInTheDocument();
    expect(screen.getByLabelText(/Email/)).toHaveAttribute(
      "aria-invalid",
      "true",
    );
  });

  it("shows a non-field failure in the summary and re-enables the button", async () => {
    const user = userEvent.setup();
    const onSave = vi
      .fn()
      .mockRejectedValue(
        httpError(403, "Your plan allows at most 10 employees."),
      );
    render(<TestForm onSave={onSave} />);

    await user.type(screen.getByLabelText(/Name/), "Abebe Kebede");
    await user.type(screen.getByLabelText(/Email/), "a@example.com");
    await user.click(screen.getByRole("button", { name: "Save" }));

    expect(
      await screen.findByText("Your plan allows at most 10 employees."),
    ).toBeInTheDocument();
    // A failed submit must leave the form usable.
    expect(screen.getByRole("button", { name: "Save" })).toBeEnabled();
  });

  it("submits parsed values once the form is valid", async () => {
    const user = userEvent.setup();
    const onSave = vi.fn().mockResolvedValue(undefined);
    render(<TestForm onSave={onSave} />);

    await user.type(screen.getByLabelText(/Name/), "  Abebe Kebede  ");
    await user.type(screen.getByLabelText(/Email/), "abebe@example.com");
    await user.click(screen.getByRole("button", { name: "Save" }));

    await waitFor(() => expect(onSave).toHaveBeenCalledTimes(1));
    // Values only — the handler never sees the DOM event, so a call site cannot
    // accidentally depend on it. Trimmed by the schema, so the API never
    // receives padded input.
    expect(onSave).toHaveBeenCalledWith({
      name: "Abebe Kebede",
      email: "abebe@example.com",
    });
  });
});
