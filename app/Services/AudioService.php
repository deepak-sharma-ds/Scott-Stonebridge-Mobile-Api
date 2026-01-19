<?php

namespace App\Services;

use App\Models\Audio;
use App\Models\Package;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AudioService
{
    /**
     * Get paginated audios with package relationship (optimized)
     */
    public function getPaginatedAudios(int $perPage = 10): LengthAwarePaginator
    {
        return Audio::with('package:id,title,shopify_tag')
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Get audios by package
     */
    public function getAudiosByPackage(int $packageId)
    {
        return Audio::where('package_id', $packageId)
            ->orderBy('order_index')
            ->get();
    }

    /**
     * Get ready HLS audios
     */
    public function getReadyAudios()
    {
        return Audio::where('is_hls_ready', true)
            ->with('package:id,title')
            ->orderBy('order_index')
            ->get();
    }

    /**
     * Create audio
     */
    public function createAudio(array $data): Audio
    {
        return DB::transaction(function () use ($data) {
            // Set default order if not provided
            if (!isset($data['order_index']) && isset($data['package_id'])) {
                $data['order_index'] = $this->getNextOrderIndex($data['package_id']);
            }

            return Audio::create($data);
        });
    }

    /**
     * Update audio
     */
    public function updateAudio(Audio $audio, array $data): Audio
    {
        return DB::transaction(function () use ($audio, $data) {
            $audio->update($data);
            return $audio->fresh();
        });
    }

    /**
     * Delete audio
     */
    public function deleteAudio(Audio $audio): bool
    {
        return DB::transaction(function () use ($audio) {
            // Delete HLS files if exists
            if ($audio->hls_path && Storage::exists($audio->hls_path)) {
                Storage::deleteDirectory(dirname($audio->hls_path));
            }

            return $audio->delete();
        });
    }

    /**
     * Reorder audios in a package
     */
    public function reorderAudios(int $packageId, array $audioIds): bool
    {
        return DB::transaction(function () use ($packageId, $audioIds) {
            foreach ($audioIds as $index => $audioId) {
                Audio::where('id', $audioId)
                    ->where('package_id', $packageId)
                    ->update(['order_index' => $index + 1]);
            }
            return true;
        });
    }

    /**
     * Mark audio as HLS ready
     */
    public function markAsHLSReady(Audio $audio, string $hlsPath, ?int $duration = null): Audio
    {
        $audio->update([
            'is_hls_ready' => true,
            'hls_path' => $hlsPath,
            'duration_seconds' => $duration ?? $audio->duration_seconds,
        ]);

        return $audio->fresh();
    }

    /**
     * Get next order index for package
     */
    private function getNextOrderIndex(int $packageId): int
    {
        $maxOrder = Audio::where('package_id', $packageId)->max('order_index');
        return ($maxOrder ?? 0) + 1;
    }
}
