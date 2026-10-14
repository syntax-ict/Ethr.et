import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { toast } from "sonner";
import { apiClient } from "@/api/client";
import {
  BrandImageUpload,
  CoverUpload,
} from "@/features/settings/components/brand-image-upload";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/**
 * The organisation's other brand images (organisation portal, PR 2 of 4).
 * The upload is multipart, and MSW's Node interceptor never settles a
 * multipart request (CLAUDE.md, cause 5), so these spy on the client.
 */
function withClient(ui: React.ReactElement) {
  const client = new QueryClient({
    defaultOptions: { mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{ui}</QueryClientProvider>,
  );
}

const empty = { assets: { logo_dark: null, mark: null, cover: null } };

beforeEach(() => vi.clearAllMocks());
afterEach(() => vi.restoreAllMocks());

describe("<BrandImageUpload>", () => {
  it("sends the chosen image as multipart to the asset's own endpoint", async () => {
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ data: empty });
    withClient(
      <BrandImageUpload
        kind="mark"
        url={null}
        title="Square mark"
        inUse="Your square mark is in use"
        help="help"
        fileLabel="Square mark file"
      />,
    );

    fireEvent.change(screen.getByLabelText("Square mark file"), {
      target: {
        files: [
          new File([new Uint8Array([137, 80, 78, 71])], "mark.png", {
            type: "image/png",
          }),
        ],
      },
    });

    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    const [url, body] = post.mock.calls[0];
    expect(url).toBe("/settings/branding/assets/mark");
    expect(body).toBeInstanceOf(FormData);
    expect((body as FormData).get("image")).toBeInstanceOf(File);
    expect(toast.success).toHaveBeenCalledWith("Image uploaded");
  });

  it("refuses a file that is not an image, or is over 5 MB, before asking the server", () => {
    const post = vi.spyOn(apiClient, "post");
    withClient(
      <BrandImageUpload
        kind="logo_dark"
        url={null}
        title="Logo for dark backgrounds"
        inUse="in use"
        help="help"
        fileLabel="Dark-background logo file"
      />,
    );

    fireEvent.change(screen.getByLabelText("Dark-background logo file"), {
      target: {
        files: [new File(["<svg/>"], "l.svg", { type: "image/svg+xml" })],
      },
    });
    expect(toast.error).toHaveBeenCalledWith(
      "Choose a PNG, JPG or WebP image.",
    );

    const big = new File([new Uint8Array(5 * 1024 * 1024 + 1)], "l.png", {
      type: "image/png",
    });
    fireEvent.change(screen.getByLabelText("Dark-background logo file"), {
      target: { files: [big] },
    });
    expect(toast.error).toHaveBeenCalledWith(
      "The image must be 5 MB or smaller.",
    );
    expect(post).not.toHaveBeenCalled();
  });

  it("removes the image through its own endpoint", async () => {
    const del = vi
      .spyOn(apiClient, "delete")
      .mockResolvedValue({ data: empty });
    withClient(
      <BrandImageUpload
        kind="mark"
        url="/api/v1/organisation-assets/T/mark/abc"
        title="Square mark"
        inUse="Your square mark is in use"
        help="help"
        fileLabel="Square mark file"
      />,
    );

    expect(screen.getByText("Your square mark is in use")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Remove" }));

    await waitFor(() =>
      expect(del).toHaveBeenCalledWith("/settings/branding/assets/mark"),
    );
  });
});

describe("<CoverUpload>", () => {
  it("sets the focal point where the photo is clicked", async () => {
    const put = vi.spyOn(apiClient, "put").mockResolvedValue({ data: empty });
    withClient(
      <CoverUpload
        cover={{
          url: "/api/v1/organisation-assets/T/cover/abc",
          srcset: "/api/v1/organisation-assets/T/cover/abc 1920w",
          width: 1920,
          height: 960,
          focal_x: 0.5,
          focal_y: 0.5,
        }}
      />,
    );

    const picker = screen.getByRole("button", { name: "Set the focal point" });
    vi.spyOn(picker, "getBoundingClientRect").mockReturnValue({
      left: 0,
      top: 0,
      width: 400,
      height: 200,
      right: 400,
      bottom: 200,
      x: 0,
      y: 0,
      toJSON: () => ({}),
    });
    fireEvent.click(picker, { clientX: 100, clientY: 150 });

    await waitFor(() =>
      expect(put).toHaveBeenCalledWith(
        "/settings/branding/assets/cover/focal",
        { x: 0.25, y: 0.75 },
      ),
    );
  });

  it("has no focal point to set without a cover", () => {
    withClient(<CoverUpload cover={null} />);
    expect(
      screen.queryByRole("button", { name: "Set the focal point" }),
    ).not.toBeInTheDocument();
    expect(screen.getByText("Cover photo")).toBeInTheDocument();
  });
});
