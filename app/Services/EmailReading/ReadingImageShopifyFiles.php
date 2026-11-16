<?php

namespace App\Services\EmailReading;

use App\Models\ReadingImageUpload;
use App\Services\Bundles\ShopifyAdminClient;
use App\Services\Bundles\ShopifyApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Copies a reading image into Shopify Files (stagedUploadsCreate, upload,
 * fileCreate) and reads back its CDN URL. Needs the write_files / read_files
 * scopes on the app credentials.
 */
class ReadingImageShopifyFiles
{
    public function __construct(private readonly ShopifyAdminClient $client) {}

    public function isConfigured(): bool
    {
        return $this->client->credentials()->isConfigured();
    }

    /**
     * Upload the file (once) and wait up to $waitSeconds for Shopify to finish
     * processing it. Safe to call again: it only repeats the missing step.
     *
     * @throws ShopifyApiException
     */
    public function copy(ReadingImageUpload $upload, int $waitSeconds = 0): ReadingImageUpload
    {
        if ($upload->shopify_file_url) {
            return $upload;
        }

        if (! $upload->shopify_file_id) {
            $upload->forceFill(['shopify_file_id' => $this->createFile($upload)])->save();
        }

        $deadline = microtime(true) + $waitSeconds;

        do {
            $url = $this->readyUrl((string) $upload->shopify_file_id);

            if ($url !== null) {
                $upload->forceFill(['shopify_file_url' => $url])->save();

                return $upload;
            }

            if ($waitSeconds > 0) {
                usleep(1_000_000);
            }
        } while (microtime(true) < $deadline);

        return $upload;
    }

    /**
     * @throws ShopifyApiException
     */
    public function delete(ReadingImageUpload $upload): void
    {
        if (! $upload->shopify_file_id) {
            return;
        }

        $this->client->mutate('fileDelete', <<<'GQL'
            mutation($fileIds: [ID!]!) {
                fileDelete(fileIds: $fileIds) {
                    deletedFileIds
                    userErrors { field message }
                }
            }
        GQL, ['fileIds' => [$upload->shopify_file_id]]);
    }

    /**
     * @throws ShopifyApiException
     */
    private function createFile(ReadingImageUpload $upload): string
    {
        $disk = Storage::disk($upload->disk);

        if (! $disk->exists($upload->path)) {
            throw new ShopifyApiException("Reading image is missing on disk: {$upload->path}");
        }

        $filename = $upload->token.'.'.pathinfo($upload->path, PATHINFO_EXTENSION);
        $contents = $disk->get($upload->path);

        $staged = $this->client->mutate('stagedUploadsCreate', <<<'GQL'
            mutation($input: [StagedUploadInput!]!) {
                stagedUploadsCreate(input: $input) {
                    stagedTargets { url resourceUrl parameters { name value } }
                    userErrors { field message }
                }
            }
        GQL, ['input' => [[
            'resource' => 'IMAGE',
            'filename' => $filename,
            'mimeType' => $upload->mime,
            'httpMethod' => 'POST',
            'fileSize' => (string) $upload->size,
        ]]]);

        $target = $staged['stagedTargets'][0] ?? null;
        if (! $target) {
            throw new ShopifyApiException('Shopify did not return an upload target.');
        }

        // Signed fields must come before the file.
        $fields = array_column($target['parameters'], 'value', 'name');
        $response = Http::timeout(60)->attach('file', $contents, $filename)->post($target['url'], $fields);

        if (! $response->successful()) {
            throw new ShopifyApiException("Image upload to Shopify failed [{$response->status()}].");
        }

        $created = $this->client->mutate('fileCreate', <<<'GQL'
            mutation($files: [FileCreateInput!]!) {
                fileCreate(files: $files) {
                    files { id fileStatus }
                    userErrors { field message }
                }
            }
        GQL, ['files' => [[
            'originalSource' => $target['resourceUrl'],
            'contentType' => 'IMAGE',
            'alt' => 'Reading image '.$upload->token,
            'filename' => $filename,
        ]]]);

        $id = $created['files'][0]['id'] ?? null;
        if (! $id) {
            throw new ShopifyApiException('Shopify did not return a file id.');
        }

        return $id;
    }

    /**
     * CDN URL once Shopify has finished processing the file, otherwise null.
     *
     * @throws ShopifyApiException
     */
    private function readyUrl(string $fileId): ?string
    {
        $data = $this->client->query(<<<'GQL'
            query($id: ID!) {
                node(id: $id) {
                    ... on MediaImage {
                        fileStatus
                        image { url }
                    }
                }
            }
        GQL, ['id' => $fileId]);

        $node = $data['node'] ?? [];

        if (($node['fileStatus'] ?? null) === 'FAILED') {
            throw new ShopifyApiException('Shopify could not process the uploaded image.');
        }

        $url = ($node['fileStatus'] ?? null) === 'READY' ? ($node['image']['url'] ?? null) : null;

        // Strip the cache-busting query so the stored value is stable for lookups.
        return $url ? strtok($url, '?') : null;
    }
}
