<?php

declare(strict_types=1);

use App\Services\Sms\SmsNumber;

describe('SmsNumber::normalize', function () {
    it('normalizes every Ethiopian input shape to 2519… / 2517…', function (string $input, string $expected) {
        expect(SmsNumber::normalize($input))->toBe($expected);
    })->with([
        'local zero prefix' => ['0911223344', '251911223344'],
        'e164 with plus' => ['+251911223344', '251911223344'],
        'e164 without plus' => ['251911223344', '251911223344'],
        'nine digit bare' => ['911223344', '251911223344'],
        'spaced local' => ['0911 223 344', '251911223344'],
        'dashed local' => ['091-122-3344', '251911223344'],
        'safaricom 07 range' => ['0711223344', '251711223344'],
    ]);
});

describe('SmsNumber::isValid', function () {
    it('accepts valid Ethiopian mobile numbers', function (string $input) {
        expect(SmsNumber::isValid($input))->toBeTrue();
    })->with([
        '0911223344',
        '+251911223344',
        '0711223344',
    ]);

    it('rejects non-mobile or malformed numbers', function (string $input) {
        expect(SmsNumber::isValid($input))->toBeFalse();
    })->with([
        'too short' => ['091122'],
        'landline leading 1' => ['0111223344'],
        'wrong first digit' => ['0811223344'],
        'too long' => ['09112233445'],
        'empty' => [''],
    ]);
});
