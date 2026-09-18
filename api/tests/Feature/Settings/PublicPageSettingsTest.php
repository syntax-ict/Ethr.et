<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantPublicProfile;
use App\Services\CurrentTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The authenticated half — how a tenant administrator configures the page.
 *
 * Two things here are worth more than the CRUD coverage around them. First,
 * publishing is audited as its own event: an organisation's page becoming
 * visible on the public internet is a different fact from someone fixing a typo
 * in it, and a reviewer asking "when did this become public, and who decided
 * that" should not have to infer it from a diff. Second, every URL field is
 * validated at write time as well as at render time, because a payload that is
 * only caught at render is a payload sitting in the database.
 */
beforeEach(function () {
    config(['app.domain' => 'ethr.et']);
    Storage::fake(config('filesystems.default'));
});

function tenantAdmin(): array
{
    $tenant = createTenant(['subdomain' => 'habru']);
    $admin = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    return [$tenant, $admin];
}

// ── Reading ─────────────────────────────────────────────────────────────────

it('returns an empty but complete shape for a tenant that has never configured one', function () {
    tenantAdmin();

    // The settings screen must render an empty form, not an error, on a tenant
    // that has never opened this page.
    $this->getJson('http://habru.ethr.et/api/v1/settings/public-page')
        ->assertOk()
        ->assertJsonPath('public_page.is_published', false)
        ->assertJsonPath('public_page.headline', null)
        ->assertJsonPath('public_page.url', 'https://habru.ethr.et');
});

it('never exposes the numeric primary key of the profile', function () {
    [$tenant] = tenantAdmin();
    TenantPublicProfile::factory()->create(['tenant_id' => $tenant->id]);

    $this->getJson('http://habru.ethr.et/api/v1/settings/public-page')
        ->assertOk()
        ->assertJsonMissingPath('public_page.id')
        ->assertJsonMissingPath('public_page.tenant_id');
});

// ── Writing ─────────────────────────────────────────────────────────────────

it('creates the profile row lazily on first save', function () {
    [$tenant] = tenantAdmin();

    expect(TenantPublicProfile::query()->count())->toBe(0);

    $this->putJson('http://habru.ethr.et/api/v1/settings/public-page', [
        'headline' => 'Weaving since 1974',
    ])->assertOk();

    expect(TenantPublicProfile::query()->count())->toBe(1);
});

it('does not publish a page just because it was edited', function () {
    tenantAdmin();

    $this->putJson('http://habru.ethr.et/api/v1/settings/public-page', [
        'headline' => 'Weaving since 1974',
    ])->assertOk()->assertJsonPath('public_page.is_published', false);

    // Opt-in means opt-in. Filling in content must never be read as consent to
    // put the organisation on the public internet.
    $this->get('http://habru.ethr.et/')->assertNotFound();
});

it('publishes only when explicitly asked, and the page appears', function () {
    tenantAdmin();

    $this->putJson('http://habru.ethr.et/api/v1/settings/public-page', [
        'headline' => 'Weaving since 1974',
        'is_published' => true,
    ])->assertOk()->assertJsonPath('public_page.is_published', true);

    $this->get('http://habru.ethr.et/')->assertOk()->assertSee('Weaving since 1974');
});

it('records publication as its own audit event', function () {
    [$tenant] = tenantAdmin();

    $this->putJson('http://habru.ethr.et/api/v1/settings/public-page', ['is_published' => true])
        ->assertOk();

    expect(AuditLog::query()->where('action', 'settings.public_page_published')->exists())->toBeTrue();
});

it('records unpublication as its own audit event', function () {
    [$tenant] = tenantAdmin();
    TenantPublicProfile::factory()->published()->create(['tenant_id' => $tenant->id]);

    $this->putJson('http://habru.ethr.et/api/v1/settings/public-page', ['is_published' => false])
        ->assertOk();

    expect(AuditLog::query()->where('action', 'settings.public_page_unpublished')->exists())->toBeTrue();
});

it('keeps the original publication date across later edits', function () {
    [$tenant] = tenantAdmin();

    $this->putJson('http://habru.ethr.et/api/v1/settings/public-page', ['is_published' => true])->assertOk();
    $first = TenantPublicProfile::query()->firstOrFail()->published_at;

    $this->travel(2)->days();
    $this->putJson('http://habru.ethr.et/api/v1/settings/public-page', ['headline' => 'Edited'])->assertOk();

    // `published_at` is the date a visitor or a crawler would mean, not a
    // "last saved" timestamp.
    expect(TenantPublicProfile::query()->firstOrFail()->published_at->timestamp)
        ->toBe($first->timestamp);
});

it('unpublishes immediately, taking the page down', function () {
    [$tenant] = tenantAdmin();
    TenantPublicProfile::factory()->published()->create(['tenant_id' => $tenant->id]);

    $this->get('http://habru.ethr.et/')->assertOk();

    $this->putJson('http://habru.ethr.et/api/v1/settings/public-page', ['is_published' => false])->assertOk();

    $this->get('http://habru.ethr.et/')->assertNotFound();
});

// ── Authorization ───────────────────────────────────────────────────────────

it('refuses a tenant member without settings.manage', function () {
    $tenant = createTenant(['subdomain' => 'habru']);
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    $this->putJson('http://habru.ethr.et/api/v1/settings/public-page', ['headline' => 'Nope'])
        ->assertForbidden();
});

