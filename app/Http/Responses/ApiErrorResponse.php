<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * Standard API error envelope for MailServer (and compatible) JSON endpoints.
 */
final class ApiErrorResponse
{
    /**
     * @param  array<string, list<string>|string>|array<empty>  $details
     */
    public static function make(
        string $code,
        string $message,
        int $status,
        array $details = [],
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details === [] ? (object) [] : $details,
            ],
        ], $status);
    }
}
