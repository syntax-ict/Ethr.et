<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Prints a VAPID key pair for web push. Run once, put both lines in .env,
 * then `optimize`. Rotating the pair silently ends every existing
 * subscription, so do it only when the private key has leaked.
 */
class GenerateVapidKeysCommand extends Command
{
    protected $signature = 'ethr:webpush-keys';

    protected $description = 'Generate the VAPID key pair web push needs (prints them; changes nothing)';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->line('WEBPUSH_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('WEBPUSH_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();
        $this->warn('Put both lines in .env and run optimize. Keep the private key secret; never rotate it casually.');

        return self::SUCCESS;
    }
}