it('refuses an anonymous caller', function () {
    createTenant(['subdomain' => 'habru']);

    $this->putJson('http://habru.ethr.et/api/v1/settings/public-page', ['headline' => 'Nope'])
        ->assertUnauthorized();
});

it('refuses an administrator of one tenant editing another tenants page', function () {
    $habru = createTenant(['subdomain' => 'habru']);
    $habruAdmin = createUser(['role' => UserRole::TENANT_ADMIN], $habru);

    $woldia = Tenant::factory()->create(['subdomain' => 'woldia']);
    app(CurrentTenant::class)->set($woldia);
    TenantPublicProfile::factory()->create(['tenant_id' => $woldia->id, 'headline' => 'Woldia original']);
    app(CurrentTenant::class)->set($habru);

    $token = $habruAdmin->createToken('auth', ['*'])->plainTextToken;

    $this->withToken($token)
        ->putJson('http://woldia.ethr.et/api/v1/settings/public-page', ['headline' => 'Hijacked'])
        ->assertForbidden();

    expect(TenantPublicProfile::withoutGlobalScopes()->where('tenant_id', $woldia->id)->value('headline'))
        ->toBe('Woldia original');
});

// ── Validation ──────────────────────────────────────────────────────────────

it('refuses a website url that is not http or https', function (string $url) {
    tenantAdmin();

    $this->putJson('http://habru.ethr.et/api/v1/settings/public-page', ['website_url' => $url])
        ->assertStatus(422)
        ->assertJsonValidationErrors('website_url');
})->with([
    'javascript:alert(1)',
    'data:text/html,<script>alert(1)</script>',
    'file:///etc/passwd',
    'not a url at all',
]);

it('refuses a social link that is not on that platforms own host', function () {
    tenantAdmin();

    $this->putJson('http://habru.ethr.et/api/v1/settings/public-page', [
        'social_links' => ['facebook' => 'https://phishing.example.com/habru'],
    ])->assertStatus(422)->assertJsonValidationErrors('social_links.facebook');
});

it('accepts a social link on the real host', function () {
    tenantAdmin();

    $this->putJson('http://habru.ethr.et/api/v1/settings/public-page', [
        'social_links' => ['telegram' => 'https://t.me/habru'],
    ])->assertOk();
});

it('rejects an unknown social platform rather than silently dropping it', function () {
    tenantAdmin();

    // Dropping it would let an administrator watch their link fail to save with
    // no error and no explanation.
    $this->putJson('http://habru.ethr.et/api/v1/settings/public-page', [
        'social_links' => ['myspace' => 'https://myspace.com/habru'],
    ])->assertStatus(422)->assertJsonValidationErrors('social_links');
});

it('caps the free text fields', function () {
    tenantAdmin();

    $this->putJson('http://habru.ethr.et/api/v1/settings/public-page', [
        'description' => str_repeat('a', 4001),
        'headline' => str_repeat('b', 161),
    ])->assertStatus(422)->assertJsonValidationErrors(['description', 'headline']);
});

// ── Image upload ────────────────────────────────────────────────────────────

it('stores an uploaded logo under the tenants own prefix', function () {
    [$tenant] = tenantAdmin();

    $this->post('http://habru.ethr.et/api/v1/settings/branding/logo', [
        'image' => UploadedFile::fake()->image('logo.png', 400, 400),
    ])->assertCreated();

    expect($tenant->refresh()->logo_path)->toStartWith("tenants/{$tenant->public_id}/public/logo/");
});

it('makes an uploaded logo visible on the published page', function () {
    [$tenant] = tenantAdmin();
    TenantPublicProfile::factory()->published()->create(['tenant_id' => $tenant->id]);

    $this->post('http://habru.ethr.et/api/v1/settings/branding/logo', [
        'image' => UploadedFile::fake()->image('logo.png', 400, 400),
    ])->assertCreated();

    $this->get('http://habru.ethr.et/media/logo')->assertOk();
});

it('reports whether the stored logo will actually appear publicly', function () {
    [$tenant] = tenantAdmin();
    TenantPublicProfile::factory()->create(['tenant_id' => $tenant->id]);

    // A legacy external URL is not renderable publicly. The settings screen
    // needs to know so it can prompt for a re-upload rather than leaving an
    // administrator wondering why the logo never appears.
    $tenant->update(['logo_path' => 'https://cdn.example.com/logo.png']);

    $this->getJson('http://habru.ethr.et/api/v1/settings/public-page')
        ->assertOk()
        ->assertJsonPath('public_page.has_public_logo', false);
});

it('refuses an oversized image', function () {
    tenantAdmin();

    $this->post('http://habru.ethr.et/api/v1/settings/branding/logo', [
        'image' => UploadedFile::fake()->create('huge.png', 4096, 'image/png'),
    ])->assertStatus(422)->assertJsonValidationErrors('image');
});

it('refuses a file that is not one of the accepted image types', function () {
    tenantAdmin();

    $this->post('http://habru.ethr.et/api/v1/settings/public-page/hero', [
        'image' => UploadedFile::fake()->create('payload.svg', 8, 'image/svg+xml'),
    ])->assertStatus(422);
});

it('refuses a file whose bytes do not match its declared type', function () {
    tenantAdmin();

    // VerifyUploadedFiles checks magic bytes against the declared content type
    // (convention 15), so a PHP script renamed to .png never reaches storage.
    $this->post('http://habru.ethr.et/api/v1/settings/branding/logo', [
        'image' => UploadedFile::fake()->createWithContent('logo.png', '<?php echo "pwned";'),
    ])->assertStatus(422);
});
