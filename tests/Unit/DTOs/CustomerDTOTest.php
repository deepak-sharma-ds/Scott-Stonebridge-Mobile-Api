<?php

namespace Tests\Unit\DTOs;

use App\DTOs\Customer\AddressDTO;
use App\DTOs\Customer\CustomerDTO;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CustomerDTOTest extends TestCase
{
    private function makeAddress(bool $isDefault = false): AddressDTO
    {
        return new AddressDTO(
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
            isDefault: $isDefault,
        );
    }

    public function test_can_create_customer_dto_with_valid_data(): void
    {
        $dto = new CustomerDTO(
            id: 'gid://shopify/Customer/456',
            email: 'john.doe@example.com',
            firstName: 'John',
            lastName: 'Doe',
            phone: '+44 20 1234 5678',
            addresses: [$this->makeAddress(true)],
            defaultAddressId: 'gid://shopify/MailingAddress/123',
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
        $this->assertEquals('gid://shopify/MailingAddress/123', $dto->defaultAddressId);
        $this->assertEquals(['VIP', 'Newsletter'], $dto->tags);
        $this->assertTrue($dto->acceptsMarketing);
        $this->assertEquals('2025-01-20T10:00:00Z', $dto->createdAt);
    }

    public function test_throws_exception_when_id_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Customer ID is required');

        new CustomerDTO('', 'john.doe@example.com', 'John', 'Doe', null, [], null, [], false, '2025-01-20T10:00:00Z');
    }

    public function test_throws_exception_when_email_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Customer email is required');

        new CustomerDTO('gid://shopify/Customer/456', '', 'John', 'Doe', null, [], null, [], false, '2025-01-20T10:00:00Z');
    }

    public function test_throws_exception_when_email_is_invalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Customer email must be a valid email address');

        new CustomerDTO('gid://shopify/Customer/456', 'not-an-email', 'John', 'Doe', null, [], null, [], false, '2025-01-20T10:00:00Z');
    }

    public function test_can_create_customer_with_null_optional_fields(): void
    {
        $dto = new CustomerDTO(
            id: 'gid://shopify/Customer/456',
            email: 'john.doe@example.com',
            firstName: null,
            lastName: null,
            phone: null,
            addresses: [],
            defaultAddressId: null,
            tags: [],
            acceptsMarketing: false,
            createdAt: '2025-01-20T10:00:00Z',
        );

        $this->assertNull($dto->firstName);
        $this->assertNull($dto->lastName);
        $this->assertNull($dto->phone);
        $this->assertEmpty($dto->addresses);
        $this->assertNull($dto->defaultAddressId);
        $this->assertEmpty($dto->tags);
        $this->assertFalse($dto->acceptsMarketing);
    }

    public function test_has_addresses_behaviour(): void
    {
        $withAddress = new CustomerDTO(
            id: 'gid://shopify/Customer/456',
            email: 'john.doe@example.com',
            firstName: 'John',
            lastName: 'Doe',
            phone: null,
            addresses: [$this->makeAddress()],
            defaultAddressId: null,
            tags: [],
            acceptsMarketing: false,
            createdAt: '2025-01-20T10:00:00Z',
        );

        $withoutAddress = new CustomerDTO(
            id: 'gid://shopify/Customer/456',
            email: 'john.doe@example.com',
            firstName: 'John',
            lastName: 'Doe',
            phone: null,
            addresses: [],
            defaultAddressId: null,
            tags: [],
            acceptsMarketing: false,
            createdAt: '2025-01-20T10:00:00Z',
        );

        $this->assertTrue($withAddress->hasAddresses());
        $this->assertFalse($withoutAddress->hasAddresses());
    }

    public function test_get_full_name_behaviour(): void
    {
        $this->assertEquals(
            'John Doe',
            (new CustomerDTO('gid://shopify/Customer/456', 'john.doe@example.com', 'John', 'Doe', null, [], null, [], false, '2025-01-20T10:00:00Z'))->getFullName()
        );

        $this->assertEquals(
            'John',
            (new CustomerDTO('gid://shopify/Customer/456', 'john.doe@example.com', 'John', null, null, [], null, [], false, '2025-01-20T10:00:00Z'))->getFullName()
        );

        $this->assertEquals(
            'Doe',
            (new CustomerDTO('gid://shopify/Customer/456', 'john.doe@example.com', null, 'Doe', null, [], null, [], false, '2025-01-20T10:00:00Z'))->getFullName()
        );

        $this->assertEquals(
            'john.doe@example.com',
            (new CustomerDTO('gid://shopify/Customer/456', 'john.doe@example.com', null, null, null, [], null, [], false, '2025-01-20T10:00:00Z'))->getFullName()
        );
    }

    public function test_from_shopify_response_creates_dto_with_edges_format(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/Customer/456',
            'email' => 'john.doe@example.com',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'phone' => '+44 20 1234 5678',
            'defaultAddress' => ['id' => 'gid://shopify/MailingAddress/123'],
            'addresses' => [
                'edges' => [[
                    'node' => [
                        'id' => 'gid://shopify/MailingAddress/123',
                        'address1' => '123 Main Street',
                        'city' => 'London',
                        'country' => 'United Kingdom',
                        'zip' => 'SW1A 1AA',
                    ],
                ]],
            ],
            'tags' => ['VIP', 'Newsletter'],
            'acceptsMarketing' => true,
            'createdAt' => '2025-01-20T10:00:00Z',
        ];

        $dto = CustomerDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('gid://shopify/MailingAddress/123', $dto->defaultAddressId);
        $this->assertTrue($dto->addresses[0]->isDefault);
    }

    public function test_from_shopify_response_creates_dto_without_edges_format(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/Customer/456',
            'email' => 'john.doe@example.com',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'defaultAddress' => ['id' => 'gid://shopify/MailingAddress/123'],
            'addresses' => [[
                'id' => 'gid://shopify/MailingAddress/123',
                'address1' => '123 Main Street',
                'city' => 'London',
                'country' => 'United Kingdom',
                'zip' => 'SW1A 1AA',
            ]],
            'createdAt' => '2025-01-20T10:00:00Z',
        ];

        $dto = CustomerDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('gid://shopify/MailingAddress/123', $dto->defaultAddressId);
        $this->assertTrue($dto->addresses[0]->isDefault);
    }

    public function test_from_shopify_response_handles_missing_optional_fields(): void
    {
        $dto = CustomerDTO::fromShopifyResponse([
            'id' => 'gid://shopify/Customer/456',
            'email' => 'john.doe@example.com',
            'createdAt' => '2025-01-20T10:00:00Z',
        ]);

        $this->assertNull($dto->defaultAddressId);
        $this->assertEmpty($dto->addresses);
        $this->assertFalse($dto->acceptsMarketing);
    }

    public function test_to_array_converts_dto_to_array_with_nested_addresses(): void
    {
        $dto = new CustomerDTO(
            id: 'gid://shopify/Customer/456',
            email: 'john.doe@example.com',
            firstName: 'John',
            lastName: 'Doe',
            phone: '+44 20 1234 5678',
            addresses: [$this->makeAddress(true)],
            defaultAddressId: 'gid://shopify/MailingAddress/123',
            tags: ['VIP', 'Newsletter'],
            acceptsMarketing: true,
            createdAt: '2025-01-20T10:00:00Z',
        );

        $array = $dto->toArray();

        $this->assertEquals('gid://shopify/MailingAddress/123', $array['defaultAddressId']);
        $this->assertTrue($array['addresses'][0]['isDefault']);
    }

    public function test_to_array_handles_null_optional_fields(): void
    {
        $dto = new CustomerDTO(
            id: 'gid://shopify/Customer/456',
            email: 'john.doe@example.com',
            firstName: null,
            lastName: null,
            phone: null,
            addresses: [],
            defaultAddressId: null,
            tags: [],
            acceptsMarketing: false,
            createdAt: '2025-01-20T10:00:00Z',
        );

        $array = $dto->toArray();

        $this->assertNull($array['defaultAddressId']);
        $this->assertEmpty($array['addresses']);
    }
}
