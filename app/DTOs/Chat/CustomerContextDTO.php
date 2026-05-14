<?php

declare(strict_types=1);

namespace App\DTOs\Chat;

use App\DTOs\Base\BaseDTO;

/**
 * Lightweight customer identification supplied by the frontend. Real customer
 * profile data is fetched via Storefront GraphQL on demand using the bearer
 * token if the visitor is logged in.
 */
class CustomerContextDTO extends BaseDTO
{
    public function __construct(
        public readonly ?string $customerId,
        public readonly bool $loggedIn,
        public readonly ?string $email,
        public readonly ?string $locale,
    ) {
        $this->validate();
    }

    protected function validate(): void
    {
        if ($this->email !== null && $this->email !== '') {
            $this->validateEmail($this->email, 'Customer email');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            customerId: isset($data['customer_id']) ? (string) $data['customer_id'] : (isset($data['customerId']) ? (string) $data['customerId'] : null),
            loggedIn: (bool) ($data['logged_in'] ?? $data['loggedIn'] ?? false),
            email: isset($data['email']) ? (string) $data['email'] : null,
            locale: isset($data['locale']) ? (string) $data['locale'] : null,
        );
    }

    public function isGuest(): bool
    {
        return ! $this->loggedIn || $this->customerId === null;
    }
}
