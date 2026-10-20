<?php

namespace App\Services\Bundles;

use App\Models\Bundles\Bundle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Stores, orders and removes the images uploaded for a bundle.
 * Shopify is updated on the next sync (BundleSyncService::syncImages).
 */
class BundleImageService
{
    public const MAX_IMAGES = 10;

    /**
     * @param  array  $order  final order as tokens: "id:12" (existing image) or "new:0" (index into $files)
     * @param  UploadedFile[]  $files
     */
    public function apply(Bundle $bundle, array $order, array $files): void
    {
        $existing = $bundle->images()->get()->keyBy('id');

        // No explicit order: keep existing images and append the new files.
        if (! $order) {
            $order = $existing->keys()->map(fn ($id) => "id:{$id}")
                ->merge(array_map(fn ($i) => "new:{$i}", array_keys($files)))
                ->all();
        }

        $keep = [];
        $position = 0;

        foreach ($order as $token) {
            if ($position >= self::MAX_IMAGES) {
                break;
            }

            [$type, $key] = array_pad(explode(':', (string) $token, 2), 2, null);

            if ($type === 'id' && ($image = $existing->get((int) $key))) {
                $image->update(['position' => $position++]);
                $keep[] = $image->id;
            } elseif ($type === 'new' && ($file = $files[(int) $key] ?? null) instanceof UploadedFile) {
                $image = $bundle->images()->create([
                    'path'          => $file->store("bundles/{$bundle->id}", 'public'),
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 250),
                    'position'      => $position++,
                ]);
                $keep[] = $image->id;
            }
        }

        // Removed images: delete the file now, the Shopify media on the next sync.
        $pending = $bundle->pending_media_deletes ?? [];
        foreach ($existing->except($keep) as $image) {
            if ($image->shopify_media_id) {
                $pending[] = $image->shopify_media_id;
            }
            Storage::disk('public')->delete($image->path);
            $image->delete();
        }

        $bundle->forceFill(['pending_media_deletes' => array_values(array_unique($pending))])->saveQuietly();
    }
}
