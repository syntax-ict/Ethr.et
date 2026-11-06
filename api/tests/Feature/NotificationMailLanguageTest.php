<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Device;
use App\Notifications\ApprovalReminderNotification;
use App\Notifications\DeviceOfflineNotification;

// A-Z review G-05 (2026-11-04). Two emails had a translated subject over an
// English body and button, so an Amharic approver read English every day.
// The whole email follows the reader's language.

it('writes the approval reminder and the device alert in the reader\'s language', function (string $locale, array $reminder, array $alert) {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id, 'branch_id' => Branch::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Bole'])->id, 'name' => 'Gate 1',
    ]);
    app()->setLocale($locale);

    $reminderMail = (new ApprovalReminderNotification(3, 30))->toMail($user);
    $alertMail = (new DeviceOfflineNotification($device))->toMail($user);

    expect([...$reminderMail->introLines, $reminderMail->actionText])->toBe($reminder)
        ->and([...$alertMail->introLines, $alertMail->actionText])->toBe($alert);
})->with([
    'Amharic' => ['am',
        ['3 የሚጠብቁዎት የማጽደቅ ጥያቄዎች አሉ።', 'በጣም የቆየው ለ30 ሰዓት ሲጠብቅ ቆይቷል።', 'ማጽደቂያዎችን ይመልከቱ'],
        ['በBole ያለው "Gate 1" መሣሪያ ከመስመር ውጭ ሆኗል።', 'የመሣሪያውን ሁኔታ ይመልከቱ'],
    ],
    'English' => ['en',
        ['You have 3 approval requests waiting for you.', 'The oldest has been waiting 30 hours.', 'Review approvals'],
        ['The device "Gate 1" at Bole has gone offline.', 'View device status'],
    ],
]);
