<?php

declare(strict_types=1);

use App\Enums\EmploymentFramework;
use App\Enums\UserRole;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\LeaveRequestResource;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Services\Employment\EmploymentFrameworks;
use App\Services\Payroll\StatutoryRules;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

function fwFresh(): void
{
    app()->forgetScopedInstances();
    app('auth')->forgetGuards();
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
        config(['app.domain' => 'ethr.et']);
        $a = createTenant(['organization_type' => 'private', 'subdomain' => 'orga']);
        $adminA = createUser(['role' => UserRole::TENANT_ADMIN], $a);
        $tokenA = $adminA->createToken('auth', ['*'])->plainTextToken;
        $b = createTenant(['organization_type' => 'private', 'subdomain' => 'orgb']);
        $adminB = createUser(['role' => UserRole::TENANT_ADMIN], $b);
        $tokenB = $adminB->createToken('auth', ['*'])->plainTextToken;
        $hostA = 'http://orga.ethr.et/api/v1/';
        $hostB = 'http://orgb.ethr.et/api/v1/';

        $this->withToken($tokenA)->putJson($hostA.'settings/organization', ['employment_framework' => 'federal_civil_service'])->assertOk();
        $aRows = fwOwnRows($a)->map(fn ($r) => (array) $r)->all();
        expect($aRows)->toHaveCount(1);

        fwFresh();
        $this->withToken($tokenB)->putJson($hostB.'settings/organization', ['employment_framework' => 'unspecified'])->assertOk();
        fwFresh();
        $this->withToken($tokenB)->putJson($hostB.'settings/organization', ['employment_framework' => null])->assertOk();
        fwFresh();
        $this->withToken($tokenB)->putJson($hostB.'settings/organization', ['employment_framework' => 'regional_civil_service'])->assertOk();

        expect(fwOwnRows($a)->map(fn ($r) => (array) $r)->all())->toEqual($aRows)
            ->and(fwOwnRows($b))->toHaveCount(1);

        fwFresh();
        $this->withToken($tokenA)->getJson($hostA.'settings')
            ->assertOk()
            ->assertJsonPath('organization.employment_framework.framework', 'federal_civil_service')
            ->assertJsonPath('organization.employment_framework.source', 'organisation');

        // B's signed-in user, sent to A's host, is refused and changes nothing.
        fwFresh();
        $this->withToken($tokenB)->getJson($hostA.'settings')
            ->assertForbidden()
            ->assertJsonPath('type', 'https://ethr.et/errors/tenant-mismatch');
        fwFresh();
        $this->withToken($tokenB)->putJson($hostA.'settings/organization', ['employment_framework' => null])
            ->assertForbidden()
            ->assertJsonPath('type', 'https://ethr.et/errors/tenant-mismatch');

        expect(fwOwnRows($a)->map(fn ($r) => (array) $r)->all())->toEqual($aRows);
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
        $this->putJson(fwUrl($tenant, 'settings/organization'), ['employment_framework' => 'unspecified'])->assertOk();

        $service = app(EmploymentFrameworks::class)->describe($tenant, Carbon::today());

        expect($this->getJson(fwUrl($tenant))->json('organization.employment_framework'))->toBe($service)
            ->and($service['framework'])->toBe('unspecified')
            ->and($service['ethr_reading'])->toBe('federal_civil_service');
    });

    it('keeps the citation when the organisation confirms ETHR\'s own reading', function () {
        $tenant = fwAdmin('private');
        $before = $this->getJson(fwUrl($tenant))->json('organization.employment_framework');

        $after = $this->putJson(fwUrl($tenant, 'settings/organization'), ['employment_framework' => 'labour_proclamation'])
            ->assertOk()->json('organization.employment_framework');

        expect($before['legal_reference'])->not->toBeNull()
            ->and($after['source'])->toBe('organisation')
            ->and($after['legal_reference'])->toBe($before['legal_reference']);

        $this->putJson(fwUrl($tenant, 'settings/organization'), ['employment_framework' => 'unspecified'])
            ->assertJsonPath('organization.employment_framework.legal_reference', null);
    });

    it('starts the row on the organisation\'s own date, and edits it in place the same local day', function () {
        $tenant = fwAdmin('private');
        $url = fwUrl($tenant, 'settings/organization');

        // 01:30 in Addis Ababa on 5 June is 22:30 UTC on 4 June.
        $this->travelTo(Carbon::parse('2026-06-04 22:30:00', 'UTC'));
        $this->putJson($url, ['employment_framework' => 'unspecified'])->assertOk();

        expect(substr((string) fwOwnRows($tenant)->sole()->effective_from, 0, 10))->toBe('2026-06-05');

        $this->travelTo(Carbon::parse('2026-06-05 09:00:00', 'UTC'));
        $this->putJson($url, ['employment_framework' => 'federal_civil_service'])->assertOk();

        $row = fwOwnRows($tenant)->sole();
        expect(json_decode($row->value))->toBe('federal_civil_service')
            ->and(substr((string) $row->effective_from, 0, 10))->toBe('2026-06-05');
    });

    it('sets again after a reset on the same day', function () {
        $tenant = fwAdmin('private');
        $url = fwUrl($tenant, 'settings/organization');

        $this->putJson($url, ['employment_framework' => 'unspecified'])->assertOk();
        $this->putJson($url, ['employment_framework' => null])->assertOk();
        expect(fwOwnRows($tenant))->toHaveCount(0);

        $this->putJson($url, ['employment_framework' => 'federal_civil_service'])->assertOk()
            ->assertJsonPath('organization.employment_framework.framework', 'federal_civil_service');

        expect(fwOwnRows($tenant))->toHaveCount(1);
    });

    it('leaves none in force when a reset finds two own rows already open', function () {
        $tenant = fwAdmin('private');
        foreach (['unspecified', 'federal_civil_service'] as $i => $value) {
            DB::table('statutory_parameters')->insert([
                'public_id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'organization_type' => null,
                'code' => 'employment.framework', 'domain' => 'employment', 'value' => json_encode($value),
                'tenant_overridable' => true, 'enforcement' => 'advisory', 'confirmed' => true,
                'effective_from' => '2026-01-0'.($i + 1), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        app(StatutoryRules::class)->forget();

        $this->putJson(fwUrl($tenant, 'settings/organization'), ['employment_framework' => null])->assertOk()
            ->assertJsonPath('organization.employment_framework.source', 'ethr');

        $open = fwOwnRows($tenant)->filter(fn ($r) => $r->effective_to === null || substr((string) $r->effective_to, 0, 10) >= Carbon::today()->toDateString());
        expect($open)->toHaveCount(0);
    });

    it('collapses two open own rows into one when a value is set', function () {
        $tenant = fwAdmin('private');
        foreach (['unspecified', 'federal_civil_service'] as $i => $value) {
            DB::table('statutory_parameters')->insert([
                'public_id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'organization_type' => null,
                'code' => 'employment.framework', 'domain' => 'employment', 'value' => json_encode($value),
                'tenant_overridable' => true, 'enforcement' => 'advisory', 'confirmed' => true,
                'effective_from' => '2026-01-0'.($i + 1), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        app(StatutoryRules::class)->forget();

        $this->putJson(fwUrl($tenant, 'settings/organization'), ['employment_framework' => 'regional_civil_service'])->assertOk();

        $open = fwOwnRows($tenant)->filter(fn ($r) => $r->effective_to === null);
        expect($open)->toHaveCount(1)
            ->and(json_decode($open->first()->value))->toBe('regional_civil_service');
    });

    it('refuses to record its own where ETHR holds the rule locked', function () {
        $tenant = fwAdmin('private');
        DB::table('statutory_parameters')->where('code', 'employment.framework')->whereNull('tenant_id')->where('organization_type', 'private')
            ->update(['enforcement' => 'locked']);
        app(StatutoryRules::class)->forget();

        $this->putJson(fwUrl($tenant, 'settings/organization'), ['employment_framework' => 'unspecified'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('employment_framework');

        expect(fwOwnRows($tenant))->toHaveCount(0)
            ->and(fwAudit($tenant, 'statutory_parameter.overridden'))->toBe(0);
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

        $payloads = DB::table('audit_log')->where('action', 'employee.updated')->orderBy('id')->pluck('payload')->map(fn ($p) => json_decode((string) $p, true))->all();
        expect($payloads)->not->toBeEmpty();
        $changes = array_values(array_filter($payloads, fn ($p) => ($p['after']['outside_employment_framework'] ?? null) === false));
        expect($changes)->toHaveCount(1)
            ->and($changes[0]['before']['outside_employment_framework'])->toBeTrue()
            ->and($changes[0]['before']['employment_framework_note'])->toBe('Executive')
            ->and($changes[0]['after']['employment_framework_note'])->toBeNull();
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

describe('who sees an employee outside the framework', function () {
    it('shows it to someone who may update the employee, and to nobody who may only view', function () {
        $tenant = createTenant();
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id, 'outside_employment_framework' => true, 'employment_framework_note' => 'General manager',
        ]);

        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $this->getJson("/api/v1/employees/{$employee->public_id}")->assertOk()
            ->assertJsonPath('outside_employment_framework', true)
            ->assertJsonPath('employment_framework_note', 'General manager');

        $lead = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $employee->update(['supervisor_id' => $lead->id]);
        actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $lead->id], $tenant);
        $this->getJson("/api/v1/employees/{$employee->public_id}")->assertOk()
            ->assertJsonMissingPath('outside_employment_framework')
            ->assertJsonMissingPath('employment_framework_note')
            ->assertJsonPath('public_id', $employee->public_id);
    });

    it('hides it inside an embedded employee, and shows it to HR', function () {
        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'outside_employment_framework' => true, 'employment_framework_note' => 'Special law']);
        $type = LeaveType::factory()->create(['tenant_id' => $tenant->id]);
        $leave = LeaveRequest::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id]);
        $leave->load('employee');

        $render = function ($user) use ($leave): array {
            // Nested resources read the application's own request, as they do over HTTP.
            $request = request();
            $request->setUserResolver(fn () => $user);

            return json_decode((new LeaveRequestResource($leave))->toResponse($request)->getContent(), true)['employee'];
        };

        $supervisor = createUser(['role' => UserRole::SUPERVISOR], $tenant);
        $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);

        expect($render($supervisor))->not->toHaveKey('outside_employment_framework')->not->toHaveKey('employment_framework_note')
            ->and($render($hr))->toMatchArray(['outside_employment_framework' => true, 'employment_framework_note' => 'Special law']);
    });

    it('does not report false for an employee loaded without the column', function () {
        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'outside_employment_framework' => true]);
        $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);

        $partial = Employee::query()->select('id', 'tenant_id', 'public_id', 'name')->findOrFail($employee->id);
        $request = Request::create('/');
        $request->setUserResolver(fn () => $hr);

        expect((new EmployeeResource($partial))->resolve($request))
            ->not->toHaveKey('outside_employment_framework')
            ->not->toHaveKey('employment_framework_note');
    });

    it('reads the flag from the database when forEmployee gets a partly loaded employee', function () {
        $tenant = createTenant(['organization_type' => 'private']);
        $outside = Employee::factory()->create(['tenant_id' => $tenant->id, 'outside_employment_framework' => true]);
        $covered = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $frameworks = app(EmploymentFrameworks::class);

        $partialOutside = Employee::query()->select('id', 'tenant_id')->findOrFail($outside->id);
        $partialCovered = Employee::query()->select('id', 'tenant_id')->findOrFail($covered->id);

        expect($frameworks->forEmployee($partialOutside, Carbon::today()))->toBeNull()
            ->and($frameworks->forEmployee($partialCovered, Carbon::today()))->toBe(EmploymentFramework::LABOUR_PROCLAMATION);
    });
});
