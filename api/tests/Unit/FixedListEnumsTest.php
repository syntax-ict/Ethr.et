<?php

declare(strict_types=1);

use App\Enums\EducationLevel;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\Relationship;

it('lists each closed list once, with the values the forms and the database use', function () {
    expect(Gender::values())->toBe(['male', 'female'])
        ->and(MaritalStatus::values())->toBe(['single', 'married', 'divorced', 'widowed'])
        ->and(Relationship::values())->toBe(['spouse', 'parent', 'sibling', 'child', 'friend', 'other'])
        ->and(EducationLevel::values())->toBe([
            'primary', 'secondary', 'preparatory', 'certificate', 'diploma', 'bachelor', 'master', 'doctorate', 'other',
        ]);
});

it('reads a spreadsheet gender, and never guesses', function (?string $typed, ?Gender $expected) {
    expect(Gender::fromInput($typed))->toBe($expected);
})->with([
    'word' => ['Male', Gender::MALE],
    'initial' => ['F', Gender::FEMALE],
    'padded' => [' female ', Gender::FEMALE],
    'other word' => ['unknown', null],
    'blank' => ['', null],
    'null' => [null, null],
]);

it('reads the other lists the same way', function () {
    expect(MaritalStatus::fromInput(' Married '))->toBe(MaritalStatus::MARRIED)
        ->and(MaritalStatus::fromInput('engaged'))->toBeNull()
        ->and(Relationship::fromInput('Spouse'))->toBe(Relationship::SPOUSE)
        ->and(Relationship::fromInput('Uncle'))->toBeNull()
        ->and(EducationLevel::fromInput('High School'))->toBeNull()
        ->and(EducationLevel::fromInput('Bachelor'))->toBe(EducationLevel::BACHELOR);
});
