<?php

declare(strict_types=1);

namespace Tests\Accuracy\Payroll;

use RuntimeException;

/**
 * The harness could not put a case's inputs into the database, or the engine
 * did not take them as stated. Never a finding: it says the test, not the
 * engine, is what to look at first (doc 12 section 6).
 */
final class FixtureError extends RuntimeException
{
    public const FIXTURE = 'FIXTURE ERROR';

    public const NOT_COVERABLE = 'NOT COVERABLE';

    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct("{$kind}: {$message}");
    }

    public static function fixture(string $message): self
    {
        return new self(self::FIXTURE, $message);
    }

    public static function notCoverable(string $message): self
    {
        return new self(self::NOT_COVERABLE, $message);
    }
}
