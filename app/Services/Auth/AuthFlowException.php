<?php
namespace App\Services\Auth;

use RuntimeException;
use Illuminate\Http\JsonResponse;

final class AuthFlowException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        string $message,
        public readonly array $extra = [],
        public readonly ?int $retryAfter = null,
    ) { parent::__construct($message); }

    public static function make(int $status, string $code, string $message, array $extra = [], ?int $retryAfter = null): self
    {
        return new self($status, $code, $message, $extra, $retryAfter);
    }

    public function response(): JsonResponse
    {
        $headers = $this->retryAfter !== null ? ['Retry-After' => (string) max(1, $this->retryAfter)] : [];
        return response()->json(array_merge([
            'success' => false,
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ], $this->extra), $this->httpStatus, $headers);
    }
}
