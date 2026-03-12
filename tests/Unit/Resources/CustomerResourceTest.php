<?php

namespace Tests\Unit\Resources;

use App\DTOs\Customer\AddressDTO;
use App\DTOs\Customer\CustomerDTO;
use App\Http\Resources\Customer\CustomerResource;
use Illuminate\Http\Request;
use Tests\TestCase;

class CustomerResourceTest extends TestCase
{
    private Request $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = Request::create('/test', 'GET');
    }

    private function makeAddress(string $id, bool $isDefault): AddressDTO
    {
        return new AddressDTO(
            id: $id,
            address1: '123 Main St',
            address2: null,
            city: 'London',
            province: 'England',
            country: 'United Kingdom',
            zip: 'SW1A 1AA',
            phone: '+44123456789',
            firstName: 'John',
            lastName: 'Doe',
            company: null,
            isDefault: $isDefault,
        );
    }

    private function transform(CustomerDTO $customerDTO): array
    {
        return (new CustomerResource($customerDTO))
            ->toResponse($this->request)
            ->getData(true)['data'];
    }

    public function test_it_transforms_customer_dto_to_array(): void
    {
        $customerDTO = new CustomerDTO(
            id: 'gid://shopify/Customer/123',
            email: 'john.doe@example.com',
            firstName: 'John',
            lastName: 'Doe',
            phone: '+44123456789',
            addresses: [
                $this->makeAddress('gid://shopify/MailingAddress/1', true),
                $this->makeAddress('gid://shopify/MailingAddress/2', false),
            ],
            defaultAddressId: 'gid://shopify/MailingAddress/1',
            tags: ['vip', 'newsletter'],
            acceptsMarketing: true,
            createdAt: '2025-01-01T00:00:00Z',
        );

        $result = $this->transform($customerDTO);

        $this->assertEquals('gid://shopify/MailingAddress/1', $result['default_address_id']);
        $this->assertTrue($result['addresses'][0]['is_default']);
        $this->assertFalse($result['addresses'][1]['is_default']);
        $this->assertTrue($result['has_addresses']);
    }

    public function test_it_handles_customer_without_addresses(): void
    {
        $customerDTO = new CustomerDTO(
            id: 'gid://shopify/Customer/789',
            email: 'no.address@example.com',
            firstName: 'No',
            lastName: 'Address',
            phone: null,
            addresses: [],
            defaultAddressId: null,
            tags: [],
            acceptsMarketing: false,
            createdAt: '2025-01-20T08:00:00Z',
        );

        $result = $this->transform($customerDTO);

        $this->assertSame([], $result['addresses']);
        $this->assertNull($result['default_address_id']);
        $this->assertFalse($result['has_addresses']);
    }

    public function test_it_uses_snake_case_for_field_names(): void
    {
        $customerDTO = new CustomerDTO(
            id: 'gid://shopify/Customer/111',
            email: 'test@example.com',
            firstName: 'Test',
            lastName: 'User',
            phone: '+441234567890',
            addresses: [],
            defaultAddressId: null,
            tags: ['test'],
            acceptsMarketing: false,
            createdAt: '2025-01-25T09:00:00Z',
        );

        $result = $this->transform($customerDTO);

        $this->assertArrayHasKey('default_address_id', $result);
        $this->assertArrayHasKey('accepts_marketing', $result);
        $this->assertArrayHasKey('has_addresses', $result);
    }
}
