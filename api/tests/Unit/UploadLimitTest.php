<?php

declare(strict_types=1);

use App\Support\UploadLimit;

/*
 * The largest upload the server really accepts (2026-10-17): production runs
 * PHP's stock 2M / 8M, and the app's own 5 MB and 10 MB limits mean nothing
 * above them. These pin how php.ini sizes are read and that the figure is the
 * smaller of the two limits, less room for the form's other fields.
 */

it('reads php.ini sizes the way PHP writes them', function (string $value, ?int $bytes) {
    expect(UploadLimit::parse($value))->toBe($bytes);
})->with([
    ['2M', 2 * 1024 * 1024],
    ['512K', 512 * 1024],
    ['1G', 1024 * 1024 * 1024],
    ['8388608', 8388608],
    ['10m', 10 * 1024 * 1024],
    ['0', null],
    ['-1', null],
    ['', null],
    ['lots', null],
]);

it('is the smaller of the upload and post limits, leaving room for the form', function () {
    $upload = UploadLimit::parse((string) ini_get('upload_max_filesize'));
    $post = UploadLimit::parse((string) ini_get('post_max_size'));
    $expected = min(array_filter([$upload, $post === null ? null : $post - 64 * 1024], fn ($v) => $v !== null) ?: [1024 * 1024 * 1024]);

    expect(UploadLimit::bytes())->toBe($expected)->toBeGreaterThan(0);
});

it('says a size the way the frontend does', function (int $bytes, string $label) {
    expect(UploadLimit::label($bytes))->toBe($label);
})->with([
    [2 * 1024 * 1024, '2 MB'],
    [(int) (2.5 * 1024 * 1024), '2.5 MB'],
    [512 * 1024, '512 KB'],
    [10, '1 KB'],
]);
