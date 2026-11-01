<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A business-rule refusal: an HTTP status and a Hebrew message that is safe to show.
 * 403/404/405 are answered with the project's one vague message (see bootstrap/app.php) - the audit log keeps the real reason.
 */
class DomainError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
