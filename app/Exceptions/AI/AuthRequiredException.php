<?php

declare(strict_types=1);

namespace App\Exceptions\AI;

use Throwable;

class AuthRequiredException extends AIException
{
    public function __construct(
        string $message = 'Customer Account authentication required.',
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 401, 'customer_auth_required', $context, $previous);
    }
}
