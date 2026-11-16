<?php

namespace App\Console\Commands;

use App\Models\ReadingImageUpload;
use App\Services\EmailReading\ReadingImageShopifyFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PurgeReadingImageUploads extends Command
{
    protected $signature = 'reading-images:purge';

    protected $description = 'Delete expired reading image uploads (private copy and Shopify Files copy).';

    public function handle(ReadingImageShopifyFiles $shopifyFiles): int
    {
        $unclaimedHours = (int) config('email_reading.image_upload.unclaimed_ttl_hours', 720);
        $retentionDays = config('email_reading.image_upload.retention_days');

        $expired = ReadingImageUpload::query()
            ->where(function ($query) use ($unclaimedHours) {
                $query->whereNull('claimed_at')->where('created_at', '<', now()->subHours($unclaimedHours));
            })
            ->when($retentionDays !== null && $retentionDays !== '', function ($query) use ($retentionDays) {
                $query->orWhere('claimed_at', '<', now()->subDays((int) $retentionDays));
            })
            ->get();

        $purged = 0;

        foreach ($expired as $upload) {
            if ($upload->shopify_file_id && $shopifyFiles->isConfigured()) {
                try {
                    $shopifyFiles->delete($upload);
                } catch (Throwable $e) {
                    $this->warn("Upload {$upload->id}: Shopify file not deleted ({$e->getMessage()}); will retry.");

                    continue;
                }
            }

            Storage::disk($upload->disk)->delete($upload->path);
            $upload->delete();
            $purged++;
        }

        $this->info("Purged {$purged} reading image upload(s).");

        return self::SUCCESS;
    }
}
