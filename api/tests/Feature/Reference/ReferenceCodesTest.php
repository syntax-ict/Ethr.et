<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\ReferenceItem;

describe('a branch', function () {
    beforeEach(function () {
        $this->tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $this->tenant);
    });

    it('keeps the region and town it was given, with the text beside them', function () {
        $this->postJson('/api/v1/organization/branches', [
            'name' => 'Bahir Dar office',
            'city' => 'Bahir Dar',
            'region_code' => 'amhara',
            'town_code' => 'bahir_dar',
        ])->assertCreated()
            ->assertJsonPath('city', 'Bahir Dar')
            ->assertJsonPath('region_code', 'amhara')
            ->assertJsonPath('town_code', 'bahir_dar');
    });

    it('takes an Addis Ababa sub-city as its place', function () {
        $this->postJson('/api/v1/organization/branches', [
            'name' => 'Arada office',
            'city' => 'Arada',
            'region_code' => 'addis_ababa',
            'town_code' => 'arada',
        ])->assertCreated()->assertJsonPath('town_code', 'arada');
    });

    it('saves with no codes at all, as it did before the lists', function () {
        $this->postJson('/api/v1/organization/branches', ['name' => 'Old style', 'city' => 'Somewhere unlisted'])
            ->assertCreated()
            ->assertJsonPath('region_code', null)
            ->assertJsonPath('town_code', null);
    });

    it('refuses a region code that is not on the list', function () {
        $this->postJson('/api/v1/organization/branches', ['name' => 'X', 'region_code' => 'atlantis'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('region_code');
    });

    it('refuses a bank code in a region field', function () {
        $this->postJson('/api/v1/organization/branches', ['name' => 'X', 'region_code' => 'awash'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('region_code');
    });

    it('refuses a town that belongs to another region', function () {
        $this->postJson('/api/v1/organization/branches', [
            'name' => 'X',
            'region_code' => 'oromia',
            'town_code' => 'bahir_dar',
        ])->assertUnprocessable()->assertJsonValidationErrors('town_code');
    });

    it('still saves a code the list has since retired', function () {
        ReferenceItem::query()->where('kind', 'town')->where('code', 'adama')->update(['is_active' => false]);

        $this->postJson('/api/v1/organization/branches', [
            'name' => 'Adama office',
            'city' => 'Adama',
            'region_code' => 'oromia',
            'town_code' => 'adama',
        ])->assertCreated();
    });

    it('drops the codes when the city is retyped without them', function () {
        $branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'city' => 'Bahir Dar',
            'region_code' => 'amhara',
            'town_code' => 'bahir_dar',
        ]);

        $this->putJson("/api/v1/organization/branches/{$branch->public_id}", ['city' => 'Somewhere else'])
            ->assertOk()
            ->assertJsonPath('city', 'Somewhere else')
            ->assertJsonPath('region_code', null)
            ->assertJsonPath('town_code', null);
    });

    it('keeps its codes when other fields change', function () {
        $branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'city' => 'Bahir Dar',
            'region_code' => 'amhara',
            'town_code' => 'bahir_dar',
        ]);

        $this->putJson("/api/v1/organization/branches/{$branch->public_id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('region_code', 'amhara')
            ->assertJsonPath('town_code', 'bahir_dar');
    });
});

