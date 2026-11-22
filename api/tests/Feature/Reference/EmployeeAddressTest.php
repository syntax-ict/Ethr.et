<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\ReferenceItem;

function addressedEmployee(object $tenant, array $over = []): Employee
{
    return Employee::factory()->create(array_merge([
        'tenant_id' => $tenant->id,
        'address_region_code' => 'oromia',
        'address_region_text' => 'Oromia',
        'address_zone_or_sub_city_text' => 'East Shewa',
        'address_town_code' => 'adama',
        'address_town_text' => 'Adama',
        'address_woreda' => '04',
        'address_kebele' => '09',
        'address_house_number' => '123',
    ], $over));
}

describe('creating an employee with an address', function () {
    beforeEach(function () {
        $this->tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $this->tenant);
    });

    it('keeps an Addis Ababa sub-city with the text beside the codes', function () {
        $this->postJson('/api/v1/employees', [
            'name' => 'Abebe Bekele',
            'hire_date' => now()->toDateString(),
            'address_region_code' => 'addis_ababa',
            'address_region_text' => 'Addis Ababa',
            'address_sub_city_code' => 'bole',
            'address_zone_or_sub_city_text' => 'Bole',
            'address_woreda' => '03',
            'address_kebele' => '12',
            'address_house_number' => 'New 456',
        ])->assertCreated()
            ->assertJsonPath('address_region_code', 'addis_ababa')
            ->assertJsonPath('address_sub_city_code', 'bole')
            ->assertJsonPath('address_zone_or_sub_city_text', 'Bole')
            ->assertJsonPath('address_woreda', '03')
            ->assertJsonPath('address_house_number', 'New 456');
    });

    it('keeps a town outside Addis Ababa, with a zone typed', function () {
        $this->postJson('/api/v1/employees', [
            'name' => 'Chala Gemeda',
            'hire_date' => now()->toDateString(),
            'address_region_code' => 'oromia',
            'address_region_text' => 'Oromia',
            'address_zone_or_sub_city_text' => 'East Shewa',
            'address_town_code' => 'adama',
            'address_town_text' => 'Adama',
        ])->assertCreated()
            ->assertJsonPath('address_town_code', 'adama')
            ->assertJsonPath('address_zone_or_sub_city_text', 'East Shewa');
    });

    it('saves with no address at all', function () {
        $this->postJson('/api/v1/employees', ['name' => 'No Address', 'hire_date' => now()->toDateString()])
            ->assertCreated()
            ->assertJsonPath('address_region_code', null)
            ->assertJsonPath('address_town_text', null);
    });

    it('keeps typed text with no code, as a record from before the lists would', function () {
        $this->postJson('/api/v1/employees', [
            'name' => 'Typed Town',
            'hire_date' => now()->toDateString(),
            'address_town_text' => 'A village not listed',
        ])->assertCreated()
            ->assertJsonPath('address_town_text', 'A village not listed')
            ->assertJsonPath('address_town_code', null);
    });

    it('refuses a region code that is not on the list, and a bank code in its place', function () {
        foreach (['atlantis', 'awash'] as $code) {
            $this->postJson('/api/v1/employees', [
                'name' => 'X',
                'hire_date' => now()->toDateString(),
                'address_region_code' => $code,
                'address_region_text' => 'X',
            ])->assertUnprocessable()->assertJsonValidationErrors('address_region_code');
        }
    });

    it('refuses a code sent without its text', function () {
        $this->postJson('/api/v1/employees', [
            'name' => 'X',
            'hire_date' => now()->toDateString(),
            'address_region_code' => 'oromia',
        ])->assertUnprocessable()->assertJsonValidationErrors('address_region_text');
    });

    it('refuses a sub-city under another region and a town under another region', function () {
        $this->postJson('/api/v1/employees', [
            'name' => 'X',
            'hire_date' => now()->toDateString(),
            'address_region_code' => 'oromia',
            'address_region_text' => 'Oromia',
            'address_sub_city_code' => 'bole',
            'address_zone_or_sub_city_text' => 'Bole',
        ])->assertUnprocessable()->assertJsonValidationErrors('address_sub_city_code');

        $this->postJson('/api/v1/employees', [
            'name' => 'X',
            'hire_date' => now()->toDateString(),
            'address_region_code' => 'oromia',
            'address_region_text' => 'Oromia',
            'address_town_code' => 'bahir_dar',
            'address_town_text' => 'Bahir Dar',
        ])->assertUnprocessable()->assertJsonValidationErrors('address_town_code');
    });

    it('asks for the region when a town arrives with none', function () {
        $this->postJson('/api/v1/employees', [
            'name' => 'X',
            'hire_date' => now()->toDateString(),
            'address_town_code' => 'adama',
            'address_town_text' => 'Adama',
        ])->assertUnprocessable()->assertJsonValidationErrors('address_region_code');
    });

    it('still saves a code the list has since retired', function () {
        ReferenceItem::query()->where('kind', 'town')->where('code', 'adama')->update(['is_active' => false]);

        $this->postJson('/api/v1/employees', [
            'name' => 'Retired Town',
            'hire_date' => now()->toDateString(),
            'address_region_code' => 'oromia',
            'address_region_text' => 'Oromia',
            'address_town_code' => 'adama',
            'address_town_text' => 'Adama',
        ])->assertCreated();
    });
});

