<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProfileServiceInterface;
use App\Http\Controllers\Base\BaseApiController;
use App\Http\Requests\Profile\AddAddressRequest;
use App\Http\Requests\Profile\UpdateAddressRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\Profile\ProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Profile Controller (v1)
 * 
 * Handles customer profile and address management endpoints.
 * Provides CRUD operations for customer profile and addresses.
 * Extends BaseApiController for standardized responses.
 * 
 * Requirements: 9.2, 9.6, 9.7, 9.8, 9.9, 9.10
 */
class ProfileController extends BaseApiController
{
    public function __construct(
        protected ProfileServiceInterface $profileService
    ) {}

    /**
     * Get customer profile
     * 
     * Returns the authenticated customer's profile including
     * all associated addresses.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $accessToken = $request->input('access_token') ?? $request->bearerToken();

            if (empty($accessToken)) {
                return $this->unauthorized('Access token is required');
            }

            $profile = $this->profileService->getProfile($accessToken);

            return $this->success(
                'Profile retrieved successfully',
                new ProfileResource($profile)
            );
        } catch (\App\Exceptions\ShopifyAuthException $e) {
            Log::warning('Profile retrieval failed - authentication error', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
            ]);

            return $this->unauthorized($e->getMessage());
        } catch (\App\Exceptions\ShopifyNotFoundException $e) {
            Log::warning('Profile not found', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
            ]);

            return $this->notFound($e->getMessage());
        } catch (\Exception $e) {
            Log::error('Failed to fetch profile', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to fetch profile',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Update customer profile
     * 
     * Updates the authenticated customer's profile information
     * such as name, phone, and marketing preferences.
     * 
     * @param UpdateProfileRequest $request
     * @return JsonResponse
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        try {
            $accessToken = $request->input('access_token') ?? $request->bearerToken();

            if (empty($accessToken)) {
                return $this->unauthorized('Access token is required');
            }

            $profile = $this->profileService->updateProfile(
                $accessToken,
                $request->validated()
            );

            return $this->success(
                'Profile updated successfully',
                new ProfileResource($profile)
            );
        } catch (\App\Exceptions\ShopifyAuthException $e) {
            Log::warning('Profile update failed - authentication error', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
            ]);

            return $this->unauthorized($e->getMessage());
        } catch (\App\Exceptions\ShopifyApiException $e) {
            Log::error('Profile update failed - API error', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'data' => $request->validated(),
            ]);

            return $this->error(
                'Failed to update profile',
                ['error' => $e->getMessage()],
                [],
                422
            );
        } catch (\Exception $e) {
            Log::error('Failed to update profile', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to update profile',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Add new address
     * 
     * Creates a new address for the authenticated customer.
     * 
     * @param AddAddressRequest $request
     * @return JsonResponse
     */
    public function storeAddress(AddAddressRequest $request): JsonResponse
    {
        try {
            $accessToken = $request->input('access_token') ?? $request->bearerToken();

            if (empty($accessToken)) {
                return $this->unauthorized('Access token is required');
            }

            $profile = $this->profileService->addAddress(
                $accessToken,
                $request->validated()
            );

            return $this->success(
                'Address added successfully',
                new ProfileResource($profile),
                [],
                201
            );
        } catch (\App\Exceptions\ShopifyAuthException $e) {
            Log::warning('Address creation failed - authentication error', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
            ]);

            return $this->unauthorized($e->getMessage());
        } catch (\App\Exceptions\ShopifyApiException $e) {
            Log::error('Address creation failed - API error', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'data' => $request->validated(),
            ]);

            return $this->error(
                'Failed to add address',
                ['error' => $e->getMessage()],
                [],
                422
            );
        } catch (\Exception $e) {
            Log::error('Failed to add address', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to add address',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Update existing address
     * 
     * Updates an existing address for the authenticated customer.
     * Address ID is provided in the request body.
     * 
     * @param UpdateAddressRequest $request
     * @return JsonResponse
     */
    public function updateAddress(UpdateAddressRequest $request): JsonResponse
    {
        try {
            $accessToken = $request->input('access_token') ?? $request->bearerToken();

            if (empty($accessToken)) {
                return $this->unauthorized('Access token is required');
            }

            $addressId = $request->input('address_id');

            if (empty($addressId)) {
                return $this->error(
                    'Address ID is required',
                    ['error' => 'address_id field is required in request body'],
                    [],
                    422
                );
            }

            $profile = $this->profileService->updateAddress(
                $accessToken,
                $addressId,
                $request->validated()
            );

            return $this->success(
                'Address updated successfully',
                new ProfileResource($profile)
            );
        } catch (\App\Exceptions\ShopifyAuthException $e) {
            Log::warning('Address update failed - authentication error', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
            ]);

            return $this->unauthorized($e->getMessage());
        } catch (\App\Exceptions\ShopifyNotFoundException $e) {
            Log::warning('Address not found', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
            ]);

            return $this->notFound($e->getMessage());
        } catch (\App\Exceptions\ShopifyApiException $e) {
            Log::error('Address update failed - API error', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'data' => $request->validated(),
            ]);

            return $this->error(
                'Failed to update address',
                ['error' => $e->getMessage()],
                [],
                422
            );
        } catch (\Exception $e) {
            Log::error('Failed to update address', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to update address',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Delete address
     * 
     * Deletes an existing address for the authenticated customer.
     * Address ID is provided in the request body.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function destroyAddress(Request $request): JsonResponse
    {
        try {
            $accessToken = $request->input('access_token') ?? $request->bearerToken();

            if (empty($accessToken)) {
                return $this->unauthorized('Access token is required');
            }

            $addressId = $request->input('address_id');

            if (empty($addressId)) {
                return $this->error(
                    'Address ID is required',
                    ['error' => 'address_id field is required in request body'],
                    [],
                    422
                );
            }

            $this->profileService->deleteAddress($accessToken, $addressId);

            return $this->success(
                'Address deleted successfully'
            );
        } catch (\App\Exceptions\ShopifyAuthException $e) {
            Log::warning('Address deletion failed - authentication error', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
            ]);

            return $this->unauthorized($e->getMessage());
        } catch (\App\Exceptions\ShopifyNotFoundException $e) {
            Log::warning('Address not found', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
            ]);

            return $this->notFound($e->getMessage());
        } catch (\App\Exceptions\ShopifyApiException $e) {
            Log::error('Address deletion failed - API error', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
            ]);

            return $this->error(
                'Failed to delete address',
                ['error' => $e->getMessage()],
                [],
                422
            );
        } catch (\Exception $e) {
            Log::error('Failed to delete address', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to delete address',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }
}
