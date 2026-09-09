<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RichTextUploadControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_an_image_for_the_rich_text_editor(): void
    {
        Storage::fake('public');

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('admin.rich-text-uploads.store'), [
                'upload' => UploadedFile::fake()->image('inline.jpg'),
            ]);

        $response->assertOk();
        $url = $response->json('url');
        $this->assertNotEmpty($url);
        $this->assertStringContainsString('rich-text-uploads/', $url);

        $path = 'rich-text-uploads/'.basename((string) parse_url($url, PHP_URL_PATH));
        Storage::disk('public')->assertExists($path);
    }

    public function test_rejects_a_non_image_upload(): void
    {
        Storage::fake('public');

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('admin.rich-text-uploads.store'), [
                'upload' => UploadedFile::fake()->create('document.pdf', 100),
            ]);

        $response->assertStatus(422);
    }
}
