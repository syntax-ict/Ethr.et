<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Events\TitleAccessHeld;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TitleAccessHeldNotification;
use App\Services\Access\PositionAccess;
use App\Services\CurrentTenant;
use App\Support\HealthSignals;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

// An account that follows its job title and does not hold the role the title
// gives is waiting for someone who may give it. `access:watch-held-titles`
// reads that from PositionAccess::heldQuery() and tells the people who can act,
// once per new set of waiting accounts, so a bulk change is one notice.

beforeEach(function () {
    Cache::flush();
    HealthSignals::store()->flush();
});

/** An account that follows a title giving $titleGives while holding $role. */
function heldNoticeAccount(Tenant $tenant, string $titleGives = 'hr_admin', UserRole $role = UserRole::EMPLOYEE): User
{
    $position = Position::factory()->create(['tenant_id' => $tenant->id, 'access_role' => $titleGives]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'position_id' => $position->id]);

    return User::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'role' => $role,
        'role_follows_position' => true,
    ]);
}

function heldNoticeSweep(): void
{
    app()->forgetScopedInstances();
    test()->artisan('access:watch-held-titles')->assertSuccessful();
}

function heldNoticeDeliver(Tenant $tenant, array $userIds): void
{
    app()->forgetScopedInstances();
    event(new TitleAccessHeld($tenant->id, $userIds));
}

/** An organisation with an admin who may give any role a title gives. */
function heldNoticeOrganisation(): Tenant
{
    $tenant = createTenant();
    createUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    return $tenant;
}

describe('the sweep', function () {
    it('dispatches one event for an account that starts waiting, and none on the next sweep', function () {
        Event::fake([TitleAccessHeld::class]);
        $tenant = heldNoticeOrganisation();
        $held = heldNoticeAccount($tenant);

        heldNoticeSweep();
        heldNoticeSweep();

        Event::assertDispatchedTimes(TitleAccessHeld::class, 1);
        Event::assertDispatched(TitleAccessHeld::class, fn (TitleAccessHeld $e) => $e->tenantId === $tenant->id && $e->userIds === [$held->id]);
    });

    it('makes a bulk change one event naming every account, not one each', function () {
        Event::fake([TitleAccessHeld::class]);
        $tenant = heldNoticeOrganisation();
        $ids = collect(range(1, 3))->map(fn () => heldNoticeAccount($tenant)->id)->sort()->values()->all();

        heldNoticeSweep();

        Event::assertDispatchedTimes(TitleAccessHeld::class, 1);
        Event::assertDispatched(TitleAccessHeld::class, fn (TitleAccessHeld $e) => collect($e->userIds)->sort()->values()->all() === $ids);
    });

    it('tells again about an account that was applied and then held a second time', function () {
        Event::fake([TitleAccessHeld::class]);
        $tenant = heldNoticeOrganisation();
        $held = heldNoticeAccount($tenant);

        heldNoticeSweep();
        $held->update(['role' => UserRole::HR_ADMIN]);
        heldNoticeSweep();
        $held->update(['role' => UserRole::EMPLOYEE]);
        heldNoticeSweep();

        Event::assertDispatchedTimes(TitleAccessHeld::class, 2);
    });

    it('tells only about the accounts that are new when one more starts waiting', function () {
        Event::fake([TitleAccessHeld::class]);
        $tenant = heldNoticeOrganisation();
        $first = heldNoticeAccount($tenant);

        heldNoticeSweep();
        $second = heldNoticeAccount($tenant);
        heldNoticeSweep();

        $sent = Event::dispatched(TitleAccessHeld::class)->map(fn (array $args) => $args[0]->userIds)->all();
        expect($sent)->toBe([[$first->id], [$second->id]]);
    });

    it('does not count an account that does not follow its title, or one that already holds the role', function () {
        Event::fake([TitleAccessHeld::class]);
        $tenant = heldNoticeOrganisation();
        heldNoticeAccount($tenant)->update(['role_follows_position' => false]);
        heldNoticeAccount($tenant, 'hr_admin', UserRole::HR_ADMIN);

        heldNoticeSweep();

        Event::assertNotDispatched(TitleAccessHeld::class);
    });

    it('keeps each organisation to its own accounts', function () {
        Event::fake([TitleAccessHeld::class]);
        $a = heldNoticeOrganisation();
        $heldA = heldNoticeAccount($a);
        $b = heldNoticeOrganisation();
        $heldB = heldNoticeAccount($b);

        heldNoticeSweep();

        Event::assertDispatchedTimes(TitleAccessHeld::class, 2);
        Event::assertDispatched(TitleAccessHeld::class, fn (TitleAccessHeld $e) => $e->tenantId === $a->id && $e->userIds === [$heldA->id]);
        Event::assertDispatched(TitleAccessHeld::class, fn (TitleAccessHeld $e) => $e->tenantId === $b->id && $e->userIds === [$heldB->id]);
    });

    it('does not announce the same accounts again after a release empties the default cache', function () {
        Event::fake([TitleAccessHeld::class]);
        $tenant = heldNoticeOrganisation();
        heldNoticeAccount($tenant);

        heldNoticeSweep();
        Cache::store()->flush();
        heldNoticeSweep();

        Event::assertDispatchedTimes(TitleAccessHeld::class, 1);
    });

    it('waits to announce a hold nobody may act on until someone who may give the role joins', function () {
        Event::fake([TitleAccessHeld::class]);
        $tenant = createTenant();
        createUser(['role' => UserRole::HR_ADMIN], $tenant);
        $held = heldNoticeAccount($tenant, 'hr_admin');

        heldNoticeSweep();
        Event::assertNotDispatched(TitleAccessHeld::class);

        createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        heldNoticeSweep();

        Event::assertDispatched(TitleAccessHeld::class, fn (TitleAccessHeld $e) => $e->userIds === [$held->id]);
    });

    it('does not count a suspended admin as someone who may act', function () {
        Event::fake([TitleAccessHeld::class]);
        $tenant = createTenant();
        createUser(['role' => UserRole::TENANT_ADMIN, 'status' => 'suspended'], $tenant);
        heldNoticeAccount($tenant, 'supervisor');

        heldNoticeSweep();

        Event::assertNotDispatched(TitleAccessHeld::class);
    });
});

