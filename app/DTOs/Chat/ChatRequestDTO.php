<?php

declare(strict_types=1);

namespace App\DTOs\Chat;

use App\DTOs\Base\BaseDTO;

/**
 * Inbound chat request payload after validation. Wraps the user message,
 * session id, and full storefront context envelope.
 */
class ChatRequestDTO extends BaseDTO
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $message,
        public readonly ChatContextDTO $context,
        public readonly ?string $accessToken,
        public readonly ?string $ipAddress,
    ) {
        $this->validate();
    }

    protected function validate(): void
    {
        $this->validateRequired($this->sessionId, 'session_id');
        $this->validateRequired($this->message, 'message');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sessionId: (string) $data['session_id'],
            message: (string) $data['message'],
            context: ChatContextDTO::fromArray((array) ($data['context'] ?? [])),
            accessToken: isset($data['access_token']) ? (string) $data['access_token'] : null,
            ipAddress: isset($data['ip_address']) ? (string) $data['ip_address'] : null,
        );
    }
}
