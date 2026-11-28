import { describe, expect, it } from "vitest";
import type { ErrorEvent } from "@sentry/nextjs";

import { scrubBreadcrumb, scrubEvent } from "@/lib/observability/scrub";

function eventWith(partial: Partial<ErrorEvent>): ErrorEvent {
  return { type: undefined, ...partial } as ErrorEvent;
}

describe("scrubEvent", () => {
  it("redacts credentials and encrypted-at-rest fields from the request", () => {
    const event = eventWith({
      request: {
        url: "https://acme.ethr.et/employees",
        method: "POST",
        data: {
          first_name: "Abebe",
          password: "hunter2",
          national_id: "1234567890",
          account_number: "1000200030004000",
        },
        headers: {
          authorization: "Bearer super-secret",
          cookie: "session=abc",
          "content-type": "application/json",
        },
      },
    });

    const result = scrubEvent(event);
    const data = result?.request?.data as Record<string, unknown>;
    const headers = result?.request?.headers as Record<string, string>;

    expect(data.password).toBe("[redacted]");
    expect(data.national_id).toBe("[redacted]");
    expect(data.account_number).toBe("[redacted]");
    expect(headers.authorization).toBe("[redacted]");
    expect(headers.cookie).toBe("[redacted]");

    // Debuggable context must survive.
    expect(data.first_name).toBe("Abebe");
    expect(headers["content-type"]).toBe("application/json");
    expect(result?.request?.method).toBe("POST");
  });

  it("redacts sensitive keys nested at any depth", () => {
    const event = eventWith({
      extra: {
        employee: {
          bank_details: [{ bank_name: "CBE", account_number: "10002000" }],
        },
      },
    });

    const result = scrubEvent(event);
    const employee = (result?.extra as Record<string, never>)
      .employee as Record<string, Array<Record<string, string>>>;

    expect(employee.bank_details[0].account_number).toBe("[redacted]");
    expect(employee.bank_details[0].bank_name).toBe("CBE");
  });

  it("redacts keys that merely contain a sensitive fragment", () => {
    const event = eventWith({
      extra: {
        employee_national_id: "123",
        sso_client_secret: "shhh",
        department_name: "Finance",
      },
    });

    const extra = scrubEvent(event)?.extra as Record<string, unknown>;

    expect(extra.employee_national_id).toBe("[redacted]");
    expect(extra.sso_client_secret).toBe("[redacted]");
    expect(extra.department_name).toBe("Finance");
  });

  it("survives cyclic references without recursing forever", () => {
    const cyclic: Record<string, unknown> = { name: "loop" };
    cyclic.self = cyclic;

    const event = eventWith({ extra: { cyclic } });

    expect(() => scrubEvent(event)).not.toThrow();
  });
});

describe("scrubBreadcrumb", () => {
  it("strips the query string from fetch and navigation addresses", () => {
    const fetchCrumb = scrubBreadcrumb({
      category: "fetch",
      data: {
        url: "/api/v1/employees?search=abebe%20bekele&page=2",
        method: "GET",
        status_code: 200,
      },
    });
    const navCrumb = scrubBreadcrumb({
      category: "navigation",
      data: {
        from: "/employees?q=abebe",
        to: "https://acme.ethr.et/directory?q=kebede#top",
      },
    });

    expect(fetchCrumb?.data).toEqual({
      url: "/api/v1/employees",
      method: "GET",
      status_code: 200,
    });
    expect(navCrumb?.data).toEqual({
      from: "/employees",
      to: "https://acme.ethr.et/directory",
    });
  });

  it("leaves a breadcrumb with no data, or no address, as it was", () => {
    expect(scrubBreadcrumb({ message: "clicked" })).toEqual({
      message: "clicked",
    });
    expect(
      scrubBreadcrumb({ category: "ui.click", data: { target: "button" } }),
    ).toEqual({ category: "ui.click", data: { target: "button" } });
  });

  it("does not change the breadcrumb it was given", () => {
    const original = { data: { url: "/a?q=1" } };

    scrubBreadcrumb(original);

    expect(original.data.url).toBe("/a?q=1");
  });
});
