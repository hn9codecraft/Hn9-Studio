<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Builds the canonical HN9 API JSON envelope. Controllers use these helpers so
 * every response — success or error — shares one predictable shape.
 *
 * Success: { "data": ..., "meta"?: ... }
 * Error:   { "message": ..., "error_code": ..., "errors"?: ..., "context"?: ... }
 */
final class ApiResponse
{
    /**
     * A successful response wrapping arbitrary data.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function success(mixed $data = null, int $status = 200, array $meta = []): JsonResponse
    {
        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * A "201 Created" response.
     */
    public static function created(mixed $data = null): JsonResponse
    {
        return self::success($data, 201);
    }

    /**
     * A "204 No Content" response.
     */
    public static function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * An error response.
     *
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $context
     */
    public static function error(
        string $message,
        string $errorCode = 'error',
        int $status = 400,
        array $errors = [],
        array $context = [],
    ): JsonResponse {
        $payload = [
            'message' => $message,
            'error_code' => $errorCode,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        if ($context !== []) {
            $payload['context'] = $context;
        }

        return response()->json($payload, $status);
    }
}
