<?php

use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// API documentation (served by Scramble when installed)
if (class_exists(Scramble::class)) {
    Scramble::registerUiRoute(prefix: 'api/docs');
    Scramble::registerJsonSpecificationRoute(prefix: 'api/docs');
}
