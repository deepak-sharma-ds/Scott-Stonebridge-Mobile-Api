<?php

namespace App\Http\Controllers;

use App\Contracts\PackageServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PackageResource;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class ShopifyPackageController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PackageServiceInterface $packageService
    ) {}

    /**
     * GET /api/shopify/packages
     * Return all packages (with audios)
     */
    public function index(Request $request)
    {
        // Using getActivePackages as per service capability for API
        $packages = $this->packageService->getActivePackages();

        return $this->success(
            'Data found successfully',
            PackageResource::collection($packages)
        );
    }

    /**
     * GET /api/shopify/packages/{id}
     * Return single package details
     */
    public function show($id)
    {
        // Existing logic used shopify_tag as the identifier for "id" param
        $package = $this->packageService->findPackageByShopifyTag($id);

        if (!$package) {
            return $this->error('Package not found', null, 404);
        }

        return $this->success(
            'Data found successfully',
            new PackageResource($package)
        );
    }
}
