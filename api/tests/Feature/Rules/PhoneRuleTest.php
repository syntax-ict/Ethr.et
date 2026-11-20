<?php

declare(strict_types=1);

use App\Rules\Phone;
use Illuminate\Support\Facades\Validator;

function phoneFails(Phone $rule, mixed $value): bool
{
    return Validator::make(['phone' => $value], ['phone' => [$rule]])->fails();
}

describe('Phone::any', function () {
    it('accepts an Ethiopian number however it is typed', function (string $input) {
        expect(phoneFails(Phone::any(), $input))->toBeFalse();
    })->with([
        'local' => '0911223344',
        'spaced' => '0911 22 33 44',
        'e164' => '+251911223344',
        'bare' => '911223344',
        'landline' => '011 123 4567',
    ]);

    it('accepts a number from another country with its country code', function () {
        expect(phoneFails(Phone::any(), '+44 20 7946 0958'))->toBeFalse();
    });

    it('refuses what is not a phone number', function (mixed $input) {
        expect(phoneFails(Phone::any(), $input))->toBeTrue();
    })->with([
        'too short' => '09112',
        'letters mixed in' => '0911 call me',
        'foreign without a country code' => '20 7946 0958',
        'malformed ethiopian number' => '+251 91 122',
        'an array' => [['0911223344']],
    ]);

    it('says what a good number looks like', function () {
        $errors = Validator::make(['phone' => 'abc'], ['phone' => [Phone::any()]])->errors();

        expect($errors->first('phone'))->toContain('0912345678');
    });
});

describe('Phone::ethiopianMobile', function () {
    it('accepts the 9 and 7 mobile ranges in any typed shape', function (string $input) {
        expect(phoneFails(Phone::ethiopianMobile(), $input))->toBeFalse();
    })->with([
        'nine range' => '0911223344',
        'seven range' => '+251 711 223 344',
    ]);

    it('refuses a landline, a foreign number and noise', function (string $input) {
        expect(phoneFails(Phone::ethiopianMobile(), $input))->toBeTrue();
    })->with([
        'landline' => '0111234567',
        'foreign' => '+44 20 7946 0958',
        'letters' => '0911 call me',
    ]);
});
