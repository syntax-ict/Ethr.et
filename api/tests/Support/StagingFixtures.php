<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\MigrationBatch;
use App\Models\MigrationStagingRow;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Migration\WorkforceMigrationService;

/**
 * What the staged-list tests share: an organisation with someone signed in,
 * an employee with chosen blanks, and the review endpoints by name.
 *
 * A class, not helper functions, for the reason IntakeFixtures gives: Pest's
 * helpers are global and a second file declaring one makes collection fatal.
 */
final class StagingFixtures
{
    /** @return array{0: Tenant, 1: User} */
    public static function organisation(UserRole $role = UserRole::HR_ADMIN): array
    {
        $tenant = createTenant();

        return [$tenant, actingAsUser(['role' => $role], $tenant)];
    }

    /**
     * An employee with nothing but a name and a number, so a test states only
     * what it is about.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function employee(Tenant $tenant, array $attributes = []): Employee
    {
        return Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Abebe Kebede',
            'employee_code' => 'E-1',
            'email' => null,
            'phone' => null,
            'badge_number' => null,
            'department_id' => null,
            'salary_cents' => 0,
            'hire_date' => '2020-01-01',
            ...$attributes,
        ]);
    }

    public static function service(): WorkforceMigrationService
    {
        return app(WorkforceMigrationService::class);
    }

    public static function url(Tenant $tenant, string $path): string
    {
        return "http://{$tenant->subdomain}.ethr.test/api/v1/onboarding/migration/{$path}";
    }

    /**
     * Stage rows through the service, which takes every field a row can carry.
     * The request the wizard sends takes six of them.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function stage(Tenant $tenant, array $rows): MigrationBatch
    {
        return self::service()->stageFromRows($rows, $tenant->id);
    }

    public static function firstRow(MigrationBatch $batch): MigrationStagingRow
    {
        return $batch->rows()->orderBy('id')->firstOrFail();
    }

    /**
     * One field's entry in a row's list of changes, from the model or from a
     * response.
     *
     * @param  array<int, array<string, mixed>>|null  $changes
     * @return array<string, mixed>|null
     */
    public static function change(?array $changes, string $field): ?array
    {
        foreach ($changes ?? [] as $change) {
            if (($change['field'] ?? null) === $field) {
                return $change;
            }
        }

        return null;
    }
}
