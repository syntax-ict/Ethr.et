<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;

class ChartOfAccount extends Model
{
    use BelongsToTenant, HasAuditLog;

    protected $table = 'chart_of_accounts';

    protected $fillable = [
        'tenant_id',
        'key',
        'account_code',
        'account_name',
    ];

    protected $hidden = ['id', 'tenant_id'];
}
