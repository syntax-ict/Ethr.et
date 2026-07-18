<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\CleanupExpiredDataJob;
use App\Jobs\GenerateMonthlyInvoicesJob;
use App\Jobs\HandleOverdueInvoicesJob;
use App\Jobs\ScanMissingPunchesJob;
use App\Notifications\SystemAlertNotification;
use Illuminate\Support\Facades\Notification;

test('GenerateMonthlyInvoicesJob notifies every super admin when it fails entirely', function () {
    Notification::fake();

    $tenant = createTenant();
    $superAdmin1 = createUser(['role' => UserRole::SUPER_ADMIN], $tenant);
    $superAdmin2 = createUser(['role' => UserRole::SUPER_ADMIN], createTenant());
    createUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    (new GenerateMonthlyInvoicesJob)->failed(new RuntimeException('DB connection lost'));

    Notification::assertSentTo($superAdmin1, SystemAlertNotification::class);
    Notification::assertSentTo($superAdmin2, SystemAlertNotification::class);
    Notification::assertCount(2);
});

test('HandleOverdueInvoicesJob notifies every super admin when it fails entirely', function () {
    Notification::fake();

    $tenant = createTenant();
    $superAdmin = createUser(['role' => UserRole::SUPER_ADMIN], $tenant);

    (new HandleOverdueInvoicesJob)->failed(new RuntimeException('DB connection lost'));

    Notification::assertSentTo($superAdmin, SystemAlertNotification::class);
});

test('CleanupExpiredDataJob failure is handled without throwing and without alerting anyone', function () {
    Notification::fake();

    (new CleanupExpiredDataJob)->failed(new RuntimeException('disk full'));

    Notification::assertNothingSent();
});

test('ScanMissingPunchesJob failure is handled without throwing and without alerting anyone', function () {
    Notification::fake();

    (new ScanMissingPunchesJob(1, now()->format('Y-m-d')))->failed(new RuntimeException('timeout'));

    Notification::assertNothingSent();
});
