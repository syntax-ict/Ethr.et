<?php

declare(strict_types=1);

namespace Tests\Accuracy\Oracle;

/** "The register does not say, and I do not model it." Never asserted. */
final class NoOpinion
{
    public function __construct(public readonly string $why) {}
}
