<?php

declare(strict_types=1);

use App\Support\TenancyDomain;

/**
 * `APP_DOMAIN=` is an empty string, not null.
 *
 * `.env.example` ships the variable present-but-blank, because single-host
 * development wants hostname tenancy off. Every guard originally written as
 * `config('app.domain') === null` therefore read a blank setting as *configured*
 * and switched the whole hostname model on: admin API calls on a tenant host
 * became 404s, the super-admin exemption narrowed, and impersonation demanded a
 * cross-host handoff on localhost.
 *
 * Sixteen tests failed on that one empty string. These pin the normalisation so
 * a future guard cannot reintroduce it by comparing against null directly.
 */
it('treats a blank domain as tenancy being switched off', function (mixed $blank) {
    config(['app.domain' => $blank]);

    expect(TenancyDomain::root())->toBeNull()
        ->and(TenancyDomain::isHostnameAuthoritative())->toBeFalse();
})->with([
    'unset' => null,
    'empty string' => '',
    'whitespace' => '   ',
    'bare dot' => '.',
]);

it('normalises a configured domain', function (string $configured) {
    config(['app.domain' => $configured]);

    expect(TenancyDomain::root())->toBe('ethr.et')
        ->and(TenancyDomain::isHostnameAuthoritative())->toBeTrue();
})->with([
    'plain' => 'ethr.et',
    'leading dot' => '.ethr.et',
    'trailing dot' => 'ethr.et.',
    'padded' => '  ethr.et  ',
    'mixed case' => 'ETHR.et',
]);
