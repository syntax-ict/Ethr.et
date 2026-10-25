<?php

declare(strict_types=1);

use App\Rules\PermittedMarketingClaim;
use App\Support\MarketingClaims;
use Illuminate\Support\Facades\Validator;

/**
 * The claims the public site may not make, now that an operator can type the
 * page into a form. The list is App\Support\MarketingClaims; the test runs
 * every pattern through the rule as Laravel would.
 */
function claimPasses(string $text): bool
{
    return Validator::make(['text' => $text], ['text' => [new PermittedMarketingClaim]])->passes();
}

test('every forbidden claim is refused', function (string $text) {
    expect(claimPasses($text))->toBeFalse("'{$text}' should be refused");
})->with([
    'Performance reviews for every employee',
    'Annual appraisal cycles',
    'Training and certification tracking',
    'Set goals and OKRs',
    'Works with Hikvision and ZKTeco devices',
    'Biometric devices supported',
    'Fingerprint check-in',
    'Import from Excel',
    'Multi-level approval chains',
    'Delegate approvals while on leave',
    'Maker-checker on every payroll',
    'Full compliance with Proclamation 1321/2024',
    'No data leaves the country',
    'Your Data, Your Servers',
    'Every action is logged',
    'No cross-tenant access is possible',
    'Trusted by 500+ organisations',
    '50,000+ employees managed',
    '1M+ payslips',
    '99.9% uptime',
    'Telebirr integration',
]);

test('the claims the audit allowed pass', function (string $text) {
    expect(claimPasses($text))->toBeTrue("'{$text}' should pass");
})->with([
    'Stored on servers in Ethiopia, as Article 22 of Proclamation 1321/2024 requires.',
    'Every query is scoped to one organization by a guard that fails closed.',
    'Income tax under Proclamation 1395/2025, pension and Pagume proration.',
    'Check in from a phone, a shared kiosk, a QR code or the web, even offline.',
    'Managers decide requests from one approvals inbox.',
    'ሠራተኞች ከስልክ፣ ከኪዮስክ ወይም ከድር ይገባሉ።',
]);

test('every entry in the list has a reason', function () {
    foreach (MarketingClaims::FORBIDDEN as $pattern => $reason) {
        expect($reason)->not->toBeEmpty($pattern)
            ->and(@preg_match($pattern, ''))->not->toBeFalse("{$pattern} is not a valid pattern");
    }
});

test('an empty value and a non-string are left to the other rules', function () {
    expect(claimPasses(''))->toBeTrue()
        ->and(Validator::make(['text' => 42], ['text' => [new PermittedMarketingClaim]])->passes())->toBeTrue();
});
