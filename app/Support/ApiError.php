<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Consistent shape for every custom (non-framework-default) error response
 * in the API: a human-readable `message`, a stable snake_case `error_code`
 * the Flutter client maps to a localized string, and optional `meta` with
 * structured context (e.g. which role was required, which surgery
 * conflicted). Framework-default error responses (401/403/404/422 raised by
 * Laravel itself, e.g. missing route-model-binding or FormRequest
 * validation) are normalized separately in bootstrap/app.php.
 */
class ApiError
{
    public static function make(string $message, string $errorCode, int $status, array $meta = [], array $errors = []): JsonResponse
    {
        $payload = [
            'message' => $message,
            'error_code' => $errorCode,
        ];

        if (! empty($meta)) {
            $payload['meta'] = $meta;
        }

        if (! empty($errors)) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}
