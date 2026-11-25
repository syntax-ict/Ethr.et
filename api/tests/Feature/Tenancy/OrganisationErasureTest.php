<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\Tenancy\OrganisationErasure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\ParameterizedFixtures;

/**
 * Erasing an organisation that left: only once it is cancelled, deleted and
 * past its 30 days; every row with its `tenant_id` gone except the ones kept
 * on purpose; its files gone; another organisation untouched to the row; and
 * a tombstone that keeps the address reserved and the audit log meaningful.
 */
const ERASURE_TOKEN = 'a-maintenance-token-of-at-least-thirty-two-chars';

beforeEach(function () {
    config([
        'maintenance.token' => ERASURE_TOKEN,
        'maintenance.cache_store' => 'array',
        'tenancy.erasure_grace_days' => 30,
    ]);
    Cache::store('array')->flush();
    Storage::fake(config('filesystems.default'));
});

/**
 * One record of every kind (or, for a second organisation in the same test,
 * whose shared global fixtures exist already, a branch, a department and an
 * employee), a user with sign-in data, files, and an audit row.
 */
function erasableOrganisation(string $subdomain, bool $everyKind = true): Tenant
{
    $tenant = createTenant(['subdomain' => $subdomain]);

    if ($everyKind) {
        $employee = ParameterizedFixtures::build($tenant)['employee'];
    } else {
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $department = Department::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'department_id' => $department->id]);
        $department->forceFill(['head_employee_id' => $employee->id])->save();
    }

    $user = createUser(['employee_id' => $employee->id, 'email' => "admin@{$subdomain}.et"], $tenant);
    $user->createToken('auth');
    DB::table('sessions')->insert(['id' => "session-{$subdomain}", 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);
    DB::table('password_reset_tokens')->insert(['email' => "{$tenant->id}|admin@{$subdomain}.et", 'token' => 'hashed', 'created_at' => now()]);

    $disk = Storage::disk(config('filesystems.default'));
    $disk->put("tenants/{$tenant->public_id}/selfies/one.jpg", 'jpeg');
    $disk->put("tenants/{$tenant->public_id}/employees/e/documents/contract.pdf", 'pdf');
    $disk->put("exports/{$tenant->public_id}/export.zip", 'zip');
    $disk->put("backups/{$tenant->subdomain}/2026-11-01_010000.json", '{}');

    app(CurrentTenant::class)->set($tenant);
    AuditLog::record('employee.updated', $employee);
    app(CurrentTenant::class)->forget();

    return $tenant;
}

/** Cancelled and deleted, as the console leaves it, $daysAgo days ago. */
function closeOrganisation(Tenant $tenant, int $daysAgo = 31): Tenant
{
    $tenant->forceFill(['status' => TenantStatus::CANCELLED])->save();
    test()->travel(-$daysAgo)->days();
    $tenant->delete();
    test()->travelBack();

    return Tenant::withTrashed()->findOrFail($tenant->id);
}

/** @return array<string, int> rows per tenant-scoped table, for one organisation */
function rowsOf(Tenant $tenant): array
{
    $counts = [];
    foreach (app(OrganisationErasure::class)->tenantTables() as $table) {
        $counts[$table] = DB::table($table)->where('tenant_id', $tenant->id)->count();
    }

    return $counts;
}

function eraseTask(array $body): TestResponse
{
    return test()->withHeaders(['X-Maintenance-Token' => ERASURE_TOKEN])
        ->postJson('/api/v1/maintenance/erase-organisation', $body);
}

