<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The version of the Terms of Service and Privacy Policy an organisation
 * accepts at sign-up: their effective date.
 *
 * The documents themselves are on the frontend (`src/src/lib/legal/documents.ts`,
 * `LEGAL_VERSION`), rendered at /terms and /privacy. This copy is what the
 * server records, so a pinned frontend test fails when the two differ: an
 * acceptance recorded against a version nobody was shown proves nothing.
 *
 * Change both when the documents change in a way that matters to what was
 * agreed. Organisations that accepted an earlier version keep that version on
 * record (`tenants.terms_accepted_version`); asking them to accept again is a
 * separate decision.
 */
final class LegalDocuments
{
    public const VERSION = '2026-11-25';
}
