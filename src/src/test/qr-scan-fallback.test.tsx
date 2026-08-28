import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import QrScanPage from "@/app/(dashboard)/attendance/scan/page";
import { apiClient } from "@/api/client";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), back: vi.fn() }),
}));

// html5-qrcode touches real camera APIs that jsdom cannot provide; the scanner
// start is stubbed per-test to simulate granted vs denied camera permission.
const startMock = vi.fn();
vi.mock("html5-qrcode", () => ({
  Html5Qrcode: class {
    start = (...args: unknown[]) => startMock(...args);
    stop = vi.fn().mockResolvedValue(undefined);
    clear = vi.fn();
  },
}));

describe("QR scan page — camera fallback (GAP-FIX-7)", () => {
  beforeEach(() => {
    startMock.mockReset();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it("offers manual entry from the idle screen without requiring the camera", async () => {
    const user = userEvent.setup();
    render(<QrScanPage />);

    // Not shown until the employee asks for it.
    expect(screen.queryByLabelText(/enter qr code manually/i)).toBeNull();

    await user.click(
      screen.getByRole("button", { name: /enter qr code manually/i }),
    );

    expect(
      await screen.findByPlaceholderText(/type or paste the qr code text/i),
    ).toBeInTheDocument();
  });

  it("shows an explanatory message and manual entry when camera access is denied", async () => {
    const user = userEvent.setup();
    startMock.mockRejectedValue(new Error("NotAllowedError"));
    vi.spyOn(console, "error").mockImplementation(() => {});

    render(<QrScanPage />);
    await user.click(screen.getByRole("button", { name: /start scanner/i }));

    expect(
      await screen.findByText(/camera access denied/i),
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        /check your browser settings to allow camera permission/i,
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByPlaceholderText(/type or paste the qr code text/i),
    ).toBeInTheDocument();
  });

  it("submits a manually typed token to the attendance endpoint", async () => {
    const user = userEvent.setup();
    const post = vi
      .spyOn(apiClient, "post")
      .mockResolvedValue({ data: { employee: { name: "Abebe Kebede" } } });

    render(<QrScanPage />);
    await user.click(
      screen.getByRole("button", { name: /enter qr code manually/i }),
    );

    await user.type(
      screen.getByPlaceholderText(/type or paste the qr code text/i),
      "QR-TOKEN-123",
    );
    await user.click(screen.getByRole("button", { name: /^submit$/i }));

    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    expect(post.mock.calls[0][0]).toBe("/attendance/qr");
    expect(post.mock.calls[0][1]).toMatchObject({
      qr_token: "QR-TOKEN-123",
      type: "check_in",
    });

    expect(await screen.findByText(/Abebe Kebede/)).toBeInTheDocument();
  });

  it("does not submit an empty manual token", async () => {
    const user = userEvent.setup();
    const post = vi.spyOn(apiClient, "post");

    render(<QrScanPage />);
    await user.click(
      screen.getByRole("button", { name: /enter qr code manually/i }),
    );

    expect(screen.getByRole("button", { name: /^submit$/i })).toBeDisabled();
    expect(post).not.toHaveBeenCalled();
  });
});
