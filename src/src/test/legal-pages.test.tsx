import { describe, it, expect, beforeEach, afterEach } from "vitest";
import { render, screen, within } from "@testing-library/react";
import { LegalDocumentView } from "@/components/marketing/legal-document";
import {
  privacyEn,
  termsEn,
  LEGAL_REVIEW_REQUIRED,
} from "@/lib/legal/documents";

/**
 * These assert the properties that make a legal page trustworthy rather than
 * its wording, which will change the moment a lawyer reads it.
 *
 * The two that matter most are the ones a careless edit could quietly undo: the
 * unreviewed-draft banner disappearing while the text is still a draft, and the
 * privacy policy claiming something the software does not do.
 */
describe("Legal documents", () => {
  afterEach(() => {
    localStorage.setItem("locale", "en");
  });

  beforeEach(() => {
    localStorage.setItem("locale", "en");
  });

  it("warns, unmissably, while the text is still an unreviewed draft", () => {
    render(<LegalDocumentView document={privacyEn} />);

    // Guard rather than assertion: once LEGAL_REVIEW_REQUIRED is flipped off
    // this test should stop demanding a banner, not start failing.
    if (LEGAL_REVIEW_REQUIRED) {
      expect(
        screen.getByText("Draft — pending legal review"),
      ).toBeInTheDocument();
      expect(
        screen.getByText(/not yet been reviewed by a qualified legal adviser/i),
      ).toBeInTheDocument();
    }
  });

  it("tells a non-English reader, in their language, why the body is English", () => {
    localStorage.setItem("locale", "am");
    render(<LegalDocumentView document={privacyEn} />);

    // The prose is deliberately not in the i18n dictionaries, so the notice
    // explaining that is the one thing here that must be translated.
    expect(
      screen.getByText(/ይህ ሰነድ በአሁኑ ጊዜ በእንግሊዝኛ ብቻ ይገኛል/),
    ).toBeInTheDocument();
  });

  it("does not show that notice to an English reader", () => {
    render(<LegalDocumentView document={privacyEn} />);
    expect(
      screen.queryByText(/currently available in English only/i),
    ).not.toBeInTheDocument();
  });

  it("gives every section an anchor and lists them all in the contents", () => {
    render(<LegalDocumentView document={termsEn} />);

    const contents = screen.getByRole("navigation", { name: "Contents" });
    const links = within(contents).getAllByRole("link");

    expect(links).toHaveLength(termsEn.sections.length);
    // A contents entry pointing at an id that does not exist is a dead link in
    // the one kind of page where people follow links to cite a clause.
    for (const section of termsEn.sections) {
      expect(
        links.some((a) => a.getAttribute("href") === `#${section.id}`),
      ).toBe(true);
    }
  });

  it("renders the effective date as a machine-readable calendar date", () => {
    render(<LegalDocumentView document={privacyEn} />);

    // Through formatDateOnly, so it reads the same in every timezone — the
    // defect that once turned "2026-10-01" into "30 Sept 2026" behind UTC.
    // en-GB abbreviates September as "Sept", not "Sep"; asserted as rendered
    // rather than as assumed.
    expect(screen.getByText("16 Sept 2026")).toBeInTheDocument();

    // The <time> element carries the unformatted value, so the date stays
    // machine-readable however it is displayed.
    const time = screen.getByText("16 Sept 2026");
    expect(time.tagName).toBe("TIME");
    expect(time).toHaveAttribute("dateTime", "2026-09-16");
  });

  describe("the privacy policy's factual claims", () => {
    it("claims no analytics, no session recording and no tracking cookies", () => {
      render(<LegalDocumentView document={privacyEn} />);

      // All three are verifiable in this repository: no analytics script
      // exists, instrumentation-client.ts sets replaysSessionSampleRate: 0 and
      // sendDefaultPii: false. If any of that changes, this page becomes a
      // false statement and this test is where it should surface.
      expect(screen.getByText(/No web analytics/)).toBeInTheDocument();
      expect(screen.getByText(/No session recording/)).toBeInTheDocument();
      expect(
        screen.getByText(/sets no tracking or advertising cookies/i),
      ).toBeInTheDocument();
    });

    it("admits there is no automated retention rather than implying one", () => {
      render(<LegalDocumentView document={privacyEn} />);

      // Nothing in api/ prunes or expires any table. A policy that quoted a
      // retention period would be describing a control that does not exist —
      // the specific failure docs/README.md warns about.
      expect(
        screen.getByText(/does not currently delete any category of data/i),
      ).toBeInTheDocument();
    });

    it("distinguishes ETHR as processor from the employer as controller", () => {
      render(<LegalDocumentView document={privacyEn} />);

      expect(
        screen.getByText(/your employer decides what is collected/i),
      ).toBeInTheDocument();
    });
  });

  describe("the terms' commercial claims", () => {
    it("states the billing and dunning terms the code actually implements", () => {
      render(<LegalDocumentView document={termsEn} />);

      // BillingService sets a 15-day due date; HandleOverdueInvoicesJob marks
      // overdue at 7 days past due and suspends at 60.
      expect(screen.getByText(/due within fifteen days/i)).toBeInTheDocument();
      expect(
        screen.getByText(/seven days after its due date/i),
      ).toBeInTheDocument();
      expect(
        screen.getByText(/sixty days after it was issued/i),
      ).toBeInTheDocument();
    });

    it("declines to promise an uptime figure, because no SLA exists", () => {
      render(<LegalDocumentView document={termsEn} />);

      expect(
        screen.getByText(
          /do not currently offer a contractual uptime guarantee/i,
        ),
      ).toBeInTheDocument();
      // The landing page's invented "99.9%" must never reappear here.
      expect(screen.queryByText(/99\.9/)).not.toBeInTheDocument();
    });

    it("describes the trial as unrestricted, which is what AuthService does", () => {
      render(<LegalDocumentView document={termsEn} />);

      expect(screen.getByText(/six-month trial/i)).toBeInTheDocument();
      expect(
        screen.getByText(/the trial is not a restricted tier/i),
      ).toBeInTheDocument();
    });
  });
});
