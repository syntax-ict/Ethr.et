<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\TenantPublicProfile;
use App\Models\User;

/**
 * Who may dress their public page as a government office.
 *
 * Seven of the eight presets are a taste decision. `government` is not: on
 * `*.ethr.et`, state-official styling tells a visitor something about who they
 * are dealing with, and it borrows ETHR's own domain to say it.
 *
 * The test that matters most here is the third one. The obvious implementation
 * gates on the tenant's declared industry — and both signals behind that are
 * written by the tenant itself, so a private company need only claim to be a
 * ministry. That version passes every other test in this file and fails the
 * one case the control exists for.
 */
beforeEach(function () {
    config(['app.domain' => 'ethr.et']);
});

/** @return array{0: Tenant, 1: User} */
function governmentLockAdmin(array $tenantAttributes = []): array
{
    $tenant = createTenant([
        'subdomain' => 'habru',
        ...$tenantAttributes,
    ]);

    $admin = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    return [$tenant, $admin];
}

function putPreset(?string $preset)
{
    return test()->putJson('http://habru.ethr.et/api/v1/settings/public-page', [
        'preset' => $preset,
    ]);
}

// ── The ordinary cases ──────────────────────────────────────────────────────

it('lets any tenant choose a preset that carries no claim about them', function (string $preset) {
    governmentLockAdmin(['type' => 'manufacturing']);

    putPreset($preset)->assertOk()->assertJsonPath('public_page.preset', $preset);
})->with(['university', 'hospital', 'ngo', 'bank', 'manufacturing', 'hotel', 'general']);

it('lets a tenant return to the classic layout by clearing the preset', function () {
    [$tenant] = governmentLockAdmin(['type' => 'hotel']);
    TenantPublicProfile::factory()->create(['tenant_id' => $tenant->id, 'preset' => 'hotel']);

    // Null is a real choice, not a missing value. Without this an opt-in is a
    // one-way door.
    putPreset(null)->assertOk()->assertJsonPath('public_page.preset', null);
});

it('rejects a preset that does not exist', function () {
    governmentLockAdmin();

    putPreset('ministry_of_magic')
        ->assertStatus(422)
        ->assertJsonPath('errors.preset.0', 'The selected preset is not a valid layout.');
});

// ── The case the control exists for ─────────────────────────────────────────

it('refuses the government layout to a tenant that merely says it is a ministry', function () {
    // Everything a tenant can set about itself, set to government. The industry
    // key is even valid against IndustryCatalog — it is simply self-declared,
    // like the type column beside it, which is validated as `string|max:50`
    // with no `in:` rule at all.
    governmentLockAdmin([
        'type' => 'government',
        'settings' => ['industry' => 'ministry'],
        'government_verified_at' => null,
    ]);

    putPreset('government')
        ->assertStatus(422)
        ->assertJsonPath(
            'errors.preset.0',
            'The government layout is available only to verified government organizations.'
        );
});

it('allows the government layout once the platform has verified the tenant', function () {
    governmentLockAdmin([
        'type' => 'government',
        'settings' => ['industry' => 'ministry'],
        'government_verified_at' => now(),
    ]);

    putPreset('government')->assertOk()->assertJsonPath('public_page.preset', 'government');
});

it('refuses the government layout to a verified tenant that has since become something else', function () {
    // Verification is a statement about an organisation, not a permanent
    // entitlement attached to a row. A body that reorganises into a private
    // company should stop looking like a government office.
    governmentLockAdmin([
        'type' => 'hotel',
        'settings' => ['industry' => 'hotel'],
        'government_verified_at' => now(),
    ]);

    putPreset('government')->assertStatus(422);
});

// ── The tenant cannot grant itself the thing that gates it ──────────────────

it('ignores a verification timestamp sent in a settings payload', function () {
    [$tenant] = governmentLockAdmin([
        'type' => 'government',
        'settings' => ['industry' => 'ministry'],
    ]);

    test()->putJson('http://habru.ethr.et/api/v1/settings/organization', [
        'name' => $tenant->name,
        'type' => 'government',
        'government_verified_at' => now()->toIso8601String(),
    ]);

    // Mass assignment is the obvious way this control gets bypassed, so the
    // column is absent from $fillable and this asserts the consequence rather
    // than the mechanism.
    expect($tenant->fresh()->government_verified_at)->toBeNull();

    putPreset('government')->assertStatus(422);
});

it('does not offer the government layout to an unverified tenant', function () {
    governmentLockAdmin(['type' => 'government', 'settings' => ['industry' => 'ministry']]);

    $available = test()->getJson('http://habru.ethr.et/api/v1/settings/public-page')
        ->assertOk()
        ->json('public_page.available_presets');

    // The screen should not present a choice the API will refuse.
    expect($available)->not->toContain('government');
});

it('offers the government layout to a verified tenant', function () {
    governmentLockAdmin([
        'type' => 'government',
        'settings' => ['industry' => 'ministry'],
        'government_verified_at' => now(),
    ]);

    $available = test()->getJson('http://habru.ethr.et/api/v1/settings/public-page')
        ->assertOk()
        ->json('public_page.available_presets');

    expect($available)->toContain('government');
});
