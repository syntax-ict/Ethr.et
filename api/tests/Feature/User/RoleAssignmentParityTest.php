<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use App\Support\RoleAssignment;

// A job title may do automatically only what a person could do by hand on the
// Users page. RoleAssignment::mayChange is that question asked in one place;
// this proves it answers what PATCH /users/{user} with `role` actually allows,
// for every actor, target and new role, so the two cannot drift apart.
//
// The PATCH route also needs `users.update` (a permission, not a rule about two
// roles), and mayChange asks for it too: the table's expectation is mayChange
// alone, so an actor without the permission must be refused by both. Actors
// are the built-in roles; a custom role without the permission is covered in
// JobTitleAccessAuthorityTest.

it('answers exactly what PATCH /users/{user} with a role allows', function () {
    $tenant = createTenant();
    $mismatches = [];
    $checked = 0;

    foreach (UserRole::cases() as $actorRole) {
        if ($actorRole === UserRole::SUPER_ADMIN) {
            continue;
        }

        $actor = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $actorRole]);
        $this->actingAs($actor);

        foreach (UserRole::cases() as $targetRole) {
            foreach (UserRole::cases() as $to) {
                if ($to === $targetRole) {
                    continue;
                }

                $target = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $targetRole]);
                $expected = RoleAssignment::mayChange($actor, $target, $to);
                $status = $this->patchJson('/api/v1/users/'.$target->public_id, ['role' => $to->value])->status();
                $checked++;

                if (($status === 200) !== $expected) {
                    $mismatches[] = "{$actorRole->value} -> {$targetRole->value} to {$to->value}: mayChange=".json_encode($expected)." http={$status}";
                }
            }
        }
    }

    expect($checked)->toBeGreaterThan(200)->and($mismatches)->toBe([]);
});

it('answers the same for the organisation owner', function () {
    $tenant = createTenant();
    $actor = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::TENANT_ADMIN]);
    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::TENANT_ADMIN]);
    $tenant->update(['owner_user_id' => $owner->id]);
    $this->actingAs($actor);

    expect(RoleAssignment::mayChange($actor, $owner, UserRole::HR_ADMIN))->toBeFalse();
    $this->patchJson('/api/v1/users/'.$owner->public_id, ['role' => 'hr_admin'])->assertUnprocessable();
});

it('refuses an administrator of another organisation', function () {
    $a = createTenant();
    $b = createTenant();
    $outsider = User::factory()->create(['tenant_id' => $b->id, 'role' => UserRole::TENANT_ADMIN]);
    $target = User::factory()->create(['tenant_id' => $a->id, 'role' => UserRole::EMPLOYEE]);

    expect(RoleAssignment::mayChange($outsider, $target, UserRole::SUPERVISOR))->toBeFalse();
});

it('never lets someone change their own role', function () {
    $tenant = createTenant();

    foreach ([UserRole::TENANT_ADMIN, UserRole::HR_ADMIN, UserRole::EMPLOYEE] as $role) {
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);

        foreach (UserRole::cases() as $to) {
            expect(RoleAssignment::mayChange($user, $user, $to))->toBeFalse();
        }
    }
});

it('lets a super admin give any assignable role to anyone but themselves', function () {
    $tenant = createTenant();
    $super = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::SUPER_ADMIN]);
    $target = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::HR_ADMIN]);

    expect(RoleAssignment::mayChange($super, $target, UserRole::TENANT_ADMIN))->toBeTrue()
        ->and(RoleAssignment::mayChange($super, $target, UserRole::SUPER_ADMIN))->toBeFalse();
});
