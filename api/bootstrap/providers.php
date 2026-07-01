<?php

use App\Providers\AppServiceProvider;
use App\Providers\TenantServiceProvider;
use Laravel\Horizon\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    TenantServiceProvider::class,
    HorizonServiceProvider::class,
];
