<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;
use Tests\Mocks\MockShopifyClient;
use Tests\Helpers\ShopifyResponseFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Integration tests for Auth API endpoints
 * 
 * Tests Requirements: 2.1, 2.2, 2.3, 2.5, 2.6
 */
class AuthEndpointsTest extends TestCase
{
    private MockShopifyClient $mockClient;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->mockClient = new MockShopifyClient();
        $this->app->instance('App\Contracts\Shopify\StorefrontApiClientInterface', $this->mockClient);
    }

    public function test_login_returns_access_token(): void
    {
        // Arrange
        $this->mockClient->mockResponse(
            'storefront/auth/customer_login.graphql',
            [
                'data' => [
                    'customerAccessTokenCreate' => [
                        'customerAccessToken' => [
                            'accessToken' => 'test-access-token-123',
                            'expiresAt' => '2025-12-31T23:59:59Z',
                        ],
                        'customerUserErrors' => []
                    ]
                ]
            ]
        );

        // Act
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        // Assert - Requirement 2.1: Maintain identical API response structures
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'access_token',
                'expires_at',
            ],
            'meta'
        ]);

        $response->assertJson([
            'success' => true,
            'data' => [
                'access_token' => 'test-access-token-123',
            ]
        ]);
    }

    public function test_login_with_invalid_credentials_returns_401(): void
    {
        // Arrange
        $this->mockClient->mockResponse(
            'storefront/auth/customer_login.graphql',
            [
                'data' => [
                    'customerAccessTokenCreate' => [
                        'customerAccessToken' => null,
                        'customerUserErrors' => [
                            [
                                'code' => 'UNIDENTIFIED_CUSTOMER',
                                'message' => 'Invalid email or password',
                            ]
                        ]
                    ]
                ]
            ]
        );

        // Act
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        // Assert
        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
        ]);
    }

    public function test_register_creates_new_customer(): void
    {
        // Arrange
        $this->mockClient->mockResponse(
            'storefront/auth/customer_register.graphql',
            [
                'data' => [
                    'customerCreate' => [
                        'customer' => ShopifyResponseFactory::customer([
                            'email' => 'newuser@example.com',
                            'firstName' => 'John',
                            'lastName' => 'Doe',
                        ]),
                        'customerUserErrors' => []
                    ]
                ]
            ]
        );

        // Act
        $response = $this->postJson('/api/v1/auth/register', [
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        // Assert - Requirement 2.2: Maintain identical API endpoint paths
        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'customer' => [
                    'id',
                    'email',
                    'first_name',
                    'last_name',
                ]
            ],
            'meta'
        ]);

        $response->assertJson([
            'success' => true,
            'data' => [
                'customer' => [
                    'email' => 'newuser@example.com',
                ]
            ]
        ]);
    }

    public function test_register_with_existing_email_returns_422(): void
    {
        // Arrange
        $this->mockClient->mockResponse(
            'storefront/auth/customer_register.graphql',
            [
                'data' => [
                    'customerCreate' => [
                        'customer' => null,
                        'customerUserErrors' => [
                            [
                                'code' => 'TAKEN',
                                'message' => 'Email has already been taken',
                                'field' => ['email']
                            ]
                        ]
                    ]
                ]
            ]
        );

        // Act
        $response = $this->postJson('/api/v1/auth/register', [
            'email' => 'existing@example.com',
            'password' => 'password123',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        // Assert
        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
        ]);
    }

    public function test_get_current_customer_returns_customer_data(): void
    {
        // Arrange
        $accessToken = 'test-access-token';
        $customer = ShopifyResponseFactory::customer([
            'email' => 'test@example.com',
            'firstName' => 'Jane',
            'lastName' => 'Smith',
        ]);

        $this->mockClient->mockResponse(
            'storefront/customer/get_customer.graphql',
            ShopifyResponseFactory::successResponse('customer', $customer)
        );

        // Act
        $response = $this->getJson('/api/v1/auth/me', [
            'Authorization' => 'Bearer ' . $accessToken
        ]);

        // Assert
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'customer' => [
                    'id',
                    'email',
                    'first_name',
                    'last_name',
                ]
            ],
            'meta'
        ]);

        $response->assertJson([
            'success' => true,
            'data' => [
                'customer' => [
                    'email' => 'test@example.com',
                ]
            ]
        ]);
    }

    public function test_get_current_customer_requires_authentication(): void
    {
        // Act - No authentication header
        $response = $this->getJson('/api/v1/auth/me');

        // Assert - Requirement 2.3: Maintain identical request validation rules
        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
        ]);
    }

    public function test_login_validates_request_parameters(): void
    {
        // Act - Missing required fields
        $response = $this->postJson('/api/v1/auth/login', []);

        // Assert - Requirement 2.3: Maintain identical request validation rules
        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
        ]);
    }

    public function test_register_validates_email_format(): void
    {
        // Act - Invalid email format
        $response = $this->postJson('/api/v1/auth/register', [
            'email' => 'invalid-email',
            'password' => 'password123',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        // Assert
        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
        ]);
    }

    public function test_auth_response_includes_correlation_id(): void
    {
        // Arrange
        $this->mockClient->mockResponse(
            'storefront/auth/customer_login.graphql',
            [
                'data' => [
                    'customerAccessTokenCreate' => [
                        'customerAccessToken' => [
                            'accessToken' => 'test-token',
                            'expiresAt' => '2025-12-31T23:59:59Z',
                        ],
                        'customerUserErrors' => []
                    ]
                ]
            ]
        );

        // Act
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        // Assert - Requirement 2.6: Maintain identical behavior
        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('meta.correlation_id'));
        $this->assertNotEmpty($response->json('meta.timestamp'));
        $this->assertEquals('v1', $response->json('meta.version'));
    }
}
