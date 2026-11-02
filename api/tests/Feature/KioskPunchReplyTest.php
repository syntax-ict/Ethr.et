<?php

declare(strict_types=1);

use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\KioskSession;
use App\Models\Position;
use App\Services\CurrentTenant;

/*
 * Until 2026-11-01 the kiosk punch answer carried the whole employee record to
 * a signed-out terminal. Codes are sequential (EMP-0001, EMP-0002 …) and the
 * kiosk PIN is off by default, so anyone at a lobby tablet could read every
 * colleague's email, phone and date of birth by typing codes. The terminal
 * needs to show who punched, and nothing more.
 */
it('answers a kiosk punch with the name, job title and photo, and nothing that identifies the person further', function () {
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $tenant->id]);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $position = Position::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Machine operator']);
    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'position_id' => $position->id,
        'employee_code' => 'K-LEAK',
        'email' => 'private@example.test',
        'phone' => '+251911000000',
        'date_of_birth' => '1990-01-01',
    ]);
    $session = KioskSession::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'status' => 'active']);
    app(CurrentTenant::class)->forget();

    $response = $this->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk/check-in", [
        'employee_code' => 'K-LEAK',
        'type' => 'auto',
        'idempotency_key' => 'k-leak-1',
    ], ['X-Kiosk-Token' => $session->token])
        ->assertCreated()
        ->assertJsonPath('punch', 'check_in')
        ->assertJsonPath('employee.position', 'Machine operator')
        ->assertJsonStructure(['public_id', 'check_in', 'employee_name', 'employee' => ['name', 'name_am', 'position', 'photo_url']]);

    $body = json_encode($response->json());
    expect($body)->not->toContain('private@example.test')
        ->and($body)->not->toContain('+251911000000')
        ->and($body)->not->toContain('1990-01-01')
        ->and($response->json('employee'))->not->toHaveKeys(['email', 'phone', 'date_of_birth', 'public_id']);
});
