<?php

declare(strict_types=1);

namespace App\Services\Auth;

use RuntimeException;

/**
 * A code could not be issued. `$reason` is one of: tenant, document, self,
 * role, status, contact, identifier.
 */
final class ActivationCodeRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Activation code refused: '.$reason);
    }
}
