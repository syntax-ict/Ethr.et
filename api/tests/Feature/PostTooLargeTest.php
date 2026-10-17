<?php

declare(strict_types=1);

use App\Support\UploadLimit;

/*
 * An upload over PHP's post_max_size is refused by global middleware before
 * the app sees it (production: 8M, measured 2026-10-17). The answer names the
 * real limit in the caller's language, not Laravel's untranslated sentence.
 */

it('answers an oversized upload with the real limit, in the caller\'s language', function () {
    $post = UploadLimit::parse((string) ini_get('post_max_size'));
    if ($post === null) {
        $this->markTestSkipped('post_max_size is unlimited here.');
    }

    $response = $this->call('POST', '/api/v1/employees/import/preview', [], [], [], [
        'CONTENT_LENGTH' => (string) ($post + 1),
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_ACCEPT_LANGUAGE' => 'am',
    ]);

    $response->assertStatus(413)
        ->assertJsonPath('title', 'Payload Too Large')
        ->assertJsonPath('detail', __('validation.post_too_large', ['max' => UploadLimit::label(UploadLimit::bytes())], 'am'));
    expect($response->json('detail'))->toContain(UploadLimit::label(UploadLimit::bytes()));
});
