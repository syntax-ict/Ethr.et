import { describe, it, expect } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import {
  EmployeeAvatar,
  initialsFor,
} from "@/components/shared/employee-avatar";

describe("initialsFor", () => {
  it("takes the first letter of the first two words", () => {
    expect(initialsFor("Abebe Kebede")).toBe("AK");
  });

  it("ignores extra words and stray whitespace", () => {
    expect(initialsFor("  Abebe  Kebede Tesfaye ")).toBe("AK");
  });

  it("handles a single name", () => {
    expect(initialsFor("Abebe")).toBe("A");
  });

  it("keeps whole Amharic characters intact", () => {
    // Splitting by code unit would slice these syllables in half.
    expect(initialsFor("አበበ ከበደ")).toBe("አከ");
  });
});

describe("EmployeeAvatar", () => {
  it("falls back to initials when there is no photo", () => {
    render(<EmployeeAvatar name="Abebe Kebede" />);

    expect(screen.getByText("AK")).toBeInTheDocument();
    expect(screen.queryByRole("img")).not.toBeInTheDocument();
  });

  it("renders the thumbnail when one is available", () => {
    render(
      <EmployeeAvatar
        name="Abebe Kebede"
        photoThumbUrl="https://minio.test/thumb.jpg"
        photoUrl="https://minio.test/full.jpg"
      />,
    );

    const img = screen.getByRole("img", { name: "Abebe Kebede" });
    expect(img).toHaveAttribute("src", "https://minio.test/thumb.jpg");
  });

  it("falls back to the full-size photo when the thumbnail fails to load", () => {
    render(
      <EmployeeAvatar
        name="Abebe Kebede"
        photoThumbUrl="https://minio.test/missing-thumb.jpg"
        photoUrl="https://minio.test/full.jpg"
      />,
    );

    fireEvent.error(screen.getByRole("img", { name: "Abebe Kebede" }));

    expect(screen.getByRole("img", { name: "Abebe Kebede" })).toHaveAttribute(
      "src",
      "https://minio.test/full.jpg",
    );
  });

  it("falls back to initials once every source has failed", () => {
    render(
      <EmployeeAvatar
        name="Abebe Kebede"
        photoThumbUrl="https://minio.test/missing-thumb.jpg"
      />,
    );

    fireEvent.error(screen.getByRole("img", { name: "Abebe Kebede" }));

    expect(screen.getByText("AK")).toBeInTheDocument();
    expect(screen.queryByRole("img")).not.toBeInTheDocument();
  });
});
