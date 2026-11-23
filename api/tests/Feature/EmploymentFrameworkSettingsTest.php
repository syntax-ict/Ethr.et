<?php

declare(strict_types=1);

use App\Enums\EmploymentFramework;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\Employment\EmploymentFrameworks;
use App\Services\Payroll\StatutoryRules;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * How an organisation sees and corrects the employment framework ETHR read for
 * its kind, and how one employee is marked outside it.
 */
function fwUrl(Tenant $tenant, string $path = 'settings'): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

function fwOwnRows(Tenant $tenant)
{
    return DB::table('statutory_parameters')->where('tenant_id', $tenant->id)->where('code', 'employment.framework')->orderBy('id')->get();
}

function fwAudit(Tenant $tenant, string $action): int
{
    return DB::table('audit_log')->where('tenant_id', $tenant->id)->where('action', $action)->count();
}

function fwAdmin(string $kind = 'private'): Tenant
{
    $tenant = createTenant(['organization_type' => $kind]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    return $tenant;
}

describe('GET /settings', function () {
    it('shows ETHR\'s reading for a private organisation', function () {
        $tenant = fwAdmin('private');

        $this->getJson(fwUrl($tenant))
            ->assertOk()
            ->assertJsonPath('organization.employment_framework.framework', 'labour_proclamation')
            ->assertJsonPath('organization.employment_framework.confirmed', false)
            ->assertJsonPath('organization.employment_framework.source', 'ethr')
            ->assertJsonPath('organization.employment_framework.has_statutory_rules', true)
            ->assertJsonPath('organization.employment_framework.ethr_reading', 'labour_proclamation')
            ->assertJsonStructure(['organization' => ['employment_framework' => ['legal_reference']]]);

        expect(fwOwnRows($tenant))->toHaveCount(0);
    });

    it('answers null for a kind with no row', function () {
        $tenant = fwAdmin('private');
        DB::table('statutory_parameters')->where('code', 'employment.framework')->whereNull('tenant_id')->where('organization_type', 'private')->delete();
        app(StatutoryRules::class)->forget();

        $this->getJson(fwUrl($tenant))
            ->assertOk()
            ->assertJsonPath('organization.employment_framework.framework', null)
            ->assertJsonPath('organization.employment_framework.confirmed', false)
            ->assertJsonPath('organization.employment_framework.source', null)
            ->assertJsonPath('organization.employment_framework.legal_reference', null)
            ->assertJsonPath('organization.employment_framework.has_statutory_rules', false)
            ->assertJsonPath('organization.employment_framework.ethr_reading', null);
    });

    it('lets an organisation record its own where ETHR has no reading for its kind', function () {
        $tenant = fwAdmin('private');
        DB::table('statutory_parameters')->where('code', 'employment.framework')->whereNull('tenant_id')->where('organization_type', 'private')->delete();
        app(StatutoryRules::class)->forget();

        $this->putJson(fwUrl($tenant, 'settings/organization'), ['employment_framework' => 'federal_civil_service'])
            ->assertOk()
            ->assertJsonPath('organization.employment_framework.framework', 'federal_civil_service')
            ->assertJsonPath('organization.employment_framework.source', 'organisation')
            ->assertJsonPath('organization.employment_framework.ethr_reading', null);
    });

    it('refuses a user without the settings permission', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $this->getJson(fwUrl($tenant))->assertForbidden();
        $this->putJson(fwUrl($tenant, 'settings/organization'), ['employment_framework' => 'unspecified'])->assertForbidden();
        expect(fwOwnRows($tenant))->toHaveCount(0);
    });
});

