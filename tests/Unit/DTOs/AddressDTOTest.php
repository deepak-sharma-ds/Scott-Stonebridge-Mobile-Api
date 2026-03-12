<?php

namespace Tests\Unit\DTOs;

use App\DTOs\Customer\AddressDTO;
use PHPUnit\Framework\TestCase;

class AddressDTOTest extends TestCase
{
    public function test_can_create_address_dto_with_valid_data(): void
    {
        $dto = new AddressDTO(
            id: 'gid://shopify/MailingAddress/123',
            address1: '123 Main Street',
            address2: 'Apt 4B',
            city: 'London',
            province: 'Greater London',
            country: 'United Kingdom',
            zip: 'SW1A 1AA',
            phone: '+44 20 1234 5678',
            firstName: 'John',
            lastName: 'Doe',
            company: 'Acme Corp',
            isDefault: true,
        );

        $this->assertEquals('gid://shopify/MailingAddress/123', $dto->id);
        $this->assertEquals('123 Main Street', $dto->address1);
        $this->assertEquals('Apt 4B', $dto->address2);
        $this->assertEquals('London', $dto->city);
        $this->assertEquals('Greater London', $dto->province);
        $this->assertEquals('United Kingdom', $dto->country);
        $this->assertEquals('SW1A 1AA', $dto->zip);
        $this->assertEquals('+44 20 1234 5678', $dto->phone);
        $this->assertEquals('John', $dto->firstName);
        $this->assertEquals('Doe', $dto->lastName);
        $this->assertEquals('Acme Corp', $dto->company);
        $this->assertTrue($dto->isDefault);
    }

    public function test_can_create_address_with_all_null_fields(): void
    {
        $dto = new AddressDTO(
            id: null,
            address1: null,
            address2: null,
            city: null,
            province: null,
            country: null,
            zip: null,
            phone: null,
            firstName: null,
            lastName: null,
            company: null,
            isDefault: false,
        );

        $this->assertNull($dto->id);
        $this->assertNull($dto->address1);
        $this->assertNull($dto->address2);
        $this->assertNull($dto->city);
        $this->assertNull($dto->province);
        $this->assertNull($dto->country);
        $this->assertNull($dto->zip);
        $this->assertNull($dto->phone);
        $this->assertNull($dto->firstName);
        $this->assertNull($dto->lastName);
        $this->assertNull($dto->company);
        $this->assertFalse($dto->isDefault);
    }

    public function test_can_create_address_with_partial_data(): void
    {
        $dto = new AddressDTO(
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
            isDefault: false,
        );

        $this->assertEquals('gid://shopify/MailingAddress/123', $dto->id);
        $this->assertEquals('123 Main Street', $dto->address1);
        $this->assertNull($dto->address2);
        $this->assertEquals('London', $dto->city);
        $this->assertNull($dto->province);
        $this->assertEquals('United Kingdom', $dto->country);
        $this->assertEquals('SW1A 1AA', $dto->zip);
        $this->assertNull($dto->phone);
        $this->assertEquals('John', $dto->firstName);
        $this->assertEquals('Doe', $dto->lastName);
        $this->assertNull($dto->company);
        $this->assertFalse($dto->isDefault);
    }

    public function test_from_shopify_response_creates_dto_with_node_format(): void
    {
        $shopifyData = [
            'node' => [
                'id' => 'gid://shopify/MailingAddress/123',
                'address1' => '123 Main Street',
                'address2' => 'Apt 4B',
                'city' => 'London',
                'province' => 'Greater London',
                'country' => 'United Kingdom',
                'zip' => 'SW1A 1AA',
                'phone' => '+44 20 1234 5678',
                'firstName' => 'John',
                'lastName' => 'Doe',
                'company' => 'Acme Corp',
                'isDefault' => true,
            ],
        ];

        $dto = AddressDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('gid://shopify/MailingAddress/123', $dto->id);
        $this->assertEquals('123 Main Street', $dto->address1);
        $this->assertEquals('Apt 4B', $dto->address2);
        $this->assertEquals('London', $dto->city);
        $this->assertEquals('Greater London', $dto->province);
        $this->assertEquals('United Kingdom', $dto->country);
        $this->assertEquals('SW1A 1AA', $dto->zip);
        $this->assertEquals('+44 20 1234 5678', $dto->phone);
        $this->assertEquals('John', $dto->firstName);
        $this->assertEquals('Doe', $dto->lastName);
        $this->assertEquals('Acme Corp', $dto->company);
        $this->assertTrue($dto->isDefault);
    }

