<?php

declare(strict_types=1);

namespace App\Services\Auth;

use RuntimeException;

/**
 * Why a batch was given no starting password: 'tenant', 'identifier' (the
 * organisation does not sign in by employee number) or 'nobody' (none of the
 * people named can be given one). `skipped` says why each was left out.
 */
final class StartingPasswordRefused extends RuntimeException
{
    /** @param  array<string, int>  $skipped */
    public function __construct(public readonly string $reason, public readonly array $skipped = [])
    {
        parent::__construct("Starting password refused: {$reason}");
    }
}
