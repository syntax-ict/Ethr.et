<?php

declare(strict_types=1);

use App\Support\EthiopicLatin;
use App\Support\PersonName;

// An Ethiopic name written in Latin letters, and the loose spelling fold, are
// what the Unrecognised badges card's suggestions rest on. They are for
// reading by a person; nothing here assigns a badge.

it('writes Ethiopic names the way a person spells them in Latin', function (string $ethiopic, string $latin) {
    expect(EthiopicLatin::toLatin($ethiopic))->toBe($latin);
})->with([
    'Kebede' => ['ከበደ', 'kebede'],
    'Tesfaye' => ['ተስፋዬ', 'tesfaye'],
    'Seblewengel' => ['ሰብለወንጌል', 'seblewengel'],
    'Abebe, a vowel carrier' => ['አበበ', 'abebe'],
    'Haile' => ['ኃይሌ', 'hayle'],
    'Girma, sixth order' => ['ግርማ', 'grma'],
    'Birhanu' => ['ብርሃኑ', 'brhanu'],
]);

it('returns Latin text as it came', function () {
    expect(EthiopicLatin::toLatin('Abebe Kebede'))->toBe('Abebe Kebede')
        ->and(EthiopicLatin::isEthiopic('Abebe'))->toBeFalse()
        ->and(EthiopicLatin::isEthiopic('አበበ'))->toBeTrue();
});

it('folds the spellings of one name to one word', function (string $a, string $b) {
    expect(PersonName::foldedWordsFit(PersonName::fold($a), PersonName::fold($b)))->toBeTrue();
})->with([
    ['Mekonnen', 'Mekonen'],
    ['Mekonnen', 'Makonnen'],
    ['Seblewongel', 'Seblewengel'],
    ['Seblewongel', 'ሰብለወንጌል'],
    ['Mekonnen', 'መኮንን'],
    ['Haile', 'ኃይሌ'],
    ['Girma', 'ግርማ'],
    ['Birhanu', 'ብርሃኑ'],
    ['Tesfaye', 'ተስፋዬ'],
    ['Josef', 'Yosef'],
    ['Philipos', 'Filipos'],
]);

it('does not fold two different names together', function (string $a, string $b) {
    expect(PersonName::foldedWordsFit(PersonName::fold($a), PersonName::fold($b)))->toBeFalse();
})->with([
    ['Mekonnen', 'Tesfaye'],
    ['Abebe', 'Hanna'],
    ['Girma', 'Tigist'],
]);
