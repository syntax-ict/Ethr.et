<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Tenant;
use App\Services\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $current = app(CurrentTenant::class);

            if ($current->resolved()) {
                $builder->where($builder->getModel()->getTable().'.tenant_id', $current->id());
            } else {
                $builder->whereRaw('0 = 1');
            }
        });

        static::creating(function (Model $model): void {
            $current = app(CurrentTenant::class);

            if ($current->resolved() && ! $model->tenant_id) {
                $model->tenant_id = $current->id();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
