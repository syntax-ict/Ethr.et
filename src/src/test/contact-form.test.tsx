import { afterEach, describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { EMPTY_SITE_CONTENT } from "@/features/marketing/api";
import { ContactContent } from "@/app/(marketing)/[locale]/contact/contact-content";

// The public contact form's submit path, which had no test at all. It matters
// more now that the public site no longer loads axios up front: the form pulls
// the shared client in when it is sent, and `fieldErrors` no longer imports
// axios to recognise its errors. Both have to keep working exactly as before.

function renderContact(seedSiteContent = true) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  if (seedSiteContent) {
    queryClient.setQueryData(["site-content"], EMPTY_SITE_CONTENT);
  }

  return render(
    <QueryClientProvider client={queryClient}>
      <ContactContent />
    </QueryClientProvider>,
  );
}

async function fillAndSend() {
  const user = userEvent.setup();
  await user.type(screen.getByLabelText(/full name/i), "Abebe Kebede");
  await user.type(screen.getByLabelText(/email address/i), "abebe@example.et");
  await user.type(
    screen.getByLabelText(/^message/i),
    "We would like a demonstration for 300 staff.",
  );
  await user.click(screen.getByRole("button", { name: /send message/i }));
}

afterEach(() => {
  document.cookie = "XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT";
});

describe("contact form", () => {
  it("puts a 422's message under the field it names", async () => {
    server.use(
      http.post("*/api/v1/contact", () =>
        HttpResponse.json(
          {
            type: "https://ethr.et/errors/validation",
            title: "Validation Failed",
            status: 422,
            detail: "The given data was invalid.",
            errors: {
              message: ["The message must be at least 20 characters."],
            },
          },
          { status: 422 },
        ),
      ),
    );
    renderContact();

    await fillAndSend();

    expect(
      await screen.findByText("The message must be at least 20 characters."),
    ).toBeInTheDocument();
    expect(screen.queryByText(/message sent successfully/i)).toBeNull();
  });

  it("sends the CSRF header the stateful API checks, and confirms on 201", async () => {
    // `statefulApi()` CSRF-checks this POST. axios copies the XSRF-TOKEN
    // cookie into X-XSRF-TOKEN; a bare fetch would not, and lib/echo.ts
    // records the 419-on-every-request that cost once already.
    document.cookie = "XSRF-TOKEN=csrf-test-token";
    let xsrf: string | null = null;
    server.use(
      http.post("*/api/v1/contact", ({ request }) => {
        xsrf = request.headers.get("X-XSRF-TOKEN");
        return new HttpResponse(null, { status: 201 });
      }),
    );
    renderContact();

    await fillAndSend();

    expect(
      await screen.findByText(/message sent successfully/i),
    ).toBeInTheDocument();
    expect(xsrf).toBe("csrf-test-token");
  });

  it("reads the published contact details from the site-content endpoint", async () => {
    server.use(
      http.get("*/api/v1/site-content", () =>
        HttpResponse.json({
          data: { ...EMPTY_SITE_CONTENT, contact_email: "hello@ethr.et" },
        }),
      ),
    );
    renderContact(false);

    expect(await screen.findByText("hello@ethr.et")).toBeInTheDocument();
  });
});
