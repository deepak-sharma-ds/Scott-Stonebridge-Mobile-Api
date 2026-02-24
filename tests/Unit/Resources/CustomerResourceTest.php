<?php

namespace Tests\Unit\Resources;

use App\DTOs\Customer\AddressDTO;
use App\DTOs\Customer\CustomerDTO;
use App\Http\Resources\Customer\CustomerResource;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * CustomerResource Unit Tests
 * 
 * Tests transformation logic from CustomerDTO to API response format.
 * Validates field mapping, nested resource handling, calculated fields, and edge cases.
 */
class CustomerResourceTest extends TestCase
{
    private Request $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = Request::create('/test', 'GET');
    }

    /** @test */
    public function it_transforms_customer_dto_to_array(): void
    {
        // Arrange
        $address1 = new AddressDTO(
            id: 'gid://shopify/MailingAddress/1',
            address1: '123 Main St',
            address2: 'Apt 4B',
            city: 'London',
            province: 'England',
            country: 'United Kingdom',
            zip: 'SW1A 1AA',
            phone: '+44123456789',
            firstName: 'John',
            lastName: 'Doe',
            company: 'Acme Corp',
        );

        $address2 = new AddressDTO(
            id: 'gid://shopify/MailingAddress/2',
            address1: '456 Oak Ave',
            address2: null,
            city: 'Manchester',
            province: 'Greater Manchester',
            country: 'United Kingdom',
            zip: 'M1 1AA',
            phone: null,
            firstName: 'John',
            lastName: 'Doe',
            company: null,
        );

        $customerDTO = new CustomerDTO(
            id: 'gid://shopify/Customer/123',
            email: 'john.doe@example.com',
            firstName: 'John',
            lastName: 'Doe',
            phone: '+44123456789',
            addresses: [$address1, $address2],
            tags: ['vip', 'newsletter'],
            acceptsMarketing: true,
            createdAt: '2025-01-01T00:00:00Z',
        );

        // Act
        $resource = new CustomerResource($customerDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals('gid://shopify/Customer/123', $result['id']);
        $this->assertEquals('john.doe@example.com', $result['email']);
        $this->assertEquals('John', $result['first_name']);
        $this->assertEquals('Doe', $result['last_name']);
        $this->assertEquals('John Doe', $result['full_name']);
        $this->assertEquals('+44123456789', $result['phone']);
        $this->assertEquals(['vip', 'newsletter'], $result['tags']);
        $this->assertTrue($result['accepts_marketing']);
        $this->assertTrue($result['has_addresses']);
        $this->assertEquals('2025-01-01T00:00:00Z', $result['created_at']);
    }

    /** @test */
    public function it_transforms_nested_addresses_using_address_resource(): void
    {
        // Arrange
        $address = new AddressDTO(
            id: 'gid://shopify/MailingAddress/456',
            address1: '789 Elm St',
            address2: null,
            city: 'Manchester',
            province: 'Greater Manchester',
            country: 'United Kingdom',
            zip: 'M1 1AA',
            phone: null,
            firstName: 'Jane',
            lastName: 'Smith',
            company: null,
        );

        $customerDTO = new CustomerDTO(
            id: 'gid://shopify/Customer/456',
            email: 'jane.smith@example.com',
            firstName: 'Jane',
            lastName: 'Smith',
            phone: null,
            addresses: [$address],
            tags: [],
            acceptsMarketing: false,
            createdAt: '2025-01-15T12:00:00Z',
        );

        // Act
        $resource = new CustomerResource($customerDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertIsArray($result['addresses']);
        $this->assertCount(1, $result['addresses']);
        
        $addressResult = $result['addresses'][0];
        $this->assertEquals('gid://shopify/MailingAddress/456', $addressResult['id']);
        $this->assertEquals('789 Elm St', $addressResult['address1']);
        $this->assertNull($addressResult['address2']);
        $this->assertEquals('Manchester', $addressResult['city']);
        $this->assertEquals('Greater Manchester', $addressResult['province']);
        $this->assertEquals('United Kingdom', $addressResult['country']);
        $this->assertEquals('M1 1AA', $addressResult['zip']);
        $this->assertNull($addressResult['phone']);
        $this->assertEquals('Jane', $addressResult['first_name']);
        $this->assertEquals('Smith', $addressResult['last_name']);
        $this->assertNull($addressResult['company']);
    }

    /** @test */
    public function it_handles_customer_without_addresses(): void
    {
        // Arrange
        $customerDTO = new CustomerDTO(
            id: 'gid://shopify/Customer/789',
            email: 'no.address@example.com',
            firstName: 'No',
            lastName: 'Address',
            phone: null,
            addresses: [],
            tags: [],
            acceptsMarketing: false,
            createdAt: '2025-01-20T08:00:00Z',
        );

        // Act
        $resource = new CustomerResource($customerDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertIsArray($result['addresses']);
        $this->assertCount(0, $result['addresses']);
        $this->assertFalse($result['has_addresses']);
    }

    /** @test */
    public function it_handles_customer_without_names(): void
    {
        // Arrange
        $customerDTO = new CustomerDTO(
            id: 'gid://shopify/Customer/999',
            email: 'email.only@example.com',
            firstName: null,
            lastName: null,
            phone: null,
            addresses: [],
            tags: [],
            acceptsMarketing: true,
            createdAt: '2025-01-22T14:30:00Z',
        );

        // Act
        $resource = new CustomerResource($customerDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertNull($result['first_name']);
        $this->assertNull($result['last_name']);
        $this->assertEquals('email.only@example.com', $result['full_name']); // Falls back to email
    }

    /** @test */
    public function it_uses_snake_case_for_field_names(): void
    {
        // Arrange
        $customerDTO = new CustomerDTO(
            id: 'gid://shopify/Customer/111',
            email: 'test@example.com',
            firstName: 'Test',
            lastName: 'User',
            phone: '+441234567890',
            addresses: [],
            tags: ['test'],
            acceptsMarketing: false,
            createdAt: '2025-01-25T09:00:00Z',
        );

        // Act
        $resource = new CustomerResource($customerDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertArrayHasKey('first_name', $result);
        $this->assertArrayHasKey('last_name', $result);
        $this->assertArrayHasKey('full_name', $result);
        $this->assertArrayHasKey('accepts_marketing', $result);
        $this->assertArrayHasKey('has_addresses', $result);
        $this->assertArrayHasKey('created_at', $result);
    }

    /** @test */
    public function it_handles_multiple_tags(): void
    {
        // Arrange
        $customerDTO = new CustomerDTO(
            id: 'gid://shopify/Customer/222',
            email: 'tagged@example.com',
            firstName: 'Tagged',
            lastName: 'Customer',
            phone: null,
            addresses: [],
            tags: ['vip', 'newsletter', 'loyalty', 'premium'],
            acceptsMarketing: true,
            createdAt: '2025-01-26T11:00:00Z',
        );

        // Act
        $resource = new CustomerResource($customerDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertIsArray($result['tags']);
        $this->assertCount(4, $result['tags']);
        $this->assertEquals(['vip', 'newsletter', 'loyalty', 'premium'], $result['tags']);
    }

    /** @test */
    public function it_preserves_boolean_values(): void
    {
        // Arrange
        $customerDTO1 = new CustomerDTO(
            id: 'gid://shopify/Customer/333',
            email: 'marketing@example.com',
            firstName: 'Marketing',
            lastName: 'Opt-In',
            phone: null,
            addresses: [],
            tags: [],
            acceptsMarketing: true,
            createdAt: '2025-01-27T10:00:00Z',
        );

        $customerDTO2 = new CustomerDTO(
            id: 'gid://shopify/Customer/444',
            email: 'no.marketing@example.com',
            firstName: 'Marketing',
            lastName: 'Opt-Out',
            phone: null,
            addresses: [],
            tags: [],
            acceptsMarketing: false,
            createdAt: '2025-01-27T11:00:00Z',
        );

        // Act
        $resource1 = new CustomerResource($customerDTO1);
        $result1 = $resource1->toArray($this->request);

        $resource2 = new CustomerResource($customerDTO2);
        $result2 = $resource2->toArray($this->request);

        // Assert
        $this->assertTrue($result1['accepts_marketing']);
        $this->assertFalse($result2['accepts_marketing']);
    }
}
