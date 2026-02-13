<?php

namespace Tests\Unit\DTOs;

use App\DTOs\Customer\CustomerDTO;
use App\DTOs\Customer\AddressDTO;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CustomerDTOTest extends TestCase
{
    /**
     * Test that CustomerDTO can be instantiated with valid data.
     */
    public function test_can_create_customer_dto_with_valid_data(): void
    {
        $address = new AddressDTO(
            id: 'gid://shopify/MailingAddress/123',
            address1: '123 Main Street',
            address2: null,
            city: 'London',
            province: 'Greater London',
            country: 'United Kingdom',
            zip: 'SW1A 1AA',
            phone: '+44 20 1234 5678',
            firstName: 'John',
            lastName: 'Doe',
            company: null,
        );

        $dto = new CustomerDTO(
            id: 'gid://shopify/Customer/456',
            email: 'john.doe@example.com',
            firstName: 'John',
            lastName: 'Doe',
            phone: '+44 20 1234 5678',
            addresses: [$address],
            tags: ['VIP', 'Newsletter'],
            acceptsMarketing: true,
            createdAt: '2025-01-20T10:00:00Z',
        );

        $this->assertEquals('gid://shopify/Customer/456', $dto->id);
        $this->assertEquals('john.doe@example.com', $dto->email);
        $this->assertEquals('John', $dto->firstName);
        $this->assertEquals('Doe', $dto->lastName);
        $this->assertEquals('+44 20 1234 5678', $dto->phone);
        $this->assertCount(1, $dto->addresses);
        $this->assertInstanceOf(AddressDTO::class, $dto->addresses[0]);
        $this->assertEquals(['VIP', 'Newsletter'], $dto->tags);
        $this->assertTrue($dto->acceptsMarketing);
        $this->assertEquals('2025-01-20T10:00:00Z', $dto->createdAt);
    }

    /**
     * Test that CustomerDTO throws exception when ID is empty.
     */
    public function test_throws_exception_when_id_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Customer ID is required');

        new CustomerDTO(
            id: '',
            email: 'john.doe@example.com',
            firstName: 'John',
            lastName: 'Doe',
            phone: null,
            addresses: [],
            tags: [],
            acceptsMarketing: false,
            createdAt: '2025-01-20T10:00:00Z',
        );
    }

    /**
     * Test that CustomerDTO throws exception when email is empty.
     */
    public function test_throws_exception_when_email_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Customer email is required');

        new CustomerDTO(
            id: 'gid://shopify/Customer/456',
            email: '',
            firstName: 'John',
            lastName: 'Doe',
            phone: null,
            addresses: [],
            tags: [],
            acceptsMarketing: false,
            createdAt: '2025-01-20T10:00:00Z',
        );
    }

    /**
     * Test that CustomerDTO throws exception when email is invalid.
     */
    public function test_throws_exception_when_email_is_invalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Customer email must be a valid email address');

        new CustomerDTO(
            id: 'gid://shopify/Customer/456',
            email: 'not-an-email',
            firstName: 'John',
            lastName: 'Doe',
            phone: null,
            addresses: [],
            tags: [],
            acceptsMarketing: false,
            createdAt: '2025-01-20T10:00:00Z',
        );
    }

    /**
     * Test that CustomerDTO can be created with null optional fields.
     */
    public function test_can_create_customer_with_null_optional_fields(): void
    {
        $dto = new CustomerDTO(
            id: 'gid://shopify/Customer/456',
            email: 'john.doe@example.com',
            firstName: null,
            lastName: null,
            phone: null,
            addresses: [],
            tags: [],
            acceptsMarketing: false,
            createdAt: '2025-01-20T10:00:00Z',
        );

        $this->assertEquals('gid://shopify/Customer/456', $dto->id);
        $this->assertEquals('john.doe@example.com', $dto->email);
        $this->assertNull($dto->firstName);
        $this->assertNull($dto->lastName);
        $this->assertNull($dto->phone);
        $this->assertEmpty($dto->addresses);
        $this->assertEmpty($dto->tags);
        $this->assertFalse($dto->acceptsMarketing);
    }

    /**
     * Test that CustomerDTO can be created with empty addresses.
     */
    public function test_can_create_customer_with_empty_addresses(): void
    {
        $dto = new CustomerDTO(
            id: 'gid://shopify/Customer/456',
            email: 'john.doe@example.com',
            firstName: 'John',
            lastName: 'Doe',
            phone: null,
            addresses: [],
            tags: [],
            acceptsMarketing: false,
            createdAt: '2025-01-20T10:00:00Z',
        );

        $this->assertEmpty($dto->addresses);
        $this->assertFalse($dto->hasAddresses());
    }

    /**
     * Test getFullName() returns combined first and last name.
     */
    public function test_get_full_name_returns_combined_name(): void
    {
        $dto = new CustomerDTO(
            id: 'gid://shopify/Customer/456',
            email: 'john.doe@example.com',
            firstName: 'John',
            lastName: 'Doe',
            phone: null,
            addresses: [],
            tags: [],
            acceptsMarketing: false,
            createdAt: '2025-01-20T10:00:00Z',
        );

        $this->assertEquals('John Doe', $dto->getFullName());
    }

    /**
     * Test getFullName() returns first name only when last name is null.
     */
    public function test_get_full_name_returns_first_name_only(): void
    {
        $dto = new CustomerDTO(
            id: 'gid://shopify/Customer/456',
            email: 'john.doe@example.com',
            firstName: 'John',
            lastName: null,
            phone: null,
            addresses: [],
            tags: [],
            acceptsMarketing: false,
            createdAt: '2025-01-20T10:00:00Z',
        );

        $this->assertEquals('John', $dto->getFullName());
    }

    /**
     * Test getFullName() returns last name only when first name is null.
     */
    public function test_get_full_name_returns_last_name_only(): void
    {
        $dto = new CustomerDTO(
            id: 'gid://shopify/Customer/456',
            email: 'john.doe@example.com',
            firstName: null,
            lastName: 'Doe',
            phone: null,
            addresses: [],
            tags: [],
            acceptsMarketing: false,
            createdAt: '2025-01-20T10:00:00Z',
        );

        $this->assertEquals('Doe', $dto->getFullName());
    }

    /**
     * Test getFullName() returns email when both names are null.
     */
    public function test_get_full_name_returns_email_when_names_are_null(): void
    {
        $dto = new CustomerDTO(
            id: 'gid://shopify/Customer/456',
            email: 'john.doe@example.com',
            firstName: null,
            lastName: null,
            phone: null,
            addresses: [],
            tags: [],
            acceptsMarketing: false,
            createdAt: '2025-01-20T10:00:00Z',
        );

        $this->assertEquals('john.doe@example.com', $dto->getFullName());
    }

    /**
     * Test hasAddresses() returns true when addresses exist.
     */
    public function test_has_addresses_returns_true_when_addresses_exist(): void
    {
        $address = new AddressDTO(
            id: 'gid://shopify/MailingAddress/123',
            address1: '123 Main Street',
            address2: null,
            city: 'London',
            province: null,
            country: 'United Kingdom',
            zip: 'SW1A 1AA',
            phone: null,
            firstName: 'John',
            lastName: 'Doe',
            company: null,
        );

        $dto = new CustomerDTO(
            id: 'gid://shopify/Customer/456',
            email: 'john.doe@example.com',
            firstName: 'John',
            lastName: 'Doe',
            phone: null,
            addresses: [$address],
            tags: [],
            acceptsMarketing: false,
            createdAt: '2025-01-20T10:00:00Z',
        );

        $this->assertTrue($dto->hasAddresses());
    }

    /**
     * Test hasAddresses() returns false when addresses are empty.
     */
    public function test_has_addresses_returns_false_when_addresses_are_empty(): void
    {
        $dto = new CustomerDTO(
            id: 'gid://shopify/Customer/456',
            email: 'john.doe@example.com',
            firstName: 'John',
            lastName: 'Doe',
            phone: null,
            addresses: [],
            tags: [],
            acceptsMarketing: false,
            createdAt: '2025-01-20T10:00:00Z',
        );

        $this->assertFalse($dto->hasAddresses());
    }

    /**
     * Test fromShopifyResponse() creates DTO from Shopify data with edges format.
     */
    public function test_from_shopify_response_creates_dto_with_edges_format(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/Customer/456',
            'email' => 'john.doe@example.com',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'phone' => '+44 20 1234 5678',
            'addresses' => [
                'edges' => [
                    [
                        'node' => [
                            'id' => 'gid://shopify/MailingAddress/123',
                            'address1' => '123 Main Street',
                            'city' => 'London',
                            'country' => 'United Kingdom',
                            'zip' => 'SW1A 1AA',
                        ],
                    ],
                ],
            ],
            'tags' => ['VIP', 'Newsletter'],
            'acceptsMarketing' => true,
            'createdAt' => '2025-01-20T10:00:00Z',
        ];

        $dto = CustomerDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('gid://shopify/Customer/456', $dto->id);
        $this->assertEquals('john.doe@example.com', $dto->email);
        $this->assertEquals('John', $dto->firstName);
        $this->assertEquals('Doe', $dto->lastName);
        $this->assertEquals('+44 20 1234 5678', $dto->phone);
        $this->assertCount(1, $dto->addresses);
        $this->assertInstanceOf(AddressDTO::class, $dto->addresses[0]);
        $this->assertEquals('gid://shopify/MailingAddress/123', $dto->addresses[0]->id);
        $this->assertEquals(['VIP', 'Newsletter'], $dto->tags);
        $this->assertTrue($dto->acceptsMarketing);
        $this->assertEquals('2025-01-20T10:00:00Z', $dto->createdAt);
    }

    /**
     * Test fromShopifyResponse() creates DTO from Shopify data without edges format.
     */
    public function test_from_shopify_response_creates_dto_without_edges_format(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/Customer/456',
            'email' => 'john.doe@example.com',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'addresses' => [
                [
                    'id' => 'gid://shopify/MailingAddress/123',
                    'address1' => '123 Main Street',
                    'city' => 'London',
                    'country' => 'United Kingdom',
                    'zip' => 'SW1A 1AA',
                ],
            ],
            'createdAt' => '2025-01-20T10:00:00Z',
        ];

        $dto = CustomerDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('gid://shopify/Customer/456', $dto->id);
        $this->assertEquals('john.doe@example.com', $dto->email);
        $this->assertCount(1, $dto->addresses);
        $this->assertInstanceOf(AddressDTO::class, $dto->addresses[0]);
    }

    /**
     * Test fromShopifyResponse() handles missing optional fields.
     */
    public function test_from_shopify_response_handles_missing_optional_fields(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/Customer/456',
            'email' => 'john.doe@example.com',
            'createdAt' => '2025-01-20T10:00:00Z',
        ];

        $dto = CustomerDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('gid://shopify/Customer/456', $dto->id);
        $this->assertEquals('john.doe@example.com', $dto->email);
        $this->assertNull($dto->firstName);
        $this->assertNull($dto->lastName);
        $this->assertNull($dto->phone);
        $this->assertEmpty($dto->addresses);
        $this->assertEmpty($dto->tags);
        $this->assertFalse($dto->acceptsMarketing);
    }

    /**
     * Test fromShopifyResponse() handles empty addresses array.
     */
    public function test_from_shopify_response_handles_empty_addresses(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/Customer/456',
            'email' => 'john.doe@example.com',
            'addresses' => [],
            'createdAt' => '2025-01-20T10:00:00Z',
        ];

        $dto = CustomerDTO::fromShopifyResponse($shopifyData);

        $this->assertEmpty($dto->addresses);
        $this->assertFalse($dto->hasAddresses());
    }

    /**
     * Test toArray() converts DTO to array including nested addresses.
     */
    public function test_to_array_converts_dto_to_array_with_nested_addresses(): void
    {
        $address = new AddressDTO(
            id: 'gid://shopify/MailingAddress/123',
            address1: '123 Main Street',
            address2: null,
            city: 'London',
            province: null,
            country: 'United Kingdom',
            zip: 'SW1A 1AA',
            phone: null,
            firstName: 'John',
            lastName: 'Doe',
            company: null,
        );

        $dto = new CustomerDTO(
            id: 'gid://shopify/Customer/456',
            email: 'john.doe@example.com',
            firstName: 'John',
            lastName: 'Doe',
            phone: '+44 20 1234 5678',
            addresses: [$address],
            tags: ['VIP', 'Newsletter'],
            acceptsMarketing: true,
            createdAt: '2025-01-20T10:00:00Z',
        );

        $array = $dto->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('gid://shopify/Customer/456', $array['id']);
        $this->assertEquals('john.doe@example.com', $array['email']);
        $this->assertEquals('John', $array['firstName']);
        $this->assertEquals('Doe', $array['lastName']);
        $this->assertEquals('+44 20 1234 5678', $array['phone']);
        $this->assertIsArray($array['addresses']);
        $this->assertCount(1, $array['addresses']);
        $this->assertIsArray($array['addresses'][0]);
        $this->assertEquals('gid://shopify/MailingAddress/123', $array['addresses'][0]['id']);
        $this->assertEquals('123 Main Street', $array['addresses'][0]['address1']);
        $this->assertEquals(['VIP', 'Newsletter'], $array['tags']);
        $this->assertTrue($array['acceptsMarketing']);
        $this->assertEquals('2025-01-20T10:00:00Z', $array['createdAt']);
    }

    /**
     * Test toArray() handles null optional fields.
     */
    public function test_to_array_handles_null_optional_fields(): void
    {
        $dto = new CustomerDTO(
            id: 'gid://shopify/Customer/456',
            email: 'john.doe@example.com',
            firstName: null,
            lastName: null,
            phone: null,
            addresses: [],
            tags: [],
            acceptsMarketing: false,
            createdAt: '2025-01-20T10:00:00Z',
        );

        $array = $dto->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('gid://shopify/Customer/456', $array['id']);
        $this->assertEquals('john.doe@example.com', $array['email']);
        $this->assertNull($array['firstName']);
        $this->assertNull($array['lastName']);
        $this->assertNull($array['phone']);
        $this->assertIsArray($array['addresses']);
        $this->assertEmpty($array['addresses']);
        $this->assertIsArray($array['tags']);
        $this->assertEmpty($array['tags']);
        $this->assertFalse($array['acceptsMarketing']);
    }

    /**
     * Test toArray() handles empty addresses.
     */
    public function test_to_array_handles_empty_addresses(): void
    {
        $dto = new CustomerDTO(
            id: 'gid://shopify/Customer/456',
            email: 'john.doe@example.com',
            firstName: 'John',
            lastName: 'Doe',
            phone: null,
            addresses: [],
            tags: [],
            acceptsMarketing: false,
            createdAt: '2025-01-20T10:00:00Z',
        );

        $array = $dto->toArray();

        $this->assertIsArray($array['addresses']);
        $this->assertEmpty($array['addresses']);
    }
}
