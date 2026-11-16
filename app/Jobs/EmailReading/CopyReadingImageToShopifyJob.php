<?php

namespace App\Jobs\EmailReading;

use App\Models\ReadingImageUpload;
use App\Services\EmailReading\ReadingImageShopifyFiles;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Finishes the Shopify Files copy of a reading image when it was not ready (or
 * failed) during the checkout upload request. The upload keeps working from
 * its private copy meanwhile.
 */
class CopyReadingImageToShopifyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 60;

    public function __construct(public int $uploadId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(ReadingImageShopifyFiles $shopifyFiles): void
    {
        $upload = ReadingImageUpload::find($this->uploadId);

        if (! $upload || $upload->shopify_file_url) {
            return;
        }

        $shopifyFiles->copy($upload, 10);

        if (! $upload->shopify_file_url) {
            $this->release(60);
        }
    }
}
