<?php

namespace Tests\Unit\DTOs;

use App\DTOs\Base\BaseDTO;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class BaseDTOTest extends TestCase
{
    /**
     * Test that toArray() converts DTO properties to array.
     */
    public function test_to_array_converts_properties_to_array(): void
    {
        $dto = new class('test-id', 'Test Title', 'test-handle') extends BaseDTO {
            public function __construct(
                public readonly string $id,
                public readonly string $title,
                public readonly string $handle,
            ) {
                $this->validate();
            }

            protected function validate(): void
            {
                $this->validateRequired($this->id, 'ID');
            }
        };

        $result = $dto->toArray();

        $this->assertIsArray($result);
        $this->assertEquals('test-id', $result['id']);
        $this->assertEquals('Test Title', $result['title']);
        $this->assertEquals('test-handle', $result['handle']);
    }

    /**
     * Test that toArray() handles null values correctly.
     */
    public function test_to_array_handles_null_values(): void
    {
        $dto = new class('test-id', null) extends BaseDTO {
            public function __construct(
                public readonly string $id,
                public readonly ?string $description,
            ) {
                $this->validate();
            }

            protected function validate(): void
            {
                $this->validateRequired($this->id, 'ID');
            }
        };

        $result = $dto->toArray();

        $this->assertNull($result['description']);
    }

    /**
     * Test that toArray() handles nested DTOs.
     */
    public function test_to_array_handles_nested_dtos(): void
    {
        $nestedDto = new class('nested-id', 'Nested Title') extends BaseDTO {
            public function __construct(
                public readonly string $id,
                public readonly string $title,
            ) {
                $this->validate();
            }

            protected function validate(): void
            {
                $this->validateRequired($this->id, 'ID');
            }
        };

        $parentDto = new class('parent-id', $nestedDto) extends BaseDTO {
            public function __construct(
                public readonly string $id,
                public readonly BaseDTO $nested,
            ) {
                $this->validate();
            }

            protected function validate(): void
            {
                $this->validateRequired($this->id, 'ID');
            }
        };

        $result = $parentDto->toArray();

        $this->assertIsArray($result['nested']);
        $this->assertEquals('nested-id', $result['nested']['id']);
        $this->assertEquals('Nested Title', $result['nested']['title']);
    }

    /**
     * Test that toArray() handles arrays of DTOs.
     */
    public function test_to_array_handles_arrays_of_dtos(): void
    {
        $dto1 = new class('id-1', 'Title 1') extends BaseDTO {
            public function __construct(
                public readonly string $id,
                public readonly string $title,
            ) {
                $this->validate();
            }

            protected function validate(): void
            {
                $this->validateRequired($this->id, 'ID');
            }
        };

        $dto2 = new class('id-2', 'Title 2') extends BaseDTO {
            public function __construct(
                public readonly string $id,
                public readonly string $title,
            ) {
                $this->validate();
            }

            protected function validate(): void
            {
                $this->validateRequired($this->id, 'ID');
            }
        };

        $parentDto = new class('parent-id', [$dto1, $dto2]) extends BaseDTO {
            public function __construct(
                public readonly string $id,
                public readonly array $items,
            ) {
                $this->validate();
            }

            protected function validate(): void
            {
                $this->validateRequired($this->id, 'ID');
            }
        };

        $result = $parentDto->toArray();

        $this->assertIsArray($result['items']);
        $this->assertCount(2, $result['items']);
        $this->assertEquals('id-1', $result['items'][0]['id']);
        $this->assertEquals('id-2', $result['items'][1]['id']);
    }

    /**
     * Test that toArray() handles primitive arrays.
     */
    public function test_to_array_handles_primitive_arrays(): void
    {
        $dto = new class('test-id', ['tag1', 'tag2', 'tag3']) extends BaseDTO {
            public function __construct(
                public readonly string $id,
                public readonly array $tags,
            ) {
                $this->validate();
            }

            protected function validate(): void
            {
                $this->validateRequired($this->id, 'ID');
            }
        };

        $result = $dto->toArray();

        $this->assertIsArray($result['tags']);
        $this->assertEquals(['tag1', 'tag2', 'tag3'], $result['tags']);
    }

    /**
     * Test validateRequired() throws exception for empty string.
     */
    public function test_validate_required_throws_for_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Title is required');

        new class('', 'Description') extends BaseDTO {
            public function __construct(
                public readonly string $title,
                public readonly string $description,
            ) {
                $this->validate();
            }

            protected function validate(): void
            {
                $this->validateRequired($this->title, 'Title');
            }
        };
    }

    /**
     * Test validateRequired() throws exception for null value.
     */
    public function test_validate_required_throws_for_null(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Title is required');

        new class(null) extends BaseDTO {
            public function __construct(
                public readonly ?string $title,
            ) {
                $this->validate();
            }

            protected function validate(): void
            {
                $this->validateRequired($this->title, 'Title');
            }
        };
    }

    /**
     * Test validateEmail() accepts valid email.
     */
    public function test_validate_email_accepts_valid_email(): void
    {
        $dto = new class('test@example.com') extends BaseDTO {
            public function __construct(
                public readonly string $email,
            ) {
                $this->validate();
            }

            protected function validate(): void
            {
                $this->validateEmail($this->email);
            }
        };

        $this->assertEquals('test@example.com', $dto->email);
    }

    /**
     * Test validateEmail() throws exception for invalid email.
     */
    public function test_validate_email_throws_for_invalid_email(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Email must be a valid email address');

        new class('invalid-email') extends BaseDTO {
            public function __construct(
                public readonly string $email,
            ) {
                $this->validate();
            }

            protected function validate(): void
            {
                $this->validateEmail($this->email);
            }
        };
    }

    /**
     * Test validatePositive() accepts positive numbers.
     */
    public function test_validate_positive_accepts_positive_numbers(): void
    {
        $dto = new class(10) extends BaseDTO {
            public function __construct(
                public readonly int $quantity,
            ) {
                $this->validate();
            }

            protected function validate(): void
            {
                $this->validatePositive($this->quantity, 'Quantity');
            }
        };

        $this->assertEquals(10, $dto->quantity);
    }

    /**
     * Test validatePositive() throws exception for zero.
     */
    public function test_validate_positive_throws_for_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity must be positive');

        new class(0) extends BaseDTO {
            public function __construct(
                public readonly int $quantity,
            ) {
                $this->validate();
            }

            protected function validate(): void
            {
                $this->validatePositive($this->quantity, 'Quantity');
            }
        };
    }

    /**
     * Test validatePositive() throws exception for negative numbers.
     */
    public function test_validate_positive_throws_for_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity must be positive');

        new class(-5) extends BaseDTO {
            public function __construct(
                public readonly int $quantity,
            ) {
                $this->validate();
            }

            protected function validate(): void
            {
                $this->validatePositive($this->quantity, 'Quantity');
            }
        };
    }

    /**
     * Test validateNonNegative() accepts zero.
     */
    public function test_validate_non_negative_accepts_zero(): void
    {
        $dto = new class(0) extends BaseDTO {
            public function __construct(
                public readonly int $count,
            ) {
                $this->validate();
            }

            protected function validate(): void
            {
                $this->validateNonNegative($this->count, 'Count');
            }
        };

        $this->assertEquals(0, $dto->count);
    }

    /**
     * Test validateNonNegative() throws exception for negative numbers.
     */
    public function test_validate_non_negative_throws_for_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Count must be non-negative');

        new class(-1) extends BaseDTO {
            public function __construct(
                public readonly int $count,
            ) {
                $this->validate();
            }

            protected function validate(): void
            {
                $this->validateNonNegative($this->count, 'Count');
            }
        };
    }

    /**
     * Test validateNotEmpty() accepts non-empty arrays.
     */
    public function test_validate_not_empty_accepts_non_empty_arrays(): void
    {
        $dto = new class(['item1', 'item2']) extends BaseDTO {
            public function __construct(
                public readonly array $items,
            ) {
                $this->validate();
            }

            protected function validate(): void
            {
                $this->validateNotEmpty($this->items, 'Items');
            }
        };

        $this->assertCount(2, $dto->items);
    }

    /**
     * Test validateNotEmpty() throws exception for empty arrays.
     */
    public function test_validate_not_empty_throws_for_empty_array(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Items cannot be empty');

        new class([]) extends BaseDTO {
            public function __construct(
                public readonly array $items,
            ) {
                $this->validate();
            }

            protected function validate(): void
            {
                $this->validateNotEmpty($this->items, 'Items');
            }
        };
    }

    /**
     * Test validateInArray() accepts allowed values.
     */
    public function test_validate_in_array_accepts_allowed_values(): void
    {
        $dto = new class('active') extends BaseDTO {
            public function __construct(
                public readonly string $status,
            ) {
                $this->validate();
            }

            protected function validate(): void
            {
                $this->validateInArray($this->status, ['active', 'inactive', 'pending'], 'Status');
            }
        };

        $this->assertEquals('active', $dto->status);
    }

    /**
     * Test validateInArray() throws exception for disallowed values.
     */
    public function test_validate_in_array_throws_for_disallowed_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Status must be one of: active, inactive, pending');

        new class('invalid') extends BaseDTO {
            public function __construct(
                public readonly string $status,
            ) {
                $this->validate();
            }

            protected function validate(): void
            {
                $this->validateInArray($this->status, ['active', 'inactive', 'pending'], 'Status');
            }
        };
    }
}
