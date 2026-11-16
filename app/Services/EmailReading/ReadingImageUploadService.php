<?php

namespace App\Services\EmailReading;

use App\Jobs\EmailReading\CopyReadingImageToShopifyJob;
use App\Models\EmailReadingDelivery;
use App\Models\ReadingImageUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Stores, resolves and prepares customer images for "image" question slots.
 */
class ReadingImageUploadService
{
    private const REFERENCE_PATH = '~/reading-uploads/([A-Za-z0-9]{40})(?:[/?#]|$)~';

    /**
     * Validate, re-encode (drops EXIF/GPS) and store the image, then try to
     * copy it to Shopify Files. A failed or slow Shopify copy never fails the
     * upload: the Laravel reference is returned and a job finishes the copy.
     *
     * @throws InvalidArgumentException when the file is not a usable JPEG/PNG
     */
    public function store(UploadedFile $file): ReadingImageUpload
    {
        [$binary, $mime, $extension] = $this->reencode($file);

        $disk = (string) config('email_reading.image_upload.disk', 'local');
        $token = Str::random(40);
        $path = 'reading-uploads/'.date('Y/m').'/'.$token.'.'.$extension;

        Storage::disk($disk)->put($path, $binary);

        $upload = ReadingImageUpload::create([
            'token' => $token,
            'disk' => $disk,
            'path' => $path,
            'mime' => $mime,
            'size' => strlen($binary),
        ]);

        return $this->copyToShopify($upload);
    }

    /**
     * Finish (or start) the Shopify Files copy. Never throws.
     */
    public function copyToShopify(ReadingImageUpload $upload, bool $queueOnFailure = true): ReadingImageUpload
    {
        if (! config('email_reading.image_upload.shopify_copy', true) || $upload->shopify_file_url) {
            return $upload;
        }

        // Resolved lazily: building it reads the bundle store credentials.
        $shopifyFiles = app(ReadingImageShopifyFiles::class);

        if (! $shopifyFiles->isConfigured()) {
            return $upload;
        }

        try {
            $upload = $shopifyFiles->copy(
                $upload,
                (int) config('email_reading.image_upload.shopify_ready_wait_seconds', 6)
            );
        } catch (Throwable $e) {
            Log::channel('shopify_webhooks')->warning('Reading image copy to Shopify Files failed', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($queueOnFailure && ! $upload->shopify_file_url) {
            CopyReadingImageToShopifyJob::dispatch($upload->id)
                ->delay(now()->addMinute())
                ->onConnection(config('email_reading.queue.connection'))
                ->onQueue(config('email_reading.queue.process'));
        }

        return $upload->refresh();
    }

    /**
     * The upload an answer value points at: the admin viewer URL (token) or
     * the Shopify CDN URL stored with the upload.
     */
    public function resolve(?string $value): ?ReadingImageUpload
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match(self::REFERENCE_PATH, $value, $match) === 1) {
            return ReadingImageUpload::where('token', $match[1])->first();
        }

        if ($this->isShopifyCdnUrl($value)) {
            return ReadingImageUpload::where('shopify_file_url', strtok($value, '?'))->first();
        }

        return null;
    }

    /** True for values that are image references (never worth moderating as text). */
    public function looksLikeReference(?string $value): bool
    {
        $value = trim((string) $value);

        return preg_match(self::REFERENCE_PATH, $value) === 1 || $this->isShopifyCdnUrl($value);
    }

    public function dataUrl(ReadingImageUpload $upload): ?string
    {
        $disk = Storage::disk($upload->disk);

        if (! $disk->exists($upload->path)) {
            return null;
        }

        return 'data:'.$upload->mime.';base64,'.base64_encode($disk->get($upload->path));
    }

