<?php

namespace App\DTOs\Contact;

use App\DTOs\Base\BaseDTO;
use InvalidArgumentException;

/**
 * Contact Data Transfer Object
 * 
 * Represents contact form submission data with validation.
 * Used for customer inquiry submissions in the mobile API.
 * 
 * Requirements: 11.9, 11.11, 11.12
 */
class ContactDTO extends BaseDTO
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $subject,
        public readonly string $message,
        public readonly ?string $phone,
    ) {
        $this->validate();
    }

    /**
     * Validate the contact form data.
     * 
     * @throws InvalidArgumentException
     */
    protected function validate(): void
    {
        $this->validateRequired($this->name, 'Name');
        $this->validateRequired($this->email, 'Email');
        $this->validateEmail($this->email, 'Email');
        $this->validateRequired($this->message, 'Message');
        
        if (strlen($this->message) < 10) {
            throw new InvalidArgumentException('Message must be at least 10 characters');
        }
    }

    /**
     * Create a ContactDTO from request data.
     * 
     * Transforms validated request data into a typed DTO instance.
     * Used for creating DTOs from form submissions.
     * 
     * @param array $data Request data from contact form
     * @return self
     */
    public static function fromRequest(array $data): self
    {
        return new self(
            name: $data['name'],
            email: $data['email'],
            subject: $data['subject'] ?? null,
            message: $data['message'],
            phone: $data['phone'] ?? null,
        );
    }

    /**
     * Create a ContactDTO from Shopify API response data.
     * 
     * This method is included for consistency with other DTOs,
     * but contact forms typically don't come from Shopify responses.
     * 
     * @param array $data Raw contact data
     * @return self
     */
    public static function fromShopifyResponse(array $data): self
    {
        return self::fromRequest($data);
    }
}
