<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A business-rule refusal: an HTTP status plus a Hebrew message that is safe to show.
 */
class DomainError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
