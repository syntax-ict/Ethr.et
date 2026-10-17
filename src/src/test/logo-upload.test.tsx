import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { toast } from "sonner";
import { apiClient } from "@/api/client";
import { LogoUpload } from "@/features/settings/components/logo-upload";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/**
 * Uploading the organisation's logo (organisation-landing follow-up G1).
 *
 * The request is multipart, and MSW's Node interceptor never settles a
 * multipart request (CLAUDE.md, cause 5), so these spy on the client instead.
 */
function renderUpload(uploadedLogoUrl: string | null) {
  const client = new QueryClient({
    defaultOptions: { mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <LogoUpload uploadedLogoUrl={uploadedLogoUrl} />
    </QueryClientProvider>,
  );
}

function pick(file: File) {
  fireEvent.change(screen.getByLabelText("Logo image file"), {
    target: { files: [file] },
  });
}

beforeEach(() => vi.clearAllMocks());
afterEach(() => vi.restoreAllMocks());

describe("<LogoUpload>", () => {
  it("sends the chosen image as multipart to the logo endpoint", async () => {
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({
      data: {
        uploaded_logo_url: "/api/v1/organisation-logos/x/y",
        logo_url: "/api/v1/organisation-logos/x/y",
      },
    });
    renderUpload(null);

    pick(
      new File([new Uint8Array([137, 80, 78, 71])], "logo.png", {
        type: "image/png",
      }),
    );

    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    const [url, body] = post.mock.calls[0]!;
    expect(url).toBe("/settings/branding/logo");
    expect(body).toBeInstanceOf(FormData);
    expect((body as FormData).get("logo")).toBeInstanceOf(File);
    await waitFor(() => expect(toast.success).toHaveBeenCalled());
  });

  it("refuses an SVG or an oversized image before sending anything", async () => {
    const post = vi.spyOn(apiClient, "post");
    renderUpload(null);

    pick(new File(["<svg/>"], "logo.svg", { type: "image/svg+xml" }));
    pick(
      new File([new Uint8Array(1024 * 1024 + 1)], "big.png", {
        type: "image/png",
      }),
    );

    // The size check follows the in-browser shrink, which is asynchronous.
    await waitFor(() => expect(toast.error).toHaveBeenCalledTimes(2));
    expect(post).not.toHaveBeenCalled();
  });

  it("shows the uploaded logo and removes it", async () => {
    const del = vi.spyOn(apiClient, "delete").mockResolvedValue({
      data: { uploaded_logo_url: null, logo_url: null },
    });
    renderUpload("/api/v1/organisation-logos/x/y");

    expect(screen.getByAltText("Uploaded logo")).toHaveAttribute(
      "src",
      "/api/v1/organisation-logos/x/y",
    );
    fireEvent.click(screen.getByRole("button", { name: /Remove/ }));

    await waitFor(() =>
      expect(del).toHaveBeenCalledWith("/settings/branding/logo"),
    );
  });

  it("offers no Remove when nothing is uploaded", () => {
    renderUpload(null);

    expect(screen.queryByRole("button", { name: /Remove/ })).toBeNull();
    expect(
      screen.getByRole("button", { name: /Choose image/ }),
    ).toBeInTheDocument();
  });
});
