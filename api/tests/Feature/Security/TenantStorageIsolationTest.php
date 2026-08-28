<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\FileStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * File storage isolation, exercised rather than inspected.
 *
 * The audit marked MinIO PASS by reading `tenantPrefix()` and concluding the
 * paths looked right. That is the same standard of evidence that passed the
 * `device.*` broadcast channel, which turned out to check a permission that did
 * not exist — so the prefix is asserted here against a real write instead.
 *
 * Worth being explicit about what this does and does not prove: isolation here
 * is enforced entirely by application code building the right string. There is
 * one shared bucket, no per-tenant IAM policy, and nothing at the storage layer
 * that would stop a path traversal or a hand-built key from crossing tenants.
 * These tests cover the code path that constructs keys; they cannot cover a
 * caller that bypasses FileStorageService.
 */
beforeEach(function () {
    Storage::fake('minio');
});

function tenantContext(string $subdomain): Tenant
{
    $tenant = Tenant::factory()->create(['subdomain' => $subdomain]);
    app(CurrentTenant::class)->set($tenant);

    return $tenant;
}

it('writes uploads under the current tenant prefix', function () {
    $tenant = tenantContext('habru');

    $result = app(FileStorageService::class)->upload(
        UploadedFile::fake()->create('contract.pdf', 8, 'application/pdf'),
        'documents',
    );

    expect($result['path'])->toStartWith("tenants/{$tenant->public_id}/documents/");
    Storage::disk('minio')->assertExists($result['path']);
});

it('gives two tenants disjoint prefixes for the same directory', function () {
    $habru = tenantContext('habru');
    $habruPath = app(FileStorageService::class)->upload(
        UploadedFile::fake()->create('a.pdf', 8, 'application/pdf'),
        'documents',
    )['path'];

    // A second service instance, because the first captured CurrentTenant at
    // construction — resolving fresh is what a new request would do.
    $woldia = tenantContext('woldia');
    $woldiaPath = app()->make(FileStorageService::class)->upload(
        UploadedFile::fake()->create('b.pdf', 8, 'application/pdf'),
        'documents',
    )['path'];

    expect($habruPath)->toStartWith("tenants/{$habru->public_id}/")
        ->and($woldiaPath)->toStartWith("tenants/{$woldia->public_id}/")
        ->and($habru->public_id)->not->toBe($woldia->public_id);

    // Same directory name, same filename shape, different tenants — the prefix
    // is the only thing keeping them apart, so assert it actually does.
    expect(dirname($habruPath))->not->toBe(dirname($woldiaPath));
});

it('refuses a directory that would climb out of the tenant prefix', function (string $directory) {
    tenantContext('habru');

    // Measured before the guard existed: uploading with `documents/../..`
    // returned the key `tenants/{public_id}/documents/../../file.pdf`, which
    // Flysystem normalised on write to `tenants/file.pdf` — outside the tenant's
    // space, beside every other tenant's folder. One shared bucket and no
    // per-tenant IAM policy means the prefix is the whole isolation model.
    expect(fn () => app(FileStorageService::class)->upload(
        UploadedFile::fake()->create('x.pdf', 8, 'application/pdf'),
        $directory,
    ))->toThrow(InvalidArgumentException::class);

    // Nothing reached the disk on the way to failing.
    expect(Storage::disk('minio')->allFiles())->toBeEmpty();
})->with([
    'parent traversal' => 'documents/../..',
    'single parent' => '..',
    'backslash traversal' => 'documents\\..\\..',
    'empty segment' => 'documents//escape',
    'current dir' => 'documents/./x',
]);
