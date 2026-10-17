<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * Documents attached to a news story (2026-10-16): PDFs only, checked by
 * their own bytes, at most three. Downloaded in the app on the terms the
 * story is read on, and publicly only while the story is public. Every
 * download is an attachment with a sandboxing policy, never a page.
 */

beforeEach(function () {
    Storage::fake((string) config('filesystems.default'));
});

function fileStory(Tenant $tenant, array $attributes = []): Announcement
{
    return Announcement::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'published_by' => createUser([], $tenant)->id,
        'title' => 'New leave policy',
        'body' => 'The circular is attached.',
        'priority' => 'normal',
        'target_type' => 'all',
        'published_at' => now()->subHour(),
        'show_on_landing' => true,
        ...$attributes,
    ]);
}

function pdf(string $name = 'Leave circular.pdf', string $body = 'circular'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% {$body}\n%%EOF\n");
}

function attach(Announcement $story, UploadedFile $file): TestResponse
{
    return test()->post("http://acme.ethr.test/api/v1/announcements/{$story->public_id}/files", ['file' => $file], ['Accept' => 'application/json']);
}

function deskWithStory(array $attributes = []): array
{
    $tenant = createTenant(['subdomain' => 'acme']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    return [$tenant, fileStory($tenant, $attributes)];
}

it('attaches a PDF, lists it by name and size, and audits it', function () {
    [, $story] = deskWithStory();

    $files = attach($story, pdf())->assertOk()->json('attachments');

    expect($files)->toHaveCount(1)
        ->and($files[0]['name'])->toBe('Leave circular.pdf')
        ->and($files[0]['size'])->toBeGreaterThan(0)
        ->and($files[0]['id'])->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($files[0]['url'])->toBeNull()
        ->and(AuditLog::where('action', 'announcement.file_attached')->exists())->toBeTrue();
});

it('refuses anything that is not a PDF by its own bytes, and a fourth document', function () {
    [, $story] = deskWithStory();

    attach($story, UploadedFile::fake()->createWithContent('fake.pdf', '<html><script>alert(1)</script></html>'))
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    attach($story, UploadedFile::fake()->image('photo.jpg'))
        ->assertUnprocessable()->assertJsonValidationErrors('file');

    foreach (range(1, 3) as $i) {
        attach($story, pdf("Form {$i}.pdf"))->assertOk();
    }
    attach($story, pdf('Form 4.pdf'))->assertUnprocessable()->assertJsonValidationErrors('file');

    expect($story->refresh()->attachedFiles())->toHaveCount(3);
});

it('keeps a clean download name whatever the upload was called', function () {
    [, $story] = deskWithStory();

    $name = attach($story, pdf("..\\../evil\x07name.PDF"))->assertOk()->json('attachments.0.name');

    expect($name)->toBe('evil name.pdf');
});

it('downloads in the app as an attachment for readers, and not a draft for them', function () {
    [$tenant, $story] = deskWithStory();
    $id = attach($story, pdf())->assertOk()->json('attachments.0.id');
    $draft = fileStory($tenant, ['published_at' => null]);
    $draftId = attach($draft, pdf())->assertOk()->json('attachments.0.id');

    app('auth')->forgetGuards();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    $response = test()->get("http://acme.ethr.test/api/v1/announcements/{$story->public_id}/files/{$id}")->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and($response->headers->get('Content-Disposition'))->toContain('Leave circular.pdf')
        ->and($response->headers->get('Content-Security-Policy'))->toStartWith("default-src 'none'")
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and((string) $response->getContent())->toStartWith('%PDF');

    test()->getJson("http://acme.ethr.test/api/v1/announcements/{$draft->public_id}/files/{$draftId}")->assertNotFound();
});

it('serves a public story\'s document publicly, and stops when the story stops being public', function () {
    [$tenant, $story] = deskWithStory();
    attach($story, pdf())->assertOk();

    app('auth')->forgetGuards();
    $file = test()->getJson("http://acme.ethr.test/api/v1/auth/tenant-landing/announcements/{$story->public_id}")
        ->assertOk()
        ->json('attachments.0');
    expect($file['url'])->toBe("/api/v1/news-files/{$tenant->public_id}/{$story->public_id}/{$file['id']}");

    $response = test()->get("http://www.ethr.test{$file['url']}")->assertOk();
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=300')
        ->and($response->headers->get('Content-Security-Policy'))->toStartWith("default-src 'none'");

    $story->forceFill(['show_on_landing' => false])->save();
    test()->get("http://www.ethr.test{$file['url']}")->assertNotFound();

    $story->forceFill(['show_on_landing' => true, 'published_at' => null])->save();
    test()->get("http://www.ethr.test{$file['url']}")->assertNotFound();

    $story->forceFill(['published_at' => now()->subHour()])->save();
    $tenant->update(['status' => TenantStatus::SUSPENDED]);
    test()->get("http://www.ethr.test{$file['url']}")->assertNotFound();
});

it('answers only for the organisation the address names, and not on another organisation\'s host', function () {
    [$tenant, $story] = deskWithStory();
    $id = attach($story, pdf())->assertOk()->json('attachments.0.id');
    $other = createTenant(['subdomain' => 'habru']);
    app('auth')->forgetGuards();

    test()->get("http://www.ethr.test/api/v1/news-files/{$other->public_id}/{$story->public_id}/{$id}")->assertNotFound();
    test()->get("http://habru.ethr.test/api/v1/news-files/{$tenant->public_id}/{$story->public_id}/{$id}")->assertNotFound();
    test()->get("http://acme.ethr.test/api/v1/news-files/{$tenant->public_id}/{$story->public_id}/{$id}")->assertOk();
});

it('removes a document, and deletes every file with its story', function () {
    [$tenant, $story] = deskWithStory();
    $first = attach($story, pdf('One.pdf'))->assertOk()->json('attachments.0.id');
    attach($story, pdf('Two.pdf'))->assertOk();

    test()->deleteJson("http://acme.ethr.test/api/v1/announcements/{$story->public_id}/files/{$first}")
        ->assertOk()
        ->assertJsonCount(1, 'attachments')
        ->assertJsonPath('attachments.0.name', 'Two.pdf');
    expect(AuditLog::where('action', 'announcement.file_removed')->exists())->toBeTrue()
        ->and(Storage::disk((string) config('filesystems.default'))->allFiles("tenants/{$tenant->public_id}/news"))->toHaveCount(1);

    test()->deleteJson("http://acme.ethr.test/api/v1/announcements/{$story->public_id}")->assertNoContent();
    expect(Storage::disk((string) config('filesystems.default'))->allFiles("tenants/{$tenant->public_id}/news"))->toBe([]);
});

it('lets only a manager attach or remove, and never on another organisation\'s story', function () {
    $tenant = createTenant(['subdomain' => 'acme']);
    $story = fileStory($tenant);
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    attach($story, pdf())->assertForbidden();
    test()->deleteJson("http://acme.ethr.test/api/v1/announcements/{$story->public_id}/files/01JZZZZZZZZZZZZZZZZZZZZZZZ")->assertForbidden();

    app('auth')->forgetGuards();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $foreign = fileStory(createTenant(['subdomain' => 'habru']));
    attach($foreign, pdf())->assertNotFound();
    expect($foreign->refresh()->attachedFiles())->toBe([]);
});
