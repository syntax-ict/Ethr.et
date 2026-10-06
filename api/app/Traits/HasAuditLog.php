<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\AuditLog;

trait HasAuditLog
{
    public function audit(string $action, array $payload = []): AuditLog
    {
        return AuditLog::record($action, $this, $payload);
    }
}