describe('who may be erased', function () {
    it('refuses an organisation that is not deleted, not cancelled, inside its 30 days, or already erased', function () {
        $erasure = app(OrganisationErasure::class);

        $live = createTenant(['subdomain' => 'still-here']);
        expect($erasure->refusal($live))->toBe('not_deleted');

        $suspended = createTenant(['subdomain' => 'only-suspended', 'status' => TenantStatus::SUSPENDED]);
        $suspended->delete();
        expect($erasure->refusal(Tenant::withTrashed()->find($suspended->id)))->toBe('not_cancelled');

        $recent = closeOrganisation(createTenant(['subdomain' => 'just-left']), daysAgo: 29);
        expect($erasure->refusal($recent))->toBe('in_grace_period')
            ->and($erasure->eligibleFrom($recent)->isFuture())->toBeTrue();

        $gone = closeOrganisation(createTenant(['subdomain' => 'long-gone']));
        expect($erasure->refusal($gone))->toBeNull();

        $erasure->erase($gone);
        expect($erasure->refusal($gone->fresh()))->toBe('already_erased');
        expect(fn () => $erasure->erase($gone->fresh()))->toThrow(RuntimeException::class);
    });
});

describe('what erasure removes and keeps', function () {
    it('erases every row and file of the organisation, and nothing of another', function () {
        $leaving = closeOrganisation(erasableOrganisation('leaving-org'));
        $staying = erasableOrganisation('staying-org', everyKind: false);
        $stayingRows = rowsOf($staying);
        $stayingFiles = Storage::disk(config('filesystems.default'))->allFiles();
        $auditBefore = AuditLog::withoutGlobalScopes()->where('tenant_id', $leaving->id)->count();
        $invoices = DB::table('invoices')->where('tenant_id', $leaving->id)->count();

        expect(array_sum(rowsOf($leaving)))->toBeGreaterThan(50);

        $certificate = app(OrganisationErasure::class)->erase($leaving);

        // Exhaustive by construction: every table with a tenant_id except
        // the four kept on purpose holds nothing of it.
        expect(array_filter(rowsOf($leaving)))->toBe([]);
        expect(DB::table('personal_access_tokens')->whereIn('tokenable_id', User::withoutGlobalScopes()->where('tenant_id', $staying->id)->pluck('id'))->count())->toBe(1)
            ->and(DB::table('sessions')->where('id', 'session-leaving-org')->exists())->toBeFalse()
            ->and(DB::table('password_reset_tokens')->where('email', 'like', $leaving->id.'|%')->exists())->toBeFalse()
            ->and(DB::table('password_reset_tokens')->where('email', 'like', $staying->id.'|%')->exists())->toBeTrue();

        $disk = Storage::disk(config('filesystems.default'));
        expect($disk->allFiles("tenants/{$leaving->public_id}"))->toBe([])
            ->and($disk->allFiles("exports/{$leaving->public_id}"))->toBe([])
            ->and($disk->allFiles('backups/leaving-org'))->toBe([]);

        // The other organisation, to the row and to the file.
        expect(rowsOf($staying))->toBe($stayingRows);
        $stayingNow = $disk->allFiles();
        foreach ($stayingFiles as $file) {
            if (! str_contains($file, $leaving->public_id) && ! str_contains($file, 'leaving-org')) {
                expect($stayingNow)->toContain($file);
            }
        }

        // Kept on purpose, and said so.
        expect(AuditLog::withoutGlobalScopes()->where('tenant_id', $leaving->id)->count())->toBe($auditBefore + 1)
            ->and(DB::table('invoices')->where('tenant_id', $leaving->id)->count())->toBe($invoices)
            ->and($certificate['retained'])->toHaveKey('audit_log')
            ->and($certificate['retained']['audit_log']['rows'])->toBe($auditBefore)
            ->and($certificate['rows_erased'])->not->toBeEmpty()
            ->and($certificate['files_erased'])->toBe(4)
            ->and($certificate['name_before_erasure'])->not->toBe(OrganisationErasure::ERASED_NAME)
            ->and($certificate['backups_rotate_out_by'])->toBe(now()->addDays(14)->toDateString());
    });

    it('leaves a tombstone that keeps the address reserved and names no one', function () {
        $leaving = closeOrganisation(erasableOrganisation('tombstone-org'));
        $leaving->forceFill(['custom_domain' => 'hr.tombstone.et', 'settings' => ['brand' => 'x']])->save();

        app(OrganisationErasure::class)->erase($leaving);

        $tomb = Tenant::withTrashed()->findOrFail($leaving->id);
        expect($tomb->name)->toBe(OrganisationErasure::ERASED_NAME)
            ->and($tomb->subdomain)->toBe('tombstone-org')
            ->and($tomb->public_id)->toBe($leaving->public_id)
            ->and($tomb->trashed())->toBeTrue()
            ->and($tomb->erased_at)->not->toBeNull()
            ->and($tomb->erasure_certificate['address'])->toBe('tombstone-org')
            ->and($tomb->custom_domain)->toBeNull()
            ->and($tomb->settings)->toBeNull()
            ->and($tomb->owner_user_id)->toBeNull();

        expect(Tenant::slugAvailableFor('tombstone-org'))->toBeFalse();

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'admin.tenant.erased')->sole();
        expect($audit->tenant_id)->toBe($leaving->id)
            ->and($audit->payload['via'])->toBe('command');
    });
});

