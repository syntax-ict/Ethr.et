<?php

declare(strict_types=1);

use App\Services\Device\DeviceIdRules;

it('takes digits only for a person number on the ZKTeco types, and says why when it does not', function (string $type) {
    expect(DeviceIdRules::accepts($type, '0012'))->toBeTrue()
        ->and(DeviceIdRules::accepts($type, 'A12'))->toBeFalse()
        ->and(DeviceIdRules::reason($type, 'A12'))->toBe('digits_only')
        ->and(DeviceIdRules::reason($type, '12-3'))->toBe('digits_only')
        ->and(DeviceIdRules::reason($type, '0012'))->toBeNull()
        ->and(DeviceIdRules::restricts($type))->toBeTrue();
})->with(['zkteco', 'zkteco_adms']);

it('holds a type with no rule of its own to the general badge rule and claims nothing more', function (string $type) {
    expect(DeviceIdRules::restricts($type))->toBeFalse()
        ->and(DeviceIdRules::accepts($type, 'A12-b'))->toBeTrue()
        ->and(DeviceIdRules::accepts($type, '0012'))->toBeTrue()
        ->and(DeviceIdRules::reason($type, 'A_12'))->toBe('badge_rule')
        ->and(DeviceIdRules::accepts($type, str_repeat('1', 33)))->toBeFalse();
})->with(['hikvision', 'suprema', 'generic', 'mock', 'made_up']);

it('keeps a name to 32 characters unless the type says otherwise', function () {
    expect(DeviceIdRules::nameLength('hikvision'))->toBe(32)
        ->and(DeviceIdRules::nameLength('zkteco_adms'))->toBe(32)
        ->and(DeviceIdRules::nameLength('made_up'))->toBe(32);
});

it('names a restricted type once for a person, whichever ZKTeco kind it is', function () {
    expect(DeviceIdRules::label('zkteco'))->toBe('ZKTeco')
        ->and(DeviceIdRules::label('zkteco_adms'))->toBe('ZKTeco')
        ->and(DeviceIdRules::fitsBadge('A-12'))->toBeTrue()
        ->and(DeviceIdRules::fitsBadge('A_12'))->toBeFalse();
});
