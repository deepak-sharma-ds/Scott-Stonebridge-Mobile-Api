<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AudioResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'package_id'      => $this->package_id,
            'title'            => $this->title,
            'file_path'        => $this->file_path,
            // 'file_url'         => $this->file_path
            //     ? Storage::disk('private')->url($this->file_path)
            //     : null,
            'duration_seconds' => $this->duration_seconds,
            'order_index'      => $this->order_index,
            'status'           => $this->status,
            'created_at'       => $this->created_at?->format('d M Y'),
            'updated_at'       => $this->updated_at?->format('d M Y'),

            // 🔹 Include package info when loaded
            'package' => $this->whenLoaded('package', function () {
                return [
                    'id'          => $this->package->id,
                    'title'       => $this->package->title,
                    'slug'        => Str::slug($this->package->title, '-'),
                    'price'       => (float) $this->package->price,
                    'currency'    => strtoupper($this->package->currency),
                    'shopify_tag' => $this->package->shopify_tag
                        ? Str::slug($this->package->shopify_tag, '-')
                        : null,
                    'cover_image' => $this->package->cover_image
                        ? Storage::disk('public')->url($this->package->cover_image)
                        : null,
                    'status'      => $this->package->status,
                ];
            }),
        ];
    }
}
