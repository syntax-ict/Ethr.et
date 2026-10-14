<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ApiReference;
use Illuminate\Console\Command;
use Illuminate\Routing\Router;

/**
 * Write the tenant-facing API reference from an exported OpenAPI document.
 *
 *   php artisan scramble:export --path=openapi.json
 *   php artisan ethr:api-reference openapi.json
 *
 * `npm run generate:api` runs both, and scripts/api-types-check.sh runs them
 * into a temp directory and fails when the committed file differs.
 */
class ApiReferenceCommand extends Command
{
    protected $signature = 'ethr:api-reference
        {source=openapi.json : The document scramble:export wrote}
        {--output= : Where to write it (default resources/api-reference.json)}';

    protected $description = 'Build the API reference an organisation\'s developers see';

    public function handle(Router $router): int
    {
        $source = (string) $this->argument('source');
        $raw = is_file($source) ? file_get_contents($source) : false;
        $openApi = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($openApi)) {
            $this->error("Could not read an OpenAPI document from {$source}.");

            return self::FAILURE;
        }

        $reference = ApiReference::build($openApi, $router);
        $output = (string) ($this->option('output') ?: base_path(ApiReference::PATH));

        file_put_contents($output, json_encode($reference, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $operations = array_sum(array_map('count', $reference['paths']));
        $this->info("API reference: {$operations} operations written to {$output}.");

        return self::SUCCESS;
    }
}
