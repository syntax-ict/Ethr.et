<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanupExpiredDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $deleted = 0;

        $deleted += DB::table('notifications')
            ->where('created_at', '<', now()->subDays(90))
            ->delete();

        $deleted += DB::table('webhook_deliveries')
            ->where('created_at', '<', now()->subDays(30))
            ->delete();

        Log::info("CleanupExpiredDataJob: deleted {$deleted} expired records.");
    }
}
