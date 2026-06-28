<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CapPagination
{
    public function handle(Request $request, Closure $next): Response
    {
        $perPage = $request->integer('per_page', 25);

        if ($perPage > 100) {
            $request->merge(['per_page' => 100]);
        }

        if ($perPage < 1) {
            $request->merge(['per_page' => 25]);
        }

        return $next($request);
    }
}