describe('the notice', function () {
    it('goes to an organisation admin who may give the role, with the count and a link to the filtered list', function () {
        Notification::fake();
        $tenant = createTenant();
        $admin = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $held = [heldNoticeAccount($tenant)->id, heldNoticeAccount($tenant)->id];

        heldNoticeDeliver($tenant, $held);

        Notification::assertSentTo($admin, TitleAccessHeldNotification::class, function (TitleAccessHeldNotification $n) use ($admin) {
            $data = $n->toArray($admin);

            return $data['count'] === 2 && $data['url'] === '/settings/users?title=held';
        });
    });

    it('does not go to an HR admin when the role waiting is HR admin, which is at their own level', function () {
        Notification::fake();
        $tenant = createTenant();
        $admin = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);
        $held = heldNoticeAccount($tenant, 'hr_admin');

        heldNoticeDeliver($tenant, [$held->id]);

        Notification::assertSentTo($admin, TitleAccessHeldNotification::class);
        Notification::assertNotSentTo($hr, TitleAccessHeldNotification::class);
    });

    it('goes to an HR admin when the role waiting is one they may give', function () {
        Notification::fake();
        $tenant = createTenant();
        $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);
        $held = heldNoticeAccount($tenant, 'supervisor');

        heldNoticeDeliver($tenant, [$held->id]);

        Notification::assertSentTo($hr, TitleAccessHeldNotification::class);
    });

    it('never tells someone about their own account', function () {
        Notification::fake();
        $tenant = createTenant();
        $admin = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $own = heldNoticeAccount($tenant, 'supervisor', UserRole::HR_ADMIN);

        heldNoticeDeliver($tenant, [$own->id]);

        Notification::assertSentTo($admin, TitleAccessHeldNotification::class);
        Notification::assertNotSentTo($own, TitleAccessHeldNotification::class);
    });

    it('tells nobody when the account was applied while the notice waited in the queue', function () {
        Notification::fake();
        $tenant = createTenant();
        createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $held = heldNoticeAccount($tenant);
        $held->update(['role' => UserRole::HR_ADMIN]);

        heldNoticeDeliver($tenant, [$held->id]);

        Notification::assertNothingSent();
    });

    it('never reaches another organisation\'s admin, and leaves no organisation set on the worker', function () {
        Notification::fake();
        $a = createTenant();
        $adminA = createUser(['role' => UserRole::TENANT_ADMIN], $a);
        $held = heldNoticeAccount($a);
        $b = createTenant();
        $adminB = createUser(['role' => UserRole::TENANT_ADMIN], $b);

        heldNoticeDeliver($a, [$held->id]);

        Notification::assertSentTo($adminA, TitleAccessHeldNotification::class);
        Notification::assertNotSentTo($adminB, TitleAccessHeldNotification::class);
        expect(app(CurrentTenant::class)->get())->toBeNull();
    });

    it('never names an account of another organisation that an id happened to match', function () {
        Notification::fake();
        $a = createTenant();
        $adminA = createUser(['role' => UserRole::TENANT_ADMIN], $a);
        $b = createTenant();
        $heldB = heldNoticeAccount($b);

        heldNoticeDeliver($a, [$heldB->id]);

        Notification::assertNotSentTo($adminA, TitleAccessHeldNotification::class);
        Notification::assertNothingSent();
    });

    it('renders in the language of the page it opens in, for one account and for several', function () {
        $notification = new TitleAccessHeldNotification(1);
        $admin = new User;

        expect($notification->toArray($admin)['message'])->toContain('1 account is waiting');
        expect((new TitleAccessHeldNotification(3))->toArray($admin)['message'])->toContain('3 accounts are waiting');
    });
});

describe('the sweep and the listener together', function () {
    it('tells the admin when the sweep finds a held account, with the event sent through the dispatcher', function () {
        Notification::fake();
        $tenant = createTenant();
        $admin = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        heldNoticeAccount($tenant);

        heldNoticeSweep();

        Notification::assertSentTo($admin, TitleAccessHeldNotification::class);
    });
});
