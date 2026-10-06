<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeatureFlag extends Model
{
    protected $fillable = [
        'tenant_id',
        'key',
        'enabled',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function enabled(string $key, ?Tenant $tenant = null): bool
    {
        if ($tenant) {
            $flag = self::where('key', $key)->where('tenant_id', $tenant->id)->first();
            if ($flag) {
                return $flag->enabled;
            }
        }

        $global = self::where('key', $key)->whereNull('tenant_id')->first();

        return $global?->enabled ?? false;
    }
}
