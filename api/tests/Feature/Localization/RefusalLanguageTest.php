<?php

declare(strict_types=1);

use App\Enums\UserRole;

/**
 * Since 2026-10-20 every failed save in the app shows the server's own reason
 * (FRONTEND_REDESIGN_PLAN.md, Phase 2). A refusal therefore has to arrive in
 * the reader's language: Laravel's generic 403 was English on an Amharic
 * screen. lang/am.json carries the generic sentences.
 */
function refusedUrl(string $subdomain): string
{
    return "http://{$subdomain}.ethr.test/api/v1/payroll/cost-sharing";
}

it('words a refusal in Amharic for an Amharic reader', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    $this->getJson(refusedUrl($tenant->subdomain), ['Accept-Language' => 'am'])
        ->assertForbidden()
        ->assertJsonPath('detail', 'ይህን ተግባር ለመፈጸም ፈቃድ የለዎትም።');
});

it('keeps the English sentence for an English reader', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    $detail = $this->getJson(refusedUrl($tenant->subdomain), ['Accept-Language' => 'en'])
        ->assertForbidden()
        ->json('detail');

    expect($detail)->toBeString()->not->toBe('ይህን ተግባር ለመፈጸም ፈቃድ የለዎትም።');
});

it('translates every generic sentence the renderers can fall back to', function () {
    // The renderers in bootstrap/app.php fall back to these; one without an
    // Amharic line would reach an Amharic screen in English.
    $am = json_decode((string) file_get_contents(lang_path('am.json')), true);

    foreach ([
        'An error occurred.',
        'The requested resource was not found.',
        'This action is unauthorized.',
        'You are not authorized to perform this action.',
    ] as $sentence) {
        expect($am)->toHaveKey($sentence);
    }
});
