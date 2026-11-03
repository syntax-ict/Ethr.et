import { describe, expect, it } from "vitest";
import {
  hasFaq,
  hasServices,
  localised,
  localisedFaqs,
  localisedServices,
  pickText,
} from "@/lib/auth/landing-text";
import type { TenantLanding } from "@/lib/auth/use-auth-host-context";

/**
 * An organisation's portal text in the reader's language: their own, else
 * English, else whichever language the organisation wrote. Oromo joined on
 * 2026-11-03.
 */
const landing: TenantLanding = {
  headline: "Welcome to Acme",
  headline_am: "እንኳን ወደ አክሜ በደህና መጡ",
  headline_om: "Baga gara Acme dhuftan",
  message: null,
  message_am: "ሲደርሱ ይግቡ።",
  message_om: null,
  support_email: null,
  support_phone: null,
  background: "brand",
  faqs: [
    {
      question: null,
      question_am: null,
      question_om: "Mindaan yoom kaffalama?",
      answer: null,
      answer_am: null,
      answer_om: "Guyyaa 28.",
    },
  ],
  services: [
    {
      title: "Pump repair",
      title_am: null,
      title_om: "Suphaa paampii",
      description: "Within 48 hours.",
      description_am: null,
      description_om: null,
    },
  ],
};

describe("portal text for an Oromo reader", () => {
  it("prefers the reader's language, then English, then any", () => {
    expect(pickText("om", "en", "am", "om")).toBe("om");
    expect(pickText("om", "en", "am", null)).toBe("en");
    expect(pickText("om", null, "am", null)).toBe("am");
    expect(pickText("en", null, null, "om")).toBe("om");
    expect(pickText("am", null, null, null)).toBe("");
  });

  it("writes the headline in Oromo, and English where Oromo is missing", () => {
    expect(localised(landing, "headline", "om")).toBe("Baga gara Acme dhuftan");
    expect(localised(landing, "message", "om")).toBe("ሲደርሱ ይግቡ።");
    expect(localised(landing, "headline", "am")).toBe("እንኳን ወደ አክሜ በደህና መጡ");
  });

  it("shows an Oromo-only question to every reader, never dropping it", () => {
    expect(localisedFaqs(landing, "om")).toEqual([
      { question: "Mindaan yoom kaffalama?", answer: "Guyyaa 28." },
    ]);
    expect(localisedFaqs(landing, "en")).toHaveLength(1);
    expect(hasFaq(landing)).toBe(true);
  });

  it("names a service in Oromo, with the English description beside it", () => {
    expect(localisedServices(landing, "om")).toEqual([
      { title: "Suphaa paampii", description: "Within 48 hours." },
    ]);
    expect(hasServices(landing)).toBe(true);
  });
});