describe('PUT /settings/organization employment_framework', function () {
    it('records the organisation\'s own, audited once, and a second identical call writes nothing', function () {
        $tenant = fwAdmin('private');
        $url = fwUrl($tenant, 'settings/organization');

        $this->putJson($url, ['employment_framework' => 'unspecified'])
            ->assertOk()
            ->assertJsonPath('organization.employment_framework.framework', 'unspecified')
            ->assertJsonPath('organization.employment_framework.source', 'organisation')
            ->assertJsonPath('organization.employment_framework.confirmed', true)
            ->assertJsonPath('organization.employment_framework.legal_reference', null)
            ->assertJsonPath('organization.employment_framework.ethr_reading', 'labour_proclamation');

        $row = fwOwnRows($tenant)->sole();
        expect($row->domain)->toBe('employment')
            ->and($row->enforcement)->toBe('advisory')
            ->and((bool) $row->confirmed)->toBeTrue()
            ->and(fwAudit($tenant, 'statutory_parameter.overridden'))->toBe(1);

        $this->putJson($url, ['employment_framework' => 'unspecified'])->assertOk();

        expect(fwOwnRows($tenant))->toHaveCount(1)
            ->and(fwAudit($tenant, 'statutory_parameter.overridden'))->toBe(1);

        $this->getJson(fwUrl($tenant))->assertJsonPath('organization.employment_framework.framework', 'unspecified');
    });

    it('lets an organisation confirm ETHR\'s own reading as its own', function () {
        $tenant = fwAdmin('private');

        $this->putJson(fwUrl($tenant, 'settings/organization'), ['employment_framework' => 'labour_proclamation'])
            ->assertOk()
            ->assertJsonPath('organization.employment_framework.source', 'organisation')
            ->assertJsonPath('organization.employment_framework.confirmed', true);
    });

    it('edits the row in place on the day it started', function () {
        $tenant = fwAdmin('private');
        $url = fwUrl($tenant, 'settings/organization');

        $this->putJson($url, ['employment_framework' => 'unspecified'])->assertOk();
        $this->putJson($url, ['employment_framework' => 'federal_civil_service'])->assertOk();

        $row = fwOwnRows($tenant)->sole();
        expect(json_decode($row->value))->toBe('federal_civil_service')
            ->and($row->effective_to)->toBeNull()
            ->and(fwAudit($tenant, 'statutory_parameter.overridden'))->toBe(2);
    });

    it('resets to ETHR\'s reading with null, audited, and does nothing when there is no own row', function () {
        $tenant = fwAdmin('private');
        $url = fwUrl($tenant, 'settings/organization');

        $this->putJson($url, ['employment_framework' => null])->assertOk()
            ->assertJsonPath('organization.employment_framework.source', 'ethr');
        expect(fwAudit($tenant, 'statutory_parameter.reset'))->toBe(0);

        $this->putJson($url, ['employment_framework' => 'unspecified'])->assertOk();
        $this->putJson($url, ['employment_framework' => null])->assertOk()
            ->assertJsonPath('organization.employment_framework.framework', 'labour_proclamation')
            ->assertJsonPath('organization.employment_framework.source', 'ethr')
            ->assertJsonPath('organization.employment_framework.confirmed', false);

        expect(fwOwnRows($tenant))->toHaveCount(0)
            ->and(fwAudit($tenant, 'statutory_parameter.reset'))->toBe(1);
    });

    it('leaves the framework alone when the key is absent', function () {
        $tenant = fwAdmin('private');
        $this->putJson(fwUrl($tenant, 'settings/organization'), ['employment_framework' => 'unspecified'])->assertOk();

        $this->putJson(fwUrl($tenant, 'settings/organization'), ['name' => 'Renamed Ltd'])
            ->assertOk()
            ->assertJsonPath('organization.employment_framework.framework', 'unspecified');
    });

    it('refuses a value that is not a framework', function (mixed $value) {
        $tenant = fwAdmin('private');

        $this->putJson(fwUrl($tenant, 'settings/organization'), ['employment_framework' => $value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('employment_framework');

        expect(fwOwnRows($tenant))->toHaveCount(0);
    })->with(['unknown word' => ['labour_law'], 'a number' => [7], 'an array' => [['unspecified']]]);

    it('closes a row from an earlier date and starts a new one today, keeping the old answer for the earlier date', function () {
        $tenant = fwAdmin('private');
        $url = fwUrl($tenant, 'settings/organization');
        $rules = app(StatutoryRules::class);

        $this->travelTo(Carbon::parse('2026-03-01 09:00', 'Africa/Addis_Ababa'));
        $this->putJson($url, ['employment_framework' => 'unspecified'])->assertOk();

        $this->travelTo(Carbon::parse('2026-06-10 09:00', 'Africa/Addis_Ababa'));
        $this->putJson($url, ['employment_framework' => 'federal_civil_service'])->assertOk();

        $rows = fwOwnRows($tenant);
        expect($rows)->toHaveCount(2)
            ->and(substr((string) $rows[0]->effective_to, 0, 10))->toBe('2026-06-09')
            ->and(substr((string) $rows[1]->effective_from, 0, 10))->toBe('2026-06-10')
            ->and($rows[1]->effective_to)->toBeNull();

        $rules->forget();
        expect($rules->framework($tenant, Carbon::parse('2026-01-15')))->toBe(EmploymentFramework::LABOUR_PROCLAMATION)
            ->and($rules->framework($tenant, Carbon::parse('2026-05-01')))->toBe(EmploymentFramework::UNSPECIFIED)
            ->and($rules->framework($tenant, Carbon::parse('2026-06-09')))->toBe(EmploymentFramework::UNSPECIFIED)
            ->and($rules->framework($tenant, Carbon::parse('2026-06-10')))->toBe(EmploymentFramework::FEDERAL_CIVIL_SERVICE);

        // Reset closes the row the day before and leaves the history intact.
        $this->travelTo(Carbon::parse('2026-07-01 09:00', 'Africa/Addis_Ababa'));
        $this->putJson($url, ['employment_framework' => null])->assertOk();

        $rules->forget();
        expect(fwOwnRows($tenant))->toHaveCount(2)
            ->and($rules->framework($tenant, Carbon::parse('2026-06-20')))->toBe(EmploymentFramework::FEDERAL_CIVIL_SERVICE)
            ->and($rules->framework($tenant, Carbon::parse('2026-07-01')))->toBe(EmploymentFramework::LABOUR_PROCLAMATION);
    });

    it('is not touched by a change of organisation type', function () {
        $tenant = fwAdmin('private');
        $url = fwUrl($tenant, 'settings/organization');
        $this->putJson($url, ['employment_framework' => 'unspecified'])->assertOk();

        $this->putJson($url, ['organization_type' => 'ngo'])->assertOk();

        expect(fwOwnRows($tenant))->toHaveCount(1);
        $this->getJson(fwUrl($tenant))
            ->assertJsonPath('organization.organization_type', 'ngo')
            ->assertJsonPath('organization.employment_framework.framework', 'unspecified')
            ->assertJsonPath('organization.employment_framework.source', 'organisation');
    });

    it('does not let another organisation read or change it', function () {
        $a = createTenant(['organization_type' => 'private']);
        $b = createTenant(['organization_type' => 'private']);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $b);

        $this->putJson(fwUrl($b, 'settings/organization'), ['employment_framework' => 'unspecified'])->assertOk();

        expect(fwOwnRows($a))->toHaveCount(0)
            ->and(fwOwnRows($b))->toHaveCount(1);

        app(CurrentTenant::class)->set($a);
        $adminA = createUser(['role' => UserRole::TENANT_ADMIN], $a);
        $this->actingAs($adminA);
        app()->forgetScopedInstances();
        app(CurrentTenant::class)->set($a);

        $this->getJson(fwUrl($a))
            ->assertOk()
            ->assertJsonPath('organization.employment_framework.source', 'ethr')
            ->assertJsonPath('organization.employment_framework.framework', 'labour_proclamation');
    });

    it('never puts an employment rule in payroll\'s rule set', function () {
        $tenant = fwAdmin('private');
        $this->putJson(fwUrl($tenant, 'settings/organization'), ['employment_framework' => 'unspecified'])->assertOk();

        $rules = app(StatutoryRules::class);
        $rules->forget();
        $codes = array_keys($rules->for($tenant, Carbon::today())->all());

        expect(array_filter($codes, fn (string $code) => str_starts_with($code, 'employment.')))->toBe([]);

        $response = $this->getJson(fwUrl($tenant, 'payroll/statutory-rules'))->assertOk();
        expect($response->getContent())->not->toContain('employment.');
    });

    it('describes the same answer the service gives', function () {
        $tenant = fwAdmin('government');

        $described = app(EmploymentFrameworks::class)->describe($tenant, Carbon::today());

        expect($described['framework'])->toBe('federal_civil_service')
            ->and($described['source'])->toBe('ethr')
            ->and($described['has_statutory_rules'])->toBeTrue();
    });
});

describe('the employee outside the framework', function () {
    it('round-trips on create and update and nulls the note when the flag is off', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $created = $this->postJson('/api/v1/employees', [
            'name' => 'Selam Tesfaye',
            'hire_date' => '2026-01-05',
            'outside_employment_framework' => true,
            'employment_framework_note' => 'General manager',
        ])->assertCreated()
            ->assertJsonPath('outside_employment_framework', true)
            ->assertJsonPath('employment_framework_note', 'General manager')
            ->assertJsonMissingPath('id')
            ->assertJsonMissingPath('tenant_id');

        $id = $created->json('public_id');

        $this->putJson("/api/v1/employees/{$id}", ['employment_framework_note' => 'Executive'])
            ->assertOk()
            ->assertJsonPath('outside_employment_framework', true)
            ->assertJsonPath('employment_framework_note', 'Executive');

        $this->putJson("/api/v1/employees/{$id}", ['outside_employment_framework' => false, 'employment_framework_note' => 'stale'])
            ->assertOk()
            ->assertJsonPath('outside_employment_framework', false)
            ->assertJsonPath('employment_framework_note', null);

        $this->getJson("/api/v1/employees/{$id}")
            ->assertOk()
            ->assertJsonPath('outside_employment_framework', false)
            ->assertJsonPath('employment_framework_note', null);

        $this->assertDatabaseHas('audit_log', ['action' => 'employee.updated']);
    });

    it('stores no note for an employee created without the flag', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/employees', [
            'name' => 'Dawit Alemu',
            'hire_date' => '2026-01-05',
            'employment_framework_note' => 'ignored',
        ])->assertCreated()
            ->assertJsonPath('outside_employment_framework', false)
            ->assertJsonPath('employment_framework_note', null);
    });

    it('refuses a note over 255 characters and a non-boolean flag', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/employees', [
            'name' => 'Dawit Alemu',
            'hire_date' => '2026-01-05',
            'outside_employment_framework' => 'maybe',
            'employment_framework_note' => str_repeat('a', 256),
        ])->assertStatus(422)->assertJsonValidationErrors(['outside_employment_framework', 'employment_framework_note']);
    });

    it('answers null for a flagged employee and the organisation\'s framework otherwise', function () {
        $tenant = createTenant(['organization_type' => 'private']);
        $covered = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $outside = Employee::factory()->create(['tenant_id' => $tenant->id, 'outside_employment_framework' => true]);
        $frameworks = app(EmploymentFrameworks::class);

        expect($frameworks->forEmployee($covered, Carbon::today()))->toBe(EmploymentFramework::LABOUR_PROCLAMATION)
            ->and($frameworks->forEmployee($outside, Carbon::today()))->toBeNull();
    });

    it('cannot be set by the employee through their own profile', function () {
        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

        $this->putJson(fwUrl($tenant, 'profile'), [
            'phone' => '0911223344',
            'outside_employment_framework' => true,
            'employment_framework_note' => 'self declared',
        ])->assertOk();

        $fresh = $employee->fresh();
        expect($fresh->outside_employment_framework)->toBeFalse()
            ->and($fresh->employment_framework_note)->toBeNull();
    });

    it('cannot be set by an employee-role user through the employee update route', function () {
        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

        $this->putJson("/api/v1/employees/{$employee->public_id}", ['outside_employment_framework' => true])->assertForbidden();

        expect($employee->fresh()->outside_employment_framework)->toBeFalse();
    });
});
