<?php

declare(strict_types=1);

namespace App\Services\Features;

use App\Enums\FeatureReason;

/**
 * The answer for one feature and one organisation. `visible` is false when the
 * feature is off or the platform has hidden it.
 */
final readonly class FeatureDecision
{
    private function __construct(
        public bool $on,
        public bool $visible,
        public FeatureReason $reason,
    ) {}

    public static function on(FeatureReason $reason, bool $visible): self
    {
        return new self(true, $visible, $reason);
    }

    public static function off(FeatureReason $reason): self
    {
        return new self(false, false, $reason);
    }

    /** @return array{on: bool, visible: bool, reason: string} */
    public function toArray(): array
    {
        return [
            'on' => $this->on,
            'visible' => $this->visible,
            'reason' => $this->reason->value,
        ];
    }
}
