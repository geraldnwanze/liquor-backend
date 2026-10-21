<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A business-rule failure with a machine-readable code, rendered as JSON: {message, code, details?}. */
class CommerceException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 422, public readonly array $details = [])
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'details' => $this->details ?: null,
        ], fn ($v) => $v !== null), $this->status);
    }
}
