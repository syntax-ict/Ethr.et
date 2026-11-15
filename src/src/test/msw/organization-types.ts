import { http, HttpResponse } from "msw";

/** A short `GET /organization-types` catalogue for tests that name a kind. */
export const kindCatalogueHandler = http.get(
  "*/api/v1/organization-types",
  () =>
    HttpResponse.json({
      data: [
        {
          code: "private",
          name: "Private company",
          name_am: "የግል ድርጅት",
          name_om: "Dhaabbata dhuunfaa",
          description: null,
          group: "private",
          parent_code: null,
          suggested_for: [],
          policy_profile: "standard",
        },
        {
          code: "government",
          name: "Government office",
          name_am: "የመንግሥት መሥሪያ ቤት",
          name_om: "Waajjira mootummaa",
          description: null,
          group: "public",
          parent_code: null,
          suggested_for: [],
          policy_profile: "civil_service",
        },
      ],
    }),
);
