<?php

declare(strict_types=1);

describe('localization', function () {
    it('defaults to english', function () {
        $this->getJson('/api/v1/ping')
            ->assertOk();

        expect(app()->getLocale())->toBe('en');
    });

    it('switches to amharic via Accept-Language header', function () {
        $this->withHeader('Accept-Language', 'am')
            ->getJson('/api/v1/ping')
            ->assertOk();

        expect(app()->getLocale())->toBe('am');
    });

    it('falls back to english for unsupported locales', function () {
        $this->withHeader('Accept-Language', 'fr')
            ->getJson('/api/v1/ping')
            ->assertOk();

        expect(app()->getLocale())->toBe('en');
    });

    it('returns localized auth messages in amharic', function () {
        $this->withHeader('Accept-Language', 'am')
            ->postJson('/api/v1/auth/login', [
                'email' => 'nonexistent@test.com',
                'password' => 'wrong',
            ])
            ->assertUnprocessable();
    });
});
