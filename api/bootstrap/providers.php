<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    App\Providers\TenantServiceProvider::class,
    Laravel\Horizon\HorizonServiceProvider::class,
];
