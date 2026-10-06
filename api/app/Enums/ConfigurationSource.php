<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a piece of a smart-configuration plan came from, and how much to trust
 * it. This is the deterministic, auditable substitute for an LLM confidence
 * score — see ONBOARDING_V2.md decision D2. Confidence is a pure function of
 * provenance, so the same inputs always yield the same score.
 */
enum ConfigurationSource: string
{
    /** The industry's own override patch set this explicitly. */
    case EXPLICIT = 'explicit';

    /** Inherited from the base organization template for the industry. */
    case INDUSTRY_DEFAULT = 'industry_default';

    /** Derived from tenant signals (size, region) rather than a template. */
    case HEURISTIC = 'heuristic';

    /** Last-resort default when nothing else applied. */
    case GLOBAL_FALLBACK = 'global_fallback';

    public function confidence(): float
    {
        return match ($this) {
            self::EXPLICIT => 1.0,
            self::INDUSTRY_DEFAULT => 0.8,
            self::HEURISTIC => 0.6,
            self::GLOBAL_FALLBACK => 0.4,
        };
    }
}
