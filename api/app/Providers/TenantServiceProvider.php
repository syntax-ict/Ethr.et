<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\CurrentTenant;
use Illuminate\Support\ServiceProvider;

class TenantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrentTenant::class);
    }
}
