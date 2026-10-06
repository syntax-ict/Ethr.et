<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Rules\VerifyFileContent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class VerifyUploadedFiles
{
    public function handle(Request $request, Closure $next): Response
    {
        $files = $request->allFiles();

        if (empty($files)) {
            return $next($request);
        }

        $flat = $this->flattenFiles($files);

        foreach ($flat as $key => $file) {
            $validator = Validator::make(
                [$key => $file],
                [$key => [new VerifyFileContent]],
            );

            if ($validator->fails()) {
                return response()->json([
                    'type' => 'validation_error',
                    'title' => 'File Verification Failed',
                    'status' => 422,
                    'detail' => 'Uploaded file content does not match its declared type.',
                    'errors' => $validator->errors()->toArray(),
                ], 422);
            }
        }

        return $next($request);
    }

    private function flattenFiles(array $files, string $prefix = ''): array
    {
        $result = [];

        foreach ($files as $key => $file) {
            $fullKey = $prefix ? "{$prefix}.{$key}" : $key;

            if ($file instanceof UploadedFile) {
                $result[$fullKey] = $file;
            } elseif (is_array($file)) {
                $result = array_merge($result, $this->flattenFiles($file, $fullKey));
            }
        }

        return $result;
    }
}
