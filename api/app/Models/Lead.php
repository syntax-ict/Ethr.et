<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * An enquiry from the public contact form.
 *
 * No tenant scope, and deliberately so — a lead comes from someone who is not a
 * tenant yet, so there is no owner to scope it to. That makes it unlike every
 * other entry in TenantIsolationTest::GLOBAL_MODELS, which are catalogues every
 * tenant is *meant* to read. This one no tenant should ever see: the missing
 * `tenant_id` is an absent owner, not shared access.
 *
 * So the rule for anyone adding a read path: it belongs behind `admin.manage`
 * on the platform host, never on a tenant-facing route. Nothing reads it today.
 */
class Lead extends Model
{
    use HasPublicId;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'organization',
        'message',
    ];

    protected $hidden = [
        'id',
    ];
}