describe('a code without its text, or against other text', function () {
    it('is refused for a branch town sent without a city', function () {
        actingAsUser(['role' => UserRole::TENANT_ADMIN], createTenant());

        $this->postJson('/api/v1/organization/branches', [
            'name' => 'X',
            'region_code' => 'oromia',
            'town_code' => 'adama',
        ])->assertUnprocessable()->assertJsonValidationErrors('city');
    });

    it('drops the town when a branch moves to another region without naming one', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
            'city' => 'Adama',
            'region_code' => 'oromia',
            'town_code' => 'adama',
        ]);

        $this->putJson("/api/v1/organization/branches/{$branch->public_id}", ['region_code' => 'amhara'])
            ->assertOk()
            ->assertJsonPath('region_code', 'amhara')
            ->assertJsonPath('town_code', null);
    });

    it('refuses a new town that does not fit the region the branch already has', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
            'city' => 'Adama',
            'region_code' => 'oromia',
            'town_code' => 'adama',
        ]);

        $this->putJson("/api/v1/organization/branches/{$branch->public_id}", [
            'city' => 'Bahir Dar',
            'town_code' => 'bahir_dar',
        ])->assertUnprocessable()->assertJsonValidationErrors('town_code');
    });

    it('is refused for a bank code sent without a name, on the organisation and on the platform', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->putJson('/api/v1/settings', ['settings' => ['employer_bank_code' => 'awash']])
            ->assertUnprocessable();

        $home = createTenant();
        test()->actingAs(createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $home));
        test()->putJson("http://{$home->subdomain}.ethr.test/api/v1/admin/platform-settings", ['bank_code' => 'awash'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('bank_name');
    });
});

describe('an employee bank detail', function () {
    beforeEach(function () {
        $this->tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $this->tenant);
        $this->employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->url = "/api/v1/employees/{$this->employee->public_id}/bank-details";
    });

    it('keeps the bank code beside the bank name', function () {
        $this->postJson($this->url, [
            'bank_name' => 'Awash Bank',
            'bank_code' => 'awash',
            'account_number' => '1000123456789',
        ])->assertCreated()->assertJsonPath('bank_code', 'awash');
    });

    it('saves a typed bank with no code', function () {
        $this->postJson($this->url, [
            'bank_name' => 'A Bank Not Listed',
            'account_number' => '1000123456789',
        ])->assertCreated()->assertJsonPath('bank_code', null);
    });

    it('refuses a code that is not a bank', function () {
        $this->postJson($this->url, [
            'bank_name' => 'X',
            'bank_code' => 'bahir_dar',
            'account_number' => '1000123456789',
        ])->assertUnprocessable()->assertJsonValidationErrors('bank_code');
    });

    it('drops the code when the bank is changed to typed text', function () {
        $id = $this->postJson($this->url, [
            'bank_name' => 'Awash Bank',
            'bank_code' => 'awash',
            'account_number' => '1000123456789',
        ])->json('public_id');

        $this->putJson("{$this->url}/{$id}", [
            'bank_name' => 'A Bank Not Listed',
            'account_number' => '1000123456789',
        ])->assertOk()->assertJsonPath('bank_code', null);
    });
});

describe('the organisation bank it pays from', function () {
    it('keeps the code beside the name, and drops it when the name is typed', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->putJson('/api/v1/settings', ['settings' => [
            'employer_bank_name' => 'Awash Bank',
            'employer_bank_code' => 'awash',
        ]])->assertOk();
        $this->getJson('/api/v1/settings')->assertJsonPath('payroll.employer_bank_code', 'awash');

        $this->putJson('/api/v1/settings', ['settings' => ['employer_bank_name' => 'Typed Bank']])->assertOk();
        $this->getJson('/api/v1/settings')
            ->assertJsonPath('payroll.employer_bank_name', 'Typed Bank')
            ->assertJsonPath('payroll.employer_bank_code', null);
    });

    it('refuses a code that is not a bank', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->putJson('/api/v1/settings', ['settings' => ['employer_bank_code' => 'bahir_dar']])
            ->assertUnprocessable();
    });
});

describe('the platform bank', function () {
    it('keeps the code beside the name, and drops it when the name is typed', function () {
        $home = createTenant();
        test()->actingAs(createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $home));
        $url = "http://{$home->subdomain}.ethr.test/api/v1/admin/platform-settings";

        test()->putJson($url, ['bank_name' => 'Awash Bank', 'bank_code' => 'awash'])
            ->assertOk()->assertJsonPath('bank_code', 'awash');

        test()->putJson($url, ['bank_name' => 'Typed Bank'])
            ->assertOk()->assertJsonPath('bank_code', null);

        test()->putJson($url, ['bank_code' => 'bahir_dar'])->assertUnprocessable();
    });
});
