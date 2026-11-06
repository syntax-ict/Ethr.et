<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\TenantRequired;
use App\Models\Tenant;

class CurrentTenant
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    /**
     * The tenant with this id: the one already resolved when it is that one,
     * else read. A payroll run asked for its own tenant three times per
     * employee, 1,500 reads in a run of 500 (A-Z review I, payroll at scale).
     */
    public function tenantFor(int $id): ?Tenant
    {
        return $this->tenant?->id === $id ? $this->tenant : Tenant::query()->find($id);
    }

    /**
     * The tenant's id, for an endpoint that only means something inside one
     * organisation. With none resolved (a platform administrator) it refuses
     * with a 422 rather than reading `->id` on null.
     *
     * @throws TenantRequired
     */
    public function requireId(): int
    {
        return $this->tenant->id ?? throw new TenantRequired;
    }

    public function publicId(): ?string
    {
        return $this->tenant?->public_id;
    }

    public function resolved(): bool
    {
        return $this->tenant !== null;
    }

    public function forget(): void
    {
        $this->tenant = null;
    }
}
