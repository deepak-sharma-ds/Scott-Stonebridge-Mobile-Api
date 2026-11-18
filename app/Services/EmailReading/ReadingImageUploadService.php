<?php

namespace App\Services\EmailReading;

use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
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
    private const REFERENCE_PATH = '~/reading-uploads/(?:\d{4}/\d{2}/)?([A-Za-z0-9]{40})(?:\.(?:jpg|png))?(?:[/?#]|$)~';

    /**
     * Validate, re-encode (drops EXIF/GPS) and store the image, then try to
     * copy it to Shopify Files. A failed or slow Shopify copy never fails the
     * upload: the Laravel reference is returned and a job finishes the copy.
     *
     * @throws InvalidArgumentException when the file is not a usable JPEG/PNG
     */
    public function store(UploadedFile $file, ?int $shopifyProductId = null): ReadingImageUpload
    {
        [$binary, $mime, $extension] = $this->reencode($file);

        $disk = (string) config('email_reading.image_upload.disk', 'private');
        $token = Str::random(40);
        $path = 'reading-uploads/'.$this->productFolder($shopifyProductId).'/'.date('Y/m').'/'.$token.'.'.$extension;

        Storage::disk($disk)->put($path, $binary);

        if (Storage::disk($disk)->get($path) !== $binary) {
            Storage::disk($disk)->delete($path);
            Log::error('Reading image was corrupted while being written to disk', ['disk' => $disk, 'path' => $path]);

            throw new InvalidArgumentException('The image could not be saved. Please try again.');
        }

        $upload = ReadingImageUpload::create([
            'token' => $token,
            'disk' => $disk,
            'path' => $path,
            'mime' => $mime,
            'size' => strlen($binary),
        ]);

        return $upload;
    }

    /** Folder named after the product (from our own record, never client text); "general" when unknown. */
    private function productFolder(?int $shopifyProductId): string
    {
        $product = $shopifyProductId ? EmailReadingProduct::where('shopify_product_id', $shopifyProductId)->first() : null;
        $folder = Str::slug((string) ($product?->slug ?: $product?->name));

        return $folder !== '' ? $folder : 'general';
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

    /**
     * Deletes the upload a reference points at (file, Shopify copy, record). Only unclaimed uploads
     * are touched; claimed ones belong to an order. Never throws; returns whether it was deleted.
     */
    public function discard(?string $reference): bool
    {
        $upload = $this->resolve($reference);

        if ($upload === null || $upload->claimed_at !== null) {
            return false;
        }

        try {
            Storage::disk($upload->disk)->delete($upload->path);
            $upload->delete();

            return true;
        } catch (Throwable $e) {
            Log::warning('Reading image discard failed', ['upload_id' => $upload->id, 'error' => $e->getMessage()]);

            return false; // the scheduled purge removes it later
        }
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

        $binary = $this->encodeToString($image, $isJpeg);
        imagedestroy($image);

        if (! $this->isCompleteImage($binary, $isJpeg)) {
            Log::error('Reading image re-encode produced an invalid file', ['size' => strlen($binary)]);

            throw new InvalidArgumentException('The image could not be processed. Please try another image.');
        }

        return $isJpeg ? [$binary, 'image/jpeg', 'jpg'] : [$binary, 'image/png', 'png'];
    }

    /**
     * Encodes to a temp file instead of an output buffer, so stray output from
     * anywhere else in the request can never end up inside the image bytes.
     */
    private function encodeToString(\GdImage $image, bool $isJpeg): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'rimg');

        try {
            if ($isJpeg) {
                imagejpeg($image, $temp, 88);
            } else {
                imagesavealpha($image, true);
                imagepng($image, $temp);
            }

            return (string) file_get_contents($temp);
        } finally {
            @unlink($temp);
        }
    }

    /** Checks the markers: JPEG FFD8...FFD9, PNG signature...IEND. */
    private function isCompleteImage(string $binary, bool $isJpeg): bool
    {
        if ($isJpeg) {
            return str_starts_with($binary, "\xFF\xD8") && str_ends_with($binary, "\xFF\xD9");
        }

        return str_starts_with($binary, "\x89PNG\r\n\x1a\n") && str_contains(substr($binary, -12), 'IEND');
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
