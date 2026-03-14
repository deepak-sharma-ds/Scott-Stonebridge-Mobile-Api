<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\NavigationServiceInterface;
use App\Http\Controllers\Base\BaseApiController;
use App\Http\Resources\Navigation\MenuResource;
use Illuminate\Http\JsonResponse;

/**
 * Navigation Controller (v1)
 * 
 * Handles navigation/menu-related API endpoints
 */
class NavigationController extends BaseApiController
{
    public function __construct(
        protected NavigationServiceInterface $navigationService
    ) {}

    /**
     * Get menu by handle
     * 
     * @param string $handle Menu handle (e.g., 'main-menu', 'footer')
     * @return JsonResponse
     */
    public function show(string $handle): JsonResponse
    {
        try {
            $menu = $this->navigationService->getMenu($handle);

            return $this->success(
                'Menu fetched successfully',
                [
                    'menu' => new MenuResource($menu),
                ]
            );
        } catch (\App\Exceptions\ShopifyNotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (\Exception $e) {
            return $this->error(
                'Failed to fetch menu',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }
}
