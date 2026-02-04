<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PackageServiceInterface;
use App\DTOs\PackageDTO;
use App\Models\Package;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PackageService implements PackageServiceInterface
{
    /**
     * Get paginated packages
     */
    public function getPaginatedPackages(int $perPage = 10): LengthAwarePaginator
    {
        return Package::withCount('audios')
            ->with(['audios' => function ($query) {
                $query->select('id', 'package_id', 'title', 'order_index')
                    ->orderBy('order_index')
                    ->limit(3); 
            }])
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Get active packages
     */
    public function getActivePackages(): Collection
    {
        return Package::where('status', 'active')
            ->withCount('audios')
            ->with(['audios' => fn($q) => $q->orderBy('order_index')])
            ->latest()
            ->get();
    }

    /**
     * Find package by ID
     */
    public function findPackageById(int $id): ?PackageDTO
    {
        $package = Package::with(['audios' => fn($q) => $q->orderBy('order_index')])
            ->withCount('audios')
            ->find($id);

        if (!$package) {
            return null;
        }

        return $this->toDTO($package);
    }

    /**
     * Find package by Shopify tag
     */
    public function findPackageByShopifyTag(string $tag): ?PackageDTO
    {
        $package = Package::with(['audios' => fn($q) => $q->orderBy('order_index')])
            ->withCount('audios')
            ->where('shopify_tag', $tag)
            ->first();

        if (!$package) {
            return null;
        }

        return $this->toDTO($package);
    }

    /**
     * Create new package
     */
    public function createPackage(PackageDTO $dto): Package
    {
        return DB::transaction(function () use ($dto) {
            $data = $dto->toArray();
            
            // Handle image if it's a file path/url passed in DTO or handled separately
            // For now assuming DTO contains string path if already uploaded, or we handle upload in controller
            // The previous service handled UploadedFile, but DTOs shouldn't carry UploadedFile instances typically.
            // We'll assume the controller handles upload and passes the path in the DTO.

            return Package::create($data);
        });
    }

    /**
     * Update existing package
     */
    public function updatePackage(int $id, PackageDTO $dto): Package
    {
        $package = Package::findOrFail($id);

        return DB::transaction(function () use ($package, $dto) {
            $data = $dto->toArray();
            
            // If DTO has new cover image, handle old deletion logic if needed
            // But here we just update data
            
            $package->update($data);
            return $package->fresh();
        });
    }

    /**
     * Delete package
     */
    public function deletePackage(int $id): bool
    {
        $package = Package::findOrFail($id);

        return DB::transaction(function () use ($package) {
            if ($package->cover_image) {
                Storage::disk('public')->delete($package->cover_image);
            }
            return $package->delete();
        });
    }

    private function toDTO(Package $package): PackageDTO
    {
        return new PackageDTO(
            id: $package->id,
            title: $package->title,
            description: $package->description,
            price: (float) $package->price,
            currency: $package->currency ?? 'USD',
            shopifyTag: $package->shopify_tag,
            coverImage: $package->cover_image,
            status: $package->status
        );
    }
}
