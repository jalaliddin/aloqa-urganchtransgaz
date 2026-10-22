<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A request that is well-formed but violates a business rule (workflow precondition,
 * segregation of duties, missing mandatory document…). Rendered as HTTP 422.
 */
class BusinessRuleException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(string $message, private readonly array $errors = [], private readonly string $rule = 'business_rule')
    {
        parent::__construct($message);
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->rule,
            'errors' => (object) $this->errors,
        ], 422);
    }
}
