import { describe, it, expect } from "vitest";
import { render, waitFor, within } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { ImportColumnGuide } from "@/features/employees/components/import-column-guide";
import type { EmployeeImportTemplate } from "@/features/employees/api";
import { FALLBACK_TEMPLATE_KEYS } from "@/features/employees/employee-columns";

type Column = EmployeeImportTemplate["columns"][number];

function column(key: string, required = false): Column {
  return {
    key,
    column: key,
    group: "core",
    kind: "text",
    required,
    pay: false,
    masked: false,
  };
}

function renderGuide() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={qc}>
      <ImportColumnGuide />
    </QueryClientProvider>,
  );
}

/** Each listed column: its key, whether it is marked required, and its help. */
function listed(container: HTMLElement) {
  return Array.from(container.querySelectorAll("dt")).map((dt) => ({
    key: dt.firstChild?.textContent ?? "",
    required: dt.textContent?.includes("(required)") ?? false,
    help: dt.nextElementSibling?.textContent ?? "",
  }));
}

describe("Import column guide", () => {
  it("lists the columns the server names, in its order", async () => {
    server.use(
      http.post("*/employees/import/template", () =>
        HttpResponse.json({
          template: "name,shoe_size,nationality\n",
          headers: ["name", "shoe_size", "nationality"],
          required: ["name"],
          text_columns: [],
          date_columns: [],
          columns: [
            column("name", true),
            column("shoe_size"),
            column("nationality"),
          ],
          lists: {},
        }),
      ),
    );

    const { container } = renderGuide();

    await waitFor(() =>
      expect(listed(container).map((c) => c.key)).toEqual([
        "name",
        "shoe_size",
        "nationality",
      ]),
    );
    const [name, shoeSize, nationality] = listed(container);
    expect(name.required).toBe(true);
    expect(shoeSize).toEqual({ key: "shoe_size", required: false, help: "" });
    expect(nationality.required).toBe(false);
    expect(nationality.help).toBe("Country of citizenship, such as Ethiopian");
    expect(within(container).getAllByText("(required)")).toHaveLength(1);
  });

  it("keeps the browser's own list when the server cannot be asked", async () => {
    let asked = false;
    server.use(
      http.post("*/employees/import/template", () => {
        asked = true;
        return HttpResponse.json({ message: "Server Error" }, { status: 500 });
      }),
    );

    const { container } = renderGuide();

    await waitFor(() => expect(asked).toBe(true));
    // Let the failed request settle, then read what stayed on screen.
    await new Promise((resolve) => setTimeout(resolve, 100));

    const columns = listed(container);
    expect(columns.map((c) => c.key)).toEqual([...FALLBACK_TEMPLATE_KEYS]);
    expect(columns.map((c) => c.key)).toContain("nationality");
    expect(columns.filter((c) => c.required).map((c) => c.key)).toEqual([
      "name",
    ]);
  });
});
