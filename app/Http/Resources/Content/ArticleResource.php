<?php

namespace App\Http\Resources\Content;

use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;

/**
 * Article API Resource
 * 
 * Transforms ArticleDTO data to API response format.
 * Includes content, images, tags, and author information.
 * 
 * Requirements: 12.8, 12.9, 12.10, 12.11
 */
class ArticleResource extends BaseApiResource
{
    /**
     * Transform the resource into an array.
     * 
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'handle' => $this->handle,
            'content' => $this->content,
            'excerpt' => $this->excerpt,
            'image' => $this->image,
            'tags' => $this->tags,
            'author' => $this->author,
            'published_at' => $this->publishedAt,
        ];
    }
}
