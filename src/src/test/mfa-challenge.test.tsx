import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import MfaChallengePage from "@/app/(auth)/login/mfa/page";

/**
 * Regression cover for extracting the digit-box widget into
 * `OtpCodeInput` (shared with the new OTP sign-in page). The paste-to-fill
 * shortcut and the refocus-after-rejected-code behavior both moved from a
 * local ref array to the shared component's own `key`-remount trick — this
 * confirms neither broke in the move.
 */
const push = vi.fn();
const replace = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push, replace, back: vi.fn() }),
}));

beforeEach(() => {
  push.mockReset();
  replace.mockReset();
  sessionStorage.setItem("mfa_pending", "true");
  localStorage.setItem("tenant", "acme");
});

describe("MFA challenge page", () => {
  it("bounces to /login when there is no pending MFA challenge", () => {
    sessionStorage.removeItem("mfa_pending");
    render(<MfaChallengePage />);
    expect(replace).toHaveBeenCalledWith("/login");
  });

  it("auto-submits a full 6-digit paste", async () => {
    server.use(
      http.post("*/api/v1/auth/mfa/verify", () =>
        HttpResponse.json({ access_token: "tok" }),
      ),
    );

    const { container } = render(<MfaChallengePage />);
    const boxes = container.querySelectorAll('input[inputmode="numeric"]');

    fireEvent.paste(boxes[0], {
      clipboardData: { getData: () => "123456" },
    });

    await waitFor(() => expect(push).toHaveBeenCalledWith("/dashboard"));
  });

  it("clears the boxes and shows the server's message on a rejected code", async () => {
    server.use(
      http.post("*/api/v1/auth/mfa/verify", () =>
        HttpResponse.json({ detail: "Invalid code." }, { status: 422 }),
      ),
    );

    const { container } = render(<MfaChallengePage />);
    const boxes = () =>
      container.querySelectorAll('input[inputmode="numeric"]');

    "111111".split("").forEach((d, i) => {
      fireEvent.change(boxes()[i], { target: { value: d } });
    });
    fireEvent.click(screen.getByRole("button", { name: /verify/i }));

    expect(await screen.findByText("Invalid code.")).toBeInTheDocument();
    boxes().forEach((box) => expect(box).toHaveValue(""));
  });
});