    public function test_from_shopify_response_creates_dto_without_node_format(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/MailingAddress/123',
            'address1' => '123 Main Street',
            'city' => 'London',
            'country' => 'United Kingdom',
            'zip' => 'SW1A 1AA',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'isDefault' => true,
        ];

        $dto = AddressDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('gid://shopify/MailingAddress/123', $dto->id);
        $this->assertEquals('123 Main Street', $dto->address1);
        $this->assertEquals('London', $dto->city);
        $this->assertEquals('United Kingdom', $dto->country);
        $this->assertEquals('SW1A 1AA', $dto->zip);
        $this->assertEquals('John', $dto->firstName);
        $this->assertEquals('Doe', $dto->lastName);
        $this->assertTrue($dto->isDefault);
    }

    public function test_from_shopify_response_handles_missing_optional_fields(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/MailingAddress/123',
        ];

        $dto = AddressDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('gid://shopify/MailingAddress/123', $dto->id);
        $this->assertNull($dto->address1);
        $this->assertNull($dto->address2);
        $this->assertNull($dto->city);
        $this->assertNull($dto->province);
        $this->assertNull($dto->country);
        $this->assertNull($dto->zip);
        $this->assertNull($dto->phone);
        $this->assertNull($dto->firstName);
        $this->assertNull($dto->lastName);
        $this->assertNull($dto->company);
        $this->assertFalse($dto->isDefault);
    }

    public function test_from_shopify_response_handles_empty_data(): void
    {
        $dto = AddressDTO::fromShopifyResponse([]);

        $this->assertNull($dto->id);
        $this->assertNull($dto->address1);
        $this->assertNull($dto->address2);
        $this->assertNull($dto->city);
        $this->assertNull($dto->province);
        $this->assertNull($dto->country);
        $this->assertNull($dto->zip);
        $this->assertNull($dto->phone);
        $this->assertNull($dto->firstName);
        $this->assertNull($dto->lastName);
        $this->assertNull($dto->company);
        $this->assertFalse($dto->isDefault);
    }

    public function test_to_array_converts_dto_to_array(): void
    {
        $dto = new AddressDTO(
            id: 'gid://shopify/MailingAddress/123',
            address1: '123 Main Street',
            address2: 'Apt 4B',
            city: 'London',
            province: 'Greater London',
            country: 'United Kingdom',
            zip: 'SW1A 1AA',
            phone: '+44 20 1234 5678',
            firstName: 'John',
            lastName: 'Doe',
            company: 'Acme Corp',
            isDefault: true,
        );

        $array = $dto->toArray();

        $this->assertEquals('gid://shopify/MailingAddress/123', $array['id']);
        $this->assertEquals('123 Main Street', $array['address1']);
        $this->assertEquals('Apt 4B', $array['address2']);
        $this->assertEquals('London', $array['city']);
        $this->assertEquals('Greater London', $array['province']);
        $this->assertEquals('United Kingdom', $array['country']);
        $this->assertEquals('SW1A 1AA', $array['zip']);
        $this->assertEquals('+44 20 1234 5678', $array['phone']);
        $this->assertEquals('John', $array['firstName']);
        $this->assertEquals('Doe', $array['lastName']);
        $this->assertEquals('Acme Corp', $array['company']);
        $this->assertTrue($array['isDefault']);
    }

    public function test_to_array_handles_null_fields(): void
    {
        $dto = new AddressDTO(
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
            isDefault: false,
        );

        $array = $dto->toArray();

        $this->assertEquals('gid://shopify/MailingAddress/123', $array['id']);
        $this->assertEquals('123 Main Street', $array['address1']);
        $this->assertNull($array['address2']);
        $this->assertEquals('London', $array['city']);
        $this->assertNull($array['province']);
        $this->assertEquals('United Kingdom', $array['country']);
        $this->assertEquals('SW1A 1AA', $array['zip']);
        $this->assertNull($array['phone']);
        $this->assertEquals('John', $array['firstName']);
        $this->assertEquals('Doe', $array['lastName']);
        $this->assertNull($array['company']);
        $this->assertFalse($array['isDefault']);
    }
}
