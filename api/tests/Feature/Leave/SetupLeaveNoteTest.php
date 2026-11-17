<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\LeaveType;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Onboarding\LeaveTypeCatalog;
use Carbon\Carbon;

/*
 * The guided setup's note for a private organisation (UAT 2026-11-17, D-2)
 * said ETHR does not work out the annual-leave service day or sick pay in
 * steps. It does both, so the note must not say otherwise, in any language,
 * and what it now tells people to leave as proposed must be what the setup
 * proposes and what the balance applies.
 */

test('the private-organisation leave note does not deny the service day or the sick-leave steps', function (string $locale, string $denial, string $claim) {
    $note = trans('onboarding.review.leave_private', [], $locale);

    expect($note)->not->toContain($denial)
        ->and($note)->toContain($claim);
})->with([
    'English' => ['en', 'does not calculate', 'ETHR applies both'],
    'Amharic' => ['am', 'አያሰላም', 'ይተገብራል'],
    'Afaan Oromoo' => ['om', 'hin herregu', 'hojiirra oolcha'],
]);

test('what the note promises is what the setup proposes and the balance applies', function () {
    $catalog = app(LeaveTypeCatalog::class);
    $annual = $catalog->resolve('annual');

    // The setup proposes the service day, and sick leave that follows Art. 85.
    expect($annual['seniority_every_years'])->toBe(2)
        ->and($annual['seniority_extra_days'])->toBe(1)
        ->and($catalog->resolve('sick')['follows_art85'])->toBeTrue();

    // An employee of five completed years has 16 + 2 days: the step is applied.
    $tenant = createTenant();
    $type = LeaveType::factory()->create([
        'tenant_id' => $tenant->id,
        'default_days' => $annual['default_days'],
        'seniority_every_years' => $annual['seniority_every_years'],
        'seniority_extra_days' => $annual['seniority_extra_days'],
    ]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2021-01-01']);

    expect(app(LeaveBalanceService::class)->yearlyEntitlement($employee, $type, Carbon::parse('2026-06-01')))->toBe(18.0);
});
