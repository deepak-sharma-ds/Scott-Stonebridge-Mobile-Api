<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\PackageDTO;
use App\Models\Package;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface PackageServiceInterface
{
    /**
     * Get paginated packages
     */
    public function getPaginatedPackages(int $perPage = 10): LengthAwarePaginator;
    
    /**
     * Get active packages
     */
    public function getActivePackages(): Collection;
    
    /**
     * Find package by ID
     */
    public function findPackageById(int $id): ?PackageDTO;

    /**
     * Find package by Shopify tag
     */
    public function findPackageByShopifyTag(string $tag): ?PackageDTO;
    
    /**
     * Create new package
     */
    public function createPackage(PackageDTO $dto): Package;
    
    /**
     * Update existing package
     */
    public function updatePackage(int $id, PackageDTO $dto): Package;
    
    /**
     * Delete package
     */
    public function deletePackage(int $id): bool;
}
