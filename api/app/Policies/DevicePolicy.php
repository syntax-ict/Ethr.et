<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Device;
use App\Models\User;

class DevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('device.viewAny');
    }

    public function view(User $user, Device $device): bool
    {
        return $user->hasPermission('device.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('device.create');
    }

    public function update(User $user, Device $device): bool
    {
        return $user->hasPermission('device.update');
    }

    public function delete(User $user, Device $device): bool
    {
        return $user->hasPermission('device.delete');
    }
}
