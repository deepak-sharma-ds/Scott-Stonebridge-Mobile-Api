<?php

namespace App\DTOs\Base;

use InvalidArgumentException;

/**
 * Base Data Transfer Object
 * 
 * Provides common validation patterns and serialization methods for all DTOs.
 * All concrete DTOs should extend this class and implement the validate() method.
 * 
 * Requirements: 16.7, 16.8
 */
abstract class BaseDTO
{
    /**
     * Validate the DTO data.
     * 
     * Concrete classes must implement this method to validate their specific fields.
     * Should throw InvalidArgumentException for invalid data.
     * 
     * @throws InvalidArgumentException
     */
    abstract protected function validate(): void;

    /**
     * Convert the DTO to an array representation.
     * 
     * Uses reflection to get all public readonly properties and their values.
     * Recursively converts nested DTOs and arrays of DTOs to arrays.
     * 
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];
        $reflection = new \ReflectionClass($this);
        
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();
            $value = $property->getValue($this);
            
            $result[$name] = $this->convertValue($value);
        }
        
        return $result;
    }

    /**
     * Convert a value to its array representation.
     * 
     * Handles nested DTOs, arrays of DTOs, and primitive values.
     * 
     * @param mixed $value
     * @return mixed
     */
    protected function convertValue(mixed $value): mixed
    {
        // Handle null values
        if ($value === null) {
            return null;
        }
        
        // Handle nested DTOs
        if ($value instanceof self) {
            return $value->toArray();
        }
        
        // Handle arrays (including arrays of DTOs)
        if (is_array($value)) {
            return array_map(fn($item) => $this->convertValue($item), $value);
        }
        
        // Return primitive values as-is
        return $value;
    }

    /**
     * Validate that a required string field is not empty.
     * 
     * @param string|null $value
     * @param string $fieldName
     * @throws InvalidArgumentException
     */
    protected function validateRequired(?string $value, string $fieldName): void
    {
        if (empty($value)) {
            throw new InvalidArgumentException("{$fieldName} is required");
        }
    }

    /**
     * Validate that an email address is valid.
     * 
     * @param string $email
     * @param string $fieldName
     * @throws InvalidArgumentException
     */
    protected function validateEmail(string $email, string $fieldName = 'Email'): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("{$fieldName} must be a valid email address");
        }
    }

    /**
     * Validate that a numeric value is positive.
     * 
     * @param int|float $value
     * @param string $fieldName
     * @throws InvalidArgumentException
     */
    protected function validatePositive(int|float $value, string $fieldName): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException("{$fieldName} must be positive");
        }
    }

    /**
     * Validate that a numeric value is non-negative.
     * 
     * @param int|float $value
     * @param string $fieldName
     * @throws InvalidArgumentException
     */
    protected function validateNonNegative(int|float $value, string $fieldName): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException("{$fieldName} must be non-negative");
        }
    }

    /**
     * Validate that an array is not empty.
     * 
     * @param array $value
     * @param string $fieldName
     * @throws InvalidArgumentException
     */
    protected function validateNotEmpty(array $value, string $fieldName): void
    {
        if (empty($value)) {
            throw new InvalidArgumentException("{$fieldName} cannot be empty");
        }
    }

    /**
     * Validate that a value is one of the allowed values.
     * 
     * @param mixed $value
     * @param array $allowedValues
     * @param string $fieldName
     * @throws InvalidArgumentException
     */
    protected function validateInArray(mixed $value, array $allowedValues, string $fieldName): void
    {
        if (!in_array($value, $allowedValues, true)) {
            $allowed = implode(', ', $allowedValues);
            throw new InvalidArgumentException("{$fieldName} must be one of: {$allowed}");
        }
    }
}