describe('editing the address of an employee', function () {
    beforeEach(function () {
        $this->tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $this->tenant);
        $this->employee = addressedEmployee($this->tenant);
        $this->url = "/api/v1/employees/{$this->employee->public_id}";
    });

    it('keeps every code when other fields change', function () {
        $this->putJson($this->url, ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('address_region_code', 'oromia')
            ->assertJsonPath('address_town_code', 'adama');
    });

    it('keeps the code when the same text is sent again with no code', function () {
        $this->putJson($this->url, ['address_town_text' => 'Adama'])
            ->assertOk()
            ->assertJsonPath('address_town_code', 'adama');
    });

    it('drops the town code when the town is retyped without one', function () {
        $this->putJson($this->url, ['address_town_text' => 'Somewhere else'])
            ->assertOk()
            ->assertJsonPath('address_town_text', 'Somewhere else')
            ->assertJsonPath('address_town_code', null)
            ->assertJsonPath('address_region_code', 'oromia');
    });

    it('drops the region code when the region is retyped without one, and the town with it', function () {
        $this->putJson($this->url, ['address_region_text' => 'Somewhere else'])
            ->assertOk()
            ->assertJsonPath('address_region_code', null)
            ->assertJsonPath('address_town_code', null);
    });

    it('drops the town when the region moves and no town is named', function () {
        $this->putJson($this->url, ['address_region_code' => 'amhara', 'address_region_text' => 'Amhara'])
            ->assertOk()
            ->assertJsonPath('address_region_code', 'amhara')
            ->assertJsonPath('address_town_code', null);
    });

    it('moves the whole address at once', function () {
        $this->putJson($this->url, [
            'address_region_code' => 'addis_ababa',
            'address_region_text' => 'Addis Ababa',
            'address_sub_city_code' => 'arada',
            'address_zone_or_sub_city_text' => 'Arada',
            'address_town_code' => null,
            'address_town_text' => null,
        ])->assertOk()
            ->assertJsonPath('address_sub_city_code', 'arada')
            ->assertJsonPath('address_town_code', null);
    });

    it('can clear the address', function () {
        $this->putJson($this->url, [
            'address_region_code' => null,
            'address_region_text' => null,
            'address_town_code' => null,
            'address_town_text' => null,
        ])->assertOk()
            ->assertJsonPath('address_region_code', null)
            ->assertJsonPath('address_town_code', null);
    });

    it('judges a town sent alone against the region the employee already has', function () {
        $this->putJson($this->url, ['address_town_code' => 'bahir_dar', 'address_town_text' => 'Bahir Dar'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address_town_code');

        $this->putJson($this->url, ['address_sub_city_code' => 'bole', 'address_zone_or_sub_city_text' => 'Bole'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address_sub_city_code');
    });

    it('judges a town by its own list when a bank shares its code', function () {
        $this->putJson($this->url, ['address_town_code' => 'awash', 'address_town_text' => 'Awash'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address_town_code');
    });

    it('asks for a region when the employee has none and a town is sent alone', function () {
        $bare = Employee::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->putJson("/api/v1/employees/{$bare->public_id}", ['address_town_code' => 'adama', 'address_town_text' => 'Adama'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address_region_code');
    });

    it('answers an unknown code and a mismatched one in the same words', function () {
        $unknown = $this->putJson($this->url, ['address_town_code' => 'atlantis', 'address_town_text' => 'X'])
            ->assertUnprocessable()->json('errors.address_town_code.0');
        $mismatch = $this->putJson($this->url, ['address_town_code' => 'bahir_dar', 'address_town_text' => 'X'])
            ->assertUnprocessable()->json('errors.address_town_code.0');

        expect($unknown)->toBe($mismatch);
    });
});

describe('an employee editing their own address', function () {
    beforeEach(function () {
        $this->tenant = createTenant();
        $this->employee = addressedEmployee($this->tenant);
        test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $this->employee->id], $this->tenant));
        $this->url = "http://{$this->tenant->subdomain}.ethr.test/api/v1/profile";
    });

    it('sees the address on their profile', function () {
        test()->getJson($this->url)
            ->assertOk()
            ->assertJsonPath('employee.address_region_code', 'oromia')
            ->assertJsonPath('employee.address_town_text', 'Adama')
            ->assertJsonPath('employee.address_kebele', '09');
    });

    it('lists the address among the fields they may change themselves', function () {
        $self = test()->getJson($this->url)->assertOk()->json('editable_fields.self');

        expect($self)->toContain('address_region_code', 'address_town_code', 'address_house_number');
    });

    it('saves a new address at once, with no approval', function () {
        test()->putJson($this->url, [
            'address_region_code' => 'addis_ababa',
            'address_region_text' => 'Addis Ababa',
            'address_sub_city_code' => 'bole',
            'address_zone_or_sub_city_text' => 'Bole',
            'address_town_code' => null,
            'address_town_text' => null,
            'address_woreda' => '07',
        ])->assertOk();

        $fresh = $this->employee->fresh();
        expect($fresh->address_region_code)->toBe('addis_ababa')
            ->and($fresh->address_sub_city_code)->toBe('bole')
            ->and($fresh->address_town_code)->toBeNull()
            ->and($fresh->address_woreda)->toBe('07');
    });

    it('can clear a box, which no other profile field can', function () {
        test()->putJson($this->url, ['address_kebele' => null, 'address_house_number' => null])->assertOk();

        $fresh = $this->employee->fresh();
        expect($fresh->address_kebele)->toBeNull()->and($fresh->address_house_number)->toBeNull();
    });

    it('drops a code when its text is retyped without it', function () {
        test()->putJson($this->url, ['address_town_text' => 'Another town'])->assertOk();

        expect($this->employee->fresh()->address_town_code)->toBeNull();
    });

    it('leaves the address alone when only the phone is sent', function () {
        test()->putJson($this->url, ['phone' => '0911223344'])->assertOk();

        expect($this->employee->fresh()->address_town_code)->toBe('adama');
    });

    it('refuses a town under another region', function () {
        test()->putJson($this->url, ['address_town_code' => 'bahir_dar', 'address_town_text' => 'Bahir Dar'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address_town_code');
    });
});