    /**
     * One entry per "image" slot of the delivery's product: the prompt keys
     * the slot's answer is stored under, and the upload it resolves to (null
     * when the answer is empty or no longer resolves).
     *
     * @return list<array{keys:list<string>,upload:?ReadingImageUpload}>
     */
    public function imageSlots(EmailReadingDelivery $delivery): array
    {
        $questions = (array) $delivery->questions;
        $slots = [];
        $position = 0;

        foreach ((array) ($delivery->product?->questions_schema ?? []) as $slot) {
            $position++;

            if (($slot['type'] ?? 'text') !== 'image') {
                continue;
            }

            $keys = array_values(array_filter([
                EmailReadingDelivery::normalizeKey((string) ($slot['key'] ?? '')),
                EmailReadingDelivery::normalizeKey((string) ($slot['label'] ?? '')),
                'q'.$position,
            ], fn (string $key) => $key !== ''));

            $value = '';
            foreach ($keys as $key) {
                if (trim((string) ($questions[$key] ?? '')) !== '') {
                    $value = (string) $questions[$key];
                    break;
                }
            }

            $slots[] = ['keys' => $keys, 'upload' => $this->resolve($value)];
        }

        return $slots;
    }

    /**
     * Data URLs of the delivery's images for OpenAI; marks them claimed.
     *
     * @return list<string>
     */
    public function imagesForPrompt(EmailReadingDelivery $delivery): array
    {
        $images = [];

        foreach ($this->imageSlots($delivery) as $slot) {
            $upload = $slot['upload'];
            $dataUrl = $upload ? $this->dataUrl($upload) : null;

            if ($upload === null || $dataUrl === null) {
                continue;
            }

            if ($upload->claimed_at === null) {
                $upload->forceFill([
                    'claimed_at' => now(),
                    'email_reading_delivery_id' => $delivery->id,
                ])->save();
            }

            $images[] = $dataUrl;
        }

        return $images;
    }

    /**
     * @return array{0:string,1:string,2:string} binary, mime, extension
     */
    private function reencode(UploadedFile $file): array
    {
        $path = (string) $file->getRealPath();
        $info = $path !== '' ? @getimagesize($path) : false;

        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            throw new InvalidArgumentException('The image must be a JPG or PNG file.');
        }

        [$width, $height] = $info;
        $min = (int) config('email_reading.image_upload.min_dimension', 200);

        if ($width < $min || $height < $min) {
            throw new InvalidArgumentException("The image must be at least {$min} x {$min} pixels.");
        }

        // Guard decoder memory against decompression bombs.
        if ($width * $height > 40_000_000) {
            throw new InvalidArgumentException('The image dimensions are too large.');
        }

        $image = @imagecreatefromstring((string) file_get_contents($path));
        if ($image === false) {
            throw new InvalidArgumentException('The image could not be read.');
        }

        $isJpeg = $info[2] === IMAGETYPE_JPEG;

        if ($isJpeg && function_exists('exif_read_data')) {
            $image = $this->applyOrientation($image, $path);
        }

        $image = $this->downscale($image);

        ob_start();
        if ($isJpeg) {
            imagejpeg($image, null, 88);
        } else {
            imagesavealpha($image, true);
            imagepng($image);
        }
        $binary = (string) ob_get_clean();
        imagedestroy($image);

        return $isJpeg ? [$binary, 'image/jpeg', 'jpg'] : [$binary, 'image/png', 'png'];
    }

    private function applyOrientation(\GdImage $image, string $path): \GdImage
    {
        $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        return $rotated === false ? $image : $rotated;
    }

    private function downscale(\GdImage $image): \GdImage
    {
        $max = (int) config('email_reading.image_upload.max_dimension', 2048);
        $width = imagesx($image);
        $height = imagesy($image);

        if (max($width, $height) <= $max) {
            return $image;
        }

        $scaled = imagescale($image, $width >= $height ? $max : (int) round($width * $max / $height));

        return $scaled === false ? $image : $scaled;
    }

    private function isShopifyCdnUrl(string $value): bool
    {
        return str_starts_with($value, 'https://cdn.shopify.com/');
    }
}
