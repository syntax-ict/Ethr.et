<?php

declare(strict_types=1);

use App\Support\EthiopianPhone;

describe('EthiopianPhone::canonical', function () {
    it('reduces every Ethiopian input shape to one E.164 form', function (string $input) {
        expect(EthiopianPhone::canonical($input))->toBe('+251911223344');
    })->with([
        'local zero prefix' => '0911223344',
        'e164 with plus' => '+251911223344',
        'e164 without plus' => '251911223344',
        'nine digit bare' => '911223344',
        'spaced local' => '0911 223 344',
        'dashed local' => '091-122-3344',
        'parenthesised' => '(0911) 223344',
        'plus and spaces' => '+251 911 223 344',
    ]);

    it('canonicalizes landlines too, since a contact number need not be mobile', function () {
        expect(EthiopianPhone::canonical('0111234567'))->toBe('+251111234567');
    });

    it('returns null rather than guessing at unusable input', function (string $input) {
        expect(EthiopianPhone::canonical($input))->toBeNull();
    })->with([
        'empty' => '',
        'too short' => '091122',
        'too long' => '09112233445566',
        'letters only' => 'not a phone',
        // A subscriber number may not itself start with 0.
        'double zero' => '00911223344',
    ]);
});

describe('EthiopianPhone::canonicalOrRaw', function () {
    it('canonicalizes a recognizable number for storage', function (string $input) {
        expect(EthiopianPhone::canonicalOrRaw($input))->toBe('+251911223344');
    })->with([
        'local zero prefix' => '0911223344',
        'e164 with plus' => '+251911223344',
        'e164 without plus' => '251911223344',
    ]);

    it('keeps null as null so a missing phone is never invented', function () {
        expect(EthiopianPhone::canonicalOrRaw(null))->toBeNull();
    });

    it('preserves an unparseable value verbatim rather than discarding it', function (string $input) {
        expect(EthiopianPhone::canonicalOrRaw($input))->toBe($input);
    })->with([
        'foreign number' => '+1 202 555 0100',
        'free text' => 'not a phone',
    ]);
});

describe('EthiopianPhone::isMobile', function () {
    it('accepts the 9 and 7 mobile ranges', function (string $input) {
        expect(EthiopianPhone::isMobile($input))->toBeTrue();
    })->with(['0911223344', '+251911223344', '0711223344']);

    it('rejects landlines and unparseable input', function (string $input) {
        expect(EthiopianPhone::isMobile($input))->toBeFalse();
    })->with(['0111234567', 'not a phone', '']);
});

describe('EthiopianPhone::variants', function () {
    // Existing rows predate canonicalization and hold whichever shape their
    // source used, so a lookup has to search all of them. This is what lets the
    // OTP fix work on historical data without migrating every phone column.
    it('lists every shape the same number may already be stored as', function () {
        expect(EthiopianPhone::variants('0911223344'))->toBe([
            '+251911223344',
            '0911223344',
            '251911223344',
            '911223344',
        ]);
    });

    it('produces the identical set whichever shape is supplied', function () {
        expect(EthiopianPhone::variants('+251911223344'))
            ->toBe(EthiopianPhone::variants('0911223344'));
    });

    it('puts the canonical form first', function () {
        expect(EthiopianPhone::variants('911223344')[0])->toBe('+251911223344');
    });

    it('does not widen the search when the input cannot be parsed', function () {
        // Returning a broad set here would let an unparseable string match rows
        // it has nothing to do with.
        expect(EthiopianPhone::variants('not a phone'))->toBe(['not a phone']);
    });
});
