<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// API documentation (served by Scramble when installed)
if (class_exists(\Dedoc\Scramble\Scramble::class)) {
    \Dedoc\Scramble\Scramble::registerUiRoute(prefix: 'api/docs');
    \Dedoc\Scramble\Scramble::registerJsonSpecificationRoute(prefix: 'api/docs');
}
