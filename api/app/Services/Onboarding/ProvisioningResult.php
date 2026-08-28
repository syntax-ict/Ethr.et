<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

/**
 * Per-resource tally of what an OrganizationProvisioner run actually did.
 *
 * Provisioning is create-only: an existing record with the same natural key is
 * reported as `skipped`, never overwritten, so re-applying a template can never
 * clobber configuration the tenant has since edited by hand.
 */
final class ProvisioningResult
{
    /** @var array<string, array{created: int, skipped: int, restored: int}> */
    private array $resources = [];

    /** @var array<int, string> */
    private array $warnings = [];

    public function created(string $resource): void
    {
        $this->bump($resource, 'created');
    }

    public function skipped(string $resource): void
    {
        $this->bump($resource, 'skipped');
    }

    public function restored(string $resource): void
    {
        $this->bump($resource, 'restored');
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function totalCreated(): int
    {
        return array_sum(array_column($this->resources, 'created'));
    }

    /** @return array{resources: array<string, array{created: int, skipped: int, restored: int}>, total_created: int, warnings: array<int, string>} */
    public function toArray(): array
    {
        ksort($this->resources);

        return [
            'resources' => $this->resources,
            'total_created' => $this->totalCreated(),
            'warnings' => $this->warnings,
        ];
    }

    private function bump(string $resource, string $bucket): void
    {
        if (! isset($this->resources[$resource])) {
            $this->resources[$resource] = ['created' => 0, 'skipped' => 0, 'restored' => 0];
        }

        $this->resources[$resource][$bucket]++;
    }
}
