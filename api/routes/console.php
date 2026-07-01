<?php

use App\Jobs\GenerateMonthlyInvoicesJob;
use App\Jobs\HandleOverdueInvoicesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Generate monthly invoices on the 1st of each month at 06:00 EAT
Schedule::job(new GenerateMonthlyInvoicesJob())->monthlyOn(1, '03:00');

// Handle overdue invoices daily at 07:00 EAT (04:00 UTC)
Schedule::job(new HandleOverdueInvoicesJob())->dailyAt('04:00');
