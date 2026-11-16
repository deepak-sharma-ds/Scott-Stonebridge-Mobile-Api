<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One customer-uploaded image for an "image" question slot. The private copy
 * on the local disk is the source of truth (admin viewer, OpenAI); the Shopify
 * Files copy is for the internal team and the order line property.
 */
class ReadingImageUpload extends Model
{
    protected $fillable = [
        'token',
        'disk',
        'path',
        'mime',
        'size',
        'shopify_file_id',
        'shopify_file_url',
        'email_reading_delivery_id',
        'claimed_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'claimed_at' => 'datetime',
        ];
    }

    /** The value saved as the answer: the Shopify CDN URL when ready, else the admin viewer URL. */
    public function reference(): string
    {
        return $this->shopify_file_url ?: route('admin.reading_uploads.show', ['token' => $this->token]);
    }
}
