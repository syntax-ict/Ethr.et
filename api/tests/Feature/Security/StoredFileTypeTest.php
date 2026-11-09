<?php

declare(strict_types=1);

use App\Services\CurrentTenant;
use App\Services\FileStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * A stored file was named `ULID.<the client's extension>`, and the signed file
 * route takes its Content-Type from the extension whenever the content alone
 * is inconclusive, which plain text always is. So `x.html`, declared and
 * detected as `text/plain`, was kept as `.html` and served back inline as
 * `text/html` (review 2026-11-09). The extension now comes from the detected
 * type, and every stored file leaves the route as a download that is not
 * sniffed, which also covers files stored before the change.
 */
/** A real upload over a temporary file: a fake one reports its type from its name. */
function uploadedWith(string $name, string $declared, string $content): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'upload');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, $declared, null, true);
}

it('names a stored file by the type its content was detected as, never by the name it was sent with', function () {
    Storage::fake('local');
    app(CurrentTenant::class)->set(createTenant());

    $stored = app(FileStorageService::class)->upload(
        uploadedWith('x.html', 'text/plain', "Any text at all is a page once it is served as text/html.\n"),
        'documents',
    );

    expect($stored['mime_type'])->toBe('text/plain')
        ->and($stored['path'])->toEndWith('.txt')
        ->and($stored['filename'])->not->toContain('html')
        ->and($stored['original_name'])->toBe('x.html');
    Storage::disk('local')->assertExists($stored['path']);
});

it('stores content detected as a page under .bin, whatever it was called', function () {
    Storage::fake('local');
    app(CurrentTenant::class)->set(createTenant());

    $stored = app(FileStorageService::class)->upload(
        uploadedWith('notes.txt', 'text/plain', "<!DOCTYPE html><html><body><script>alert(1)</script></body></html>\n"),
        'documents',
    );

    expect($stored['mime_type'])->toBe('text/html')
        ->and($stored['path'])->toEndWith('.bin');
});

it('maps each type an upload may be to its own extension, and anything else to .bin', function (?string $mime, string $extension) {
    expect(FileStorageService::extensionFor($mime))->toBe($extension);
})->with([
    ['application/pdf', 'pdf'],
    ['image/png', 'png'],
    ['image/jpeg', 'jpg'],
    ['text/plain', 'txt'],
    ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx'],
    ['text/html', 'bin'],
    ['image/svg+xml', 'bin'],
    ['application/x-php', 'bin'],
    [null, 'bin'],
]);

it('serves a stored file as a download whose type is not sniffed, even one stored under .html', function () {
    Storage::fake('local');
    $path = 'tenants/01JTESTTENANT0000000000000/documents/01JTESTFILE00000000000000.html';
    Storage::disk('local')->put($path, "hello <svg onload=alert(1)>\n");

    // Signed as the real local disk signs it; Storage::fake() swaps in an
    // unsigned URL builder that does not reach the route.
    $response = $this->get(URL::temporarySignedRoute('storage.local', now()->addMinutes(5), ['path' => $path], absolute: false));

    $response->assertOk();
    expect((string) $response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and((string) $response->headers->get('Content-Disposition'))->toContain('01JTESTFILE00000000000000.html')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('still refuses a stored file without a valid signature', function () {
    Storage::fake('local');
    $path = 'tenants/01JTESTTENANT0000000000000/documents/01JTESTFILE00000000000001.txt';
    Storage::disk('local')->put($path, 'hello');

    $this->get('/api/files/'.$path)->assertStatus(403);
});
