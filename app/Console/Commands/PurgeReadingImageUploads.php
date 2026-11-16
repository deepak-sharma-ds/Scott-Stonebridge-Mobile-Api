<?php

namespace App\Console\Commands;

use App\Models\ReadingImageUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeReadingImageUploads extends Command
{
    protected $signature = 'reading-images:purge';

    protected $description = 'Delete expired reading image uploads (private copy).';

    public function handle(): int
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
            Storage::disk($upload->disk)->delete($upload->path);
            $upload->delete();
            $purged++;
        }

        $this->info("Purged {$purged} reading image upload(s).");

        return self::SUCCESS;
    }
}