describe('the command', function () {
    it('only shows what would go without --execute, and wants the address twice to erase', function () {
        $leaving = closeOrganisation(erasableOrganisation('command-org'));
        $before = array_sum(rowsOf($leaving));

        $this->artisan('ethr:erase-organisation', ['address' => 'command-org'])
            ->expectsOutputToContain('Nothing was erased')
            ->assertSuccessful();
        expect(array_sum(rowsOf($leaving)))->toBe($before);

        $this->artisan('ethr:erase-organisation', ['address' => 'command-org', '--execute' => true, '--confirm' => 'command-or'])
            ->expectsOutputToContain('must repeat the address')
            ->assertFailed();
        expect(array_sum(rowsOf($leaving)))->toBe($before);

        $this->artisan('ethr:erase-organisation', ['address' => 'command-org', '--execute' => true, '--confirm' => 'command-org'])
            ->expectsOutputToContain('Erased command-org')
            ->assertSuccessful();
        expect(array_sum(rowsOf($leaving)))->toBe(0);
    });

    it('says when an organisation inside its 30 days can be erased', function () {
        closeOrganisation(createTenant(['subdomain' => 'too-soon']), daysAgo: 10);

        $this->artisan('ethr:erase-organisation', ['address' => 'too-soon', '--execute' => true, '--confirm' => 'too-soon'])
            ->expectsOutputToContain('can be erased from')
            ->assertFailed();
    });

    it('refuses an address with no deleted organisation', function () {
        createTenant(['subdomain' => 'alive-org']);

        $this->artisan('ethr:erase-organisation', ['address' => 'alive-org'])
            ->expectsOutputToContain('Delete it in the platform console first')
            ->assertFailed();
    });
});

describe('the maintenance task', function () {
    it('answers 404 without the token', function () {
        closeOrganisation(createTenant(['subdomain' => 'tokenless']));

        test()->postJson('/api/v1/maintenance/erase-organisation', ['address' => 'tokenless'])->assertNotFound();
        expect(Tenant::withTrashed()->where('subdomain', 'tokenless')->value('erased_at'))->toBeNull();
    });

    it('is a dry run unless told to execute, and executes only with the address confirmed', function () {
        $leaving = closeOrganisation(erasableOrganisation('task-org'));
        $before = array_sum(rowsOf($leaving));

        eraseTask(['address' => 'task-org'])
            ->assertOk()
            ->assertJsonPath('status', 'dry-run')
            ->assertJsonPath('would_erase.files', 4);
        expect(array_sum(rowsOf($leaving)))->toBe($before);

        eraseTask(['address' => 'task-org', 'execute' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['confirm']);

        eraseTask(['address' => 'task-org', 'execute' => true, 'confirm' => 'task-org'])
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('certificate.address', 'task-org')
            ->assertJsonPath('certificate.erased_via', 'maintenance');
        expect(array_sum(rowsOf($leaving)))->toBe(0);

        eraseTask(['address' => 'task-org', 'execute' => true, 'confirm' => 'task-org'])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'already_erased');
    });

    it('refuses inside the grace period and says from when', function () {
        closeOrganisation(createTenant(['subdomain' => 'grace-org']), daysAgo: 5);

        eraseTask(['address' => 'grace-org', 'execute' => true, 'confirm' => 'grace-org'])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'in_grace_period');
    });
});
