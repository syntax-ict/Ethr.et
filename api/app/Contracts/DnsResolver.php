<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * The two DNS questions custom-domain verification asks.
 *
 * Behind an interface so tests can answer them without the network, and so a
 * host that blocks PHP's resolver can be given another one without touching
 * the verifier.
 */
interface DnsResolver
{
    /**
     * Every TXT string published at `$name`, or [] when there is none.
     *
     * @return list<string>
     */
    public function txt(string $name): array;

    /**
     * The target `$name` is a CNAME for, lower-case with no trailing dot, or
     * null when it has no CNAME record.
     */
    public function cname(string $name): ?string;
}
