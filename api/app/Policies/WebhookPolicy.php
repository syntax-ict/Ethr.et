<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class WebhookPolicy
{
    public function manage(User $user): bool
    {
        return $user->hasPermission('webhook.manage');
    }
}
