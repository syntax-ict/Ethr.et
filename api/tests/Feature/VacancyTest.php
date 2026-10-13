<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Department;
use App\Models\Tenant;
use App\Models\Vacancy;

/**
 * Vacancies inside the app: HR posts, edits and closes them; employees see
 * what is advertised now. Also the announcement side of the sign-in page: only
 * a whole-organisation announcement can be shown there.
 */
function vacancyUrl(Tenant $tenant, string $path = ''): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/vacancies{$path}";
}

it('lets HR post a vacancy, publishing it when opened', function () {
    $tenant = createTenant();
    $department = Department::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->postJson(vacancyUrl($tenant), [
        'title' => 'Payroll officer',
        'employment_type' => 'full_time',
        'description' => 'Run monthly payroll.',
        'department_id' => $department->public_id,
        'visibility' => 'public',
        'status' => 'open',
        'closes_on' => now()->addWeek()->toDateString(),
        'apply_email' => 'jobs@acme.et',
    ])->assertCreated()
        ->assertJsonPath('department.name', $department->name)
        ->assertJsonPath('status', 'open');

    expect($response->json('published_at'))->not->toBeNull();
    $this->assertDatabaseHas('audit_log', ['action' => 'vacancy.created']);
});

it('refuses what must not reach a public page', function (array $body, string $field) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(vacancyUrl($tenant), [
        'title' => 'Role',
        'employment_type' => 'full_time',
        'description' => 'Text',
        ...$body,
    ])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'an http apply link' => [['apply_url' => 'http://acme.et/apply'], 'apply_url'],
    'an unknown type' => [['employment_type' => 'gig'], 'employment_type'],
    'an unknown visibility' => [['visibility' => 'everyone'], 'visibility'],
    'a bad e-mail' => [['apply_email' => 'jobs at acme'], 'apply_email'],
    'zero positions' => [['positions' => 0], 'positions'],
]);

it('refuses a department from another organisation like one that does not exist', function () {
    $tenant = createTenant();
    $theirs = Department::factory()->create(['tenant_id' => createTenant()->id]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(vacancyUrl($tenant), [
        'title' => 'Role',
        'employment_type' => 'full_time',
        'description' => 'Text',
        'department_id' => $theirs->public_id,
    ])->assertUnprocessable()->assertJsonValidationErrors('department_id');
});

it('shows employees only what is advertised now, and nothing else exists for them', function () {
    $tenant = createTenant();
    $open = Vacancy::query()->create([
        'tenant_id' => $tenant->id, 'title' => 'Open', 'employment_type' => 'full_time',
        'description' => 'x', 'status' => 'open', 'published_at' => now()->subDay(),
    ]);
    $draft = Vacancy::query()->create([
        'tenant_id' => $tenant->id, 'title' => 'Draft', 'employment_type' => 'full_time',
        'description' => 'x', 'status' => 'draft',
    ]);
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    $titles = collect(test()->getJson(vacancyUrl($tenant))->assertOk()->json('data'))->pluck('title')->all();
    expect($titles)->toBe(['Open']);

    test()->getJson(vacancyUrl($tenant, "/{$open->public_id}"))->assertOk();
    test()->getJson(vacancyUrl($tenant, "/{$draft->public_id}"))->assertNotFound();
    test()->postJson(vacancyUrl($tenant), ['title' => 'x', 'employment_type' => 'full_time', 'description' => 'x'])
        ->assertForbidden();
});

it('lets HR close and delete a vacancy, keeping the first publish date on reopen', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $vacancy = Vacancy::query()->create([
        'tenant_id' => $tenant->id, 'title' => 'Role', 'employment_type' => 'contract',
        'description' => 'x', 'status' => 'open', 'published_at' => now()->subDays(3)->startOfSecond(),
    ]);
    $published = $vacancy->published_at?->toIso8601String();

    test()->putJson(vacancyUrl($tenant, "/{$vacancy->public_id}"), ['status' => 'closed'])
        ->assertOk()->assertJsonPath('status', 'closed');
    test()->putJson(vacancyUrl($tenant, "/{$vacancy->public_id}"), ['status' => 'open'])
        ->assertOk();
    expect($vacancy->fresh()?->published_at?->toIso8601String())->toBe($published);

    test()->deleteJson(vacancyUrl($tenant, "/{$vacancy->public_id}"))->assertNoContent();
    expect(Vacancy::query()->find($vacancy->id))->toBeNull();
    $this->assertDatabaseHas('audit_log', ['action' => 'vacancy.deleted']);
});

it('puts only a whole-organisation announcement on the sign-in page', function () {
    $tenant = createTenant();
    $department = Department::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/announcements";

    test()->postJson($url, [
        'title' => 'Dept news', 'body' => 'x',
        'target_type' => 'department', 'target_id' => $department->public_id,
        'show_on_landing' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors('show_on_landing');

    $id = test()->postJson($url, ['title' => 'All hands', 'body' => 'x', 'show_on_landing' => true])
        ->assertCreated()
        ->assertJsonPath('show_on_landing', true)
        ->json('public_id');

    // Narrowing the audience later takes it off the public page.
    test()->putJson("{$url}/{$id}", ['target_type' => 'department', 'target_id' => $department->public_id])
        ->assertOk()
        ->assertJsonPath('show_on_landing', false);
    expect(Announcement::query()->where('public_id', $id)->value('show_on_landing'))->toBeFalsy();
});
