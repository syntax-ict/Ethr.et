<?php

declare(strict_types=1);

namespace Tests\Support;

/** A CBOR byte string, as opposed to a text string (SoftAuthenticator). */
final class CborBytes
{
    public function __construct(public readonly string $bytes) {}
}
