<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PackageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'slug'        => Str::slug($this->title, '-'),
            'description' => $this->description,
            'price'       => (float) $this->price,
            'currency'    => strtoupper($this->currency),
            'shopify_tag' => $this->shopify_tag
                ? Str::slug($this->shopify_tag, '-')
                : null,
            'cover_image' => $this->cover_image
                ? Storage::disk('public')->url($this->cover_image)
                : null,
            'status'      => $this->status,
            'created_at'  => $this->created_at?->format('d M Y'),
            'updated_at'  => $this->updated_at?->format('d M Y'),

            // Include audios if loaded
            'audios'      => $this->whenLoaded('audios', function () {
                return $this->audios->map(function ($audio) {
                    return [
                        'id' => $audio->id,
                        'title' => $audio->title,
                        'order_index' => $audio->order_index,
                        'duration_seconds' => $audio->duration_seconds,
                    ];
                });
            }),
        ];
    }
}
