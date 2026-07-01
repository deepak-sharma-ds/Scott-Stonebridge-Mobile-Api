<?php

namespace App\DTOs\FreeReading;

use App\DTOs\Base\BaseDTO;

/**
 * Free Reading Form Data Transfer Object
 *
 * Represents a "Free Email Reading" lead-capture form submission from the mobile app.
 */
class FreeReadingFormDTO extends BaseDTO
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $email,
        public readonly string $phone,
        public readonly ?string $phoneCountryCode,
    ) {
        $this->validate();
    }

    /**
     * Validate the form data.
     */
    protected function validate(): void
    {
        $this->validateRequired($this->firstName, 'First name');
        $this->validateRequired($this->lastName, 'Last name');
        $this->validateRequired($this->email, 'Email');
        $this->validateEmail($this->email, 'Email');
        $this->validateRequired($this->phone, 'Phone');
    }

    /**
     * Create a FreeReadingFormDTO from validated request data.
     *
     * @param  array<string, mixed>  $data  Validated request data
     */
    public static function fromRequest(array $data): self
    {
        return new self(
            firstName: $data['first_name'],
            lastName: $data['last_name'],
            email: $data['email'],
            phone: $data['phone'],
            phoneCountryCode: $data['phone_country_code'] ?? null,
        );
    }
}
