<?php

declare(strict_types=1);

use App\Models\Tenant;

/**
 * GET /auth/tenant-context is the pre-auth endpoint the login page reads to
 * render "Sign in to {Organization}" and the tenant logo. It adds no resolution
 * logic of its own — ResolveTenant resolves the tenant from the host first — so
 * these tests pin two things: the host-resolved tenant is echoed back as the
 * safe display subset, and an apex/no-tenant host yields an explicit null
 * rather than leaking anything.
 */
it('returns the host-resolved tenant display info', function () {
    Tenant::factory()->create([
        'name' => 'Acme Corporation',
        'subdomain' => 'acme',
        'logo_path' => 'tenants/acme/logo.png',
    ]);

    $this->getJson('http://acme.ethr.et/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertExactJson([
            'tenant' => [
                'name' => 'Acme Corporation',
                'subdomain' => 'acme',
                'logo_path' => 'tenants/acme/logo.png',
            ],
        ]);
});

it('never exposes the internal numeric id', function () {
    Tenant::factory()->create(['subdomain' => 'acme']);

    $this->getJson('http://acme.ethr.et/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertJsonMissingPath('tenant.id');
});

it('returns a null tenant on a host with no subdomain', function () {
    $this->getJson('http://localhost/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertExactJson(['tenant' => null]);
});

it('returns the tenant-not-found problem for an unknown subdomain', function () {
    $this->getJson('http://ghost.ethr.et/api/v1/auth/tenant-context')
        ->assertNotFound()
        ->assertJsonPath('type', 'https://ethr.et/errors/tenant-not-found');
});
