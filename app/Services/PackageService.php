<?php

namespace App\Services;

use App\Models\Package;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PackageService
{
    /**
     * Get paginated packages with audio counts (optimized - no N+1)
     */
    public function getPaginatedPackages(int $perPage = 10): LengthAwarePaginator
    {
        return Package::withCount('audios')
            ->with(['audios' => function ($query) {
                $query->select('id', 'package_id', 'title', 'order_index')
                    ->orderBy('order_index')
                    ->limit(3); // Only load first 3 for preview
            }])
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Get active packages (for frontend/API)
     */
    public function getActivePackages()
    {
        return Package::where('status', 'active')
            ->withCount('audios')
            ->with(['audios' => fn($q) => $q->orderBy('order_index')])
            ->latest()
            ->get();
    }

    /**
     * Find package by ID with all relations
     */
    public function findPackageWithRelations(int $id): ?Package
    {
        return Package::with(['audios' => fn($q) => $q->orderBy('order_index')])
            ->withCount('audios')
            ->find($id);
    }

    /**
     * Create a new package
     */
    public function createPackage(array $data, ?UploadedFile $coverImage = null): Package
    {
        return DB::transaction(function () use ($data, $coverImage) {
            if ($coverImage) {
                $data['cover_image'] = $this->uploadCoverImage($coverImage);
            }

            return Package::create($data);
        });
    }

    /**
     * Update existing package
     */
    public function updatePackage(Package $package, array $data, ?UploadedFile $coverImage = null): Package
    {
        return DB::transaction(function () use ($package, $data, $coverImage) {
            if ($coverImage) {
                // Delete old image if exists
                $this->deleteOldCoverImage($package);
                $data['cover_image'] = $this->uploadCoverImage($coverImage);
            }

            $package->update($data);
            return $package->fresh();
        });
    }

    /**
     * Delete package (soft delete)
     */
    public function deletePackage(Package $package): bool
    {
        return DB::transaction(function () use ($package) {
            // Soft delete will trigger model event to delete audios
            return $package->delete();
        });
    }

    /**
     * Force delete package and cleanup
     */
    public function forceDeletePackage(Package $package): bool
    {
        return DB::transaction(function () use ($package) {
            // Delete cover image
            $this->deleteOldCoverImage($package);

            // Force delete (will cascade to audios via model events)
            return $package->forceDelete();
        });
    }

    /**
     * Search packages by query
     */
    public function searchPackages(string $query)
    {
        return Package::where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                  ->orWhere('description', 'like', "%{$query}%")
                  ->orWhere('shopify_tag', 'like', "%{$query}%");
            })
            ->withCount('audios')
            ->latest()
            ->get();
    }

    /**
     * Get packages by Shopify tag
     */
    public function getPackagesByTag(string $tag)
    {
        return Package::where('shopify_tag', $tag)
            ->where('status', 'active')
            ->withCount('audios')
            ->with(['audios' => fn($q) => $q->orderBy('order_index')])
            ->get();
    }

    /**
     * Upload cover image with unique filename
     */
    private function uploadCoverImage(UploadedFile $file): string
    {
        $filename = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName());
        return $file->storeAs('covers', $filename, 'public');
    }

    /**
     * Delete old cover image from storage
     */
    private function deleteOldCoverImage(Package $package): void
    {
        if ($package->cover_image && Storage::disk('public')->exists($package->cover_image)) {
            Storage::disk('public')->delete($package->cover_image);
        }
    }
}
