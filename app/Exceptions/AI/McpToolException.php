<?php

declare(strict_types=1);

namespace App\Exceptions\AI;

use Throwable;

class McpToolException extends AIException
{
    public function __construct(
        string $message,
        protected ?int $rpcCode = null,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 502, 'mcp_tool_error', $context, $previous);
    }

    public function rpcCode(): ?int
    {
        return $this->rpcCode;
    }
}
