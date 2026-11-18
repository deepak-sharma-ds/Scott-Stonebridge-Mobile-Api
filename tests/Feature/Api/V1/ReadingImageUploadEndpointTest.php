<?php

namespace Tests\Feature\Api\V1;

use App\Http\Requests\EmailReadingProductRequest;
use App\Models\EmailReadingProduct;
use App\Models\ReadingImageUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ReadingImageUploadEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    public function test_it_stores_a_jpeg_and_returns_a_reference(): void
    {
        $response = $this->postJson('/api/v1/reading-uploads', [
            'image' => UploadedFile::fake()->image('selfie.jpg', 600, 600),
        ]);

        $response->assertCreated()->assertJsonPath('success', true);

        $upload = ReadingImageUpload::firstOrFail();
        $this->assertStringContainsString('/reading-uploads/', $response->json('reference'));
        $this->assertStringContainsString($upload->token, $response->json('reference'));
        $this->assertSame('image/jpeg', $upload->mime);
        $this->assertSame('private', $upload->disk);
        Storage::disk('private')->assertExists($upload->path);
    }

    public function test_the_stored_file_is_a_complete_image_even_with_stray_output(): void
    {
        echo 'stray-output';

        $this->postJson('/api/v1/reading-uploads', [
            'image' => UploadedFile::fake()->image('selfie.jpg', 600, 600),
        ])->assertCreated();

        $binary = Storage::disk('private')->get(ReadingImageUpload::firstOrFail()->path);

        $this->assertStringStartsWith("\xFF\xD8", $binary);
        $this->assertStringEndsWith("\xFF\xD9", $binary);
    }

    public function test_the_public_link_serves_the_exact_stored_bytes_despite_stray_output(): void
    {
        $this->postJson('/api/v1/reading-uploads', [
            'image' => UploadedFile::fake()->image('selfie.jpg', 600, 600),
        ])->assertCreated();

        $upload = ReadingImageUpload::firstOrFail();
        $stored = Storage::disk('private')->get($upload->path);

        echo "\xA3\xFF\xFF\x08";

        $response = $this->get('/reading-uploads/'.$upload->token);

        $response->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('identity', $response->headers->get('Content-Encoding'));
        $this->assertStringContainsString('no-transform', $response->headers->get('Cache-Control'));
        $this->assertSame((string) strlen($stored), $response->headers->get('Content-Length'));
        $this->assertSame($stored, $response->getContent());
    }

    public function test_the_image_is_filed_under_the_product_folder(): void
    {
        EmailReadingProduct::create([
            'shopify_product_id' => 777,
            'name' => 'Selfie Reading',
            'slug' => 'selfie-reading',
            'questions_schema' => [['key' => 'Q1', 'label' => 'Your selfie', 'type' => 'image', 'required' => true]],
            'prompt_template' => 'x',
            'email_subject' => 's',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/reading-uploads', [
            'image' => UploadedFile::fake()->image('selfie.jpg', 600, 600),
            'product_id' => 777,
        ])->assertCreated();

        $this->assertStringStartsWith('reading-uploads/selfie-reading/', ReadingImageUpload::firstOrFail()->path);
    }

    public function test_a_replaced_unclaimed_image_can_be_discarded_but_a_claimed_one_cannot(): void
    {
        $first = $this->postJson('/api/v1/reading-uploads', ['image' => UploadedFile::fake()->image('a.jpg', 600, 600)])->json('reference');
        $second = $this->postJson('/api/v1/reading-uploads', ['image' => UploadedFile::fake()->image('b.jpg', 600, 600)])->json('reference');
        $old = ReadingImageUpload::where('token', basename($first))->firstOrFail();
        $claimed = ReadingImageUpload::where('token', basename($second))->firstOrFail();
        $claimed->forceFill(['claimed_at' => now()])->save();

        $this->postJson('/api/v1/reading-uploads/discard', ['reference' => $first])->assertOk();
        $this->postJson('/api/v1/reading-uploads/discard', ['reference' => $second])->assertOk();

        $this->assertNull(ReadingImageUpload::find($old->id));
        Storage::disk('private')->assertMissing($old->path);
        Storage::disk('private')->assertExists($claimed->path);
    }

    public function test_it_accepts_png(): void
    {
        $this->postJson('/api/v1/reading-uploads', [
            'image' => UploadedFile::fake()->image('selfie.png', 500, 500),
        ])->assertCreated();

        $this->assertSame('image/png', ReadingImageUpload::firstOrFail()->mime);
    }

    public function test_it_rejects_other_file_types(): void
    {
        $this->postJson('/api/v1/reading-uploads', [
            'image' => UploadedFile::fake()->image('anim.gif', 500, 500),
        ])->assertStatus(422)->assertJsonValidationErrors('image');

        $this->postJson('/api/v1/reading-uploads', [
            'image' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('image');

        $this->assertSame(0, ReadingImageUpload::count());
    }

    public function test_it_rejects_a_non_image_renamed_to_jpg(): void
    {
        $this->postJson('/api/v1/reading-uploads', [
            'image' => UploadedFile::fake()->createWithContent('fake.jpg', 'this is not an image'),
        ])->assertStatus(422);

        $this->assertSame(0, ReadingImageUpload::count());
    }

    public function test_it_rejects_files_over_the_size_limit(): void
    {
        config(['email_reading.image_upload.max_kb' => 100]);

        $this->postJson('/api/v1/reading-uploads', [
            'image' => UploadedFile::fake()->image('big.jpg', 600, 600)->size(500),
        ])->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_it_rejects_tiny_images(): void
    {
        $this->postJson('/api/v1/reading-uploads', [
            'image' => UploadedFile::fake()->image('tiny.jpg', 50, 50),
        ])->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_it_rejects_a_missing_or_multiple_files(): void
    {
        $this->postJson('/api/v1/reading-uploads', [])->assertStatus(422)->assertJsonValidationErrors('image');

        $this->postJson('/api/v1/reading-uploads', [
            'image' => [
                UploadedFile::fake()->image('a.jpg', 500, 500),
                UploadedFile::fake()->image('b.jpg', 500, 500),
            ],
        ])->assertStatus(422);

        $this->assertSame(0, ReadingImageUpload::count());
    }

    public function test_the_questions_api_returns_the_image_type(): void
    {
        EmailReadingProduct::create([
            'shopify_product_id' => 777,
            'name' => 'Selfie Reading',
            'slug' => 'selfie-reading',
            'questions_schema' => [['key' => 'Q1', 'label' => 'Your selfie', 'type' => 'image', 'required' => true]],
            'prompt_template' => 'x',
            'email_subject' => 's',
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/reading-products/777/questions')
            ->assertOk()
            ->assertJsonPath('product.questions.0.type', 'image');
    }

    public function test_the_product_form_accepts_the_image_type(): void
    {
        $rules = (new EmailReadingProductRequest)->rules();
        $subset = [
            'questions_schema' => $rules['questions_schema'],
            'questions_schema.*.type' => $rules['questions_schema.*.type'],
        ];

        $valid = Validator::make(['questions_schema' => [['key' => 'Q1', 'label' => 'Selfie', 'type' => 'image']]], $subset);
        $invalid = Validator::make(['questions_schema' => [['key' => 'Q1', 'label' => 'Selfie', 'type' => 'video']]], $subset);

        $this->assertTrue($valid->passes());
        $this->assertTrue($invalid->fails());
    }

    public function test_the_admin_viewer_is_not_public(): void
    {
        $upload = ReadingImageUpload::create([
            'token' => str_repeat('a', 40),
            'disk' => 'local',
            'path' => 'reading-uploads/x.jpg',
            'mime' => 'image/jpeg',
            'size' => 10,
        ]);

        $response = $this->get(route('admin.reading_uploads.show', ['token' => $upload->token]));

        $this->assertNotSame(200, $response->getStatusCode());
    }
}
