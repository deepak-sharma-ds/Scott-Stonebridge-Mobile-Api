<?php

declare(strict_types=1);

namespace Tests\Feature\Services\EmailReading;

use App\Jobs\EmailReading\ProcessEmailReadingOrderJob;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Models\ReadingImageUpload;
use App\Services\EmailReading\EmailReadingGenerationService;
use App\Services\EmailReading\ReadingImageUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use ReflectionMethod;
use Tests\TestCase;

class ReadingImageGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['email_reading.image_upload.shopify_copy' => false]);
    }

    private function delivery(array $schema, ?string $answer, string $template = 'Selfie: {{ $q1 }}'): EmailReadingDelivery
    {
        $product = EmailReadingProduct::create([
            'shopify_product_id' => 321,
            'name' => 'Selfie Reading',
            'slug' => 'selfie-reading',
            'questions_schema' => $schema,
            'prompt_template' => $template,
            'email_subject' => 'Your reading',
        ]);

        return EmailReadingDelivery::create([
            'shopify_order_id' => 1,
            'shopify_line_item_id' => 1,
            'email_reading_product_id' => $product->id,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Jane Doe',
            'questions' => $answer === null ? [] : ['q1' => $answer, 'q' => $answer],
            'status' => EmailReadingDelivery::STATUS_PENDING,
        ]);
    }

    private function upload(): ReadingImageUpload
    {
        return app(ReadingImageUploadService::class)->store(UploadedFile::fake()->image('selfie.jpg', 400, 400));
    }

    private function fakeReply(): void
    {
        OpenAI::fake([CreateResponse::fake(['choices' => [['message' => [
            'role' => 'assistant',
            'content' => "## Your Reading\n\nA clear path opens.\n\n### From My Heart to Yours\n\nWalk forward with joy.",
        ], 'finish_reason' => 'stop']]])]);
    }

    public function test_the_image_is_attached_to_the_openai_request_and_the_link_stays_out_of_the_prompt(): void
    {
        $this->fakeReply();
        $upload = $this->upload();
        $reference = $upload->reference();
        $delivery = $this->delivery([['key' => 'Q1', 'label' => 'Selfie', 'type' => 'image', 'required' => true]], $reference);

        app(EmailReadingGenerationService::class)->generate($delivery->load('product'));

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters) use ($reference): bool {
            $user = collect($parameters['messages'])->firstWhere('role', 'user')['content'];

            return is_array($user)
                && $user[0]['type'] === 'text'
                && str_contains($user[0]['text'], 'attached')
                && ! str_contains($user[0]['text'], $reference)
                && $user[1]['type'] === 'image_url'
                && str_starts_with($user[1]['image_url']['url'], 'data:image/jpeg;base64,');
        });

        $this->assertNotNull($upload->fresh()->claimed_at);
        $this->assertSame($delivery->id, $upload->fresh()->email_reading_delivery_id);
    }

    public function test_text_only_products_send_a_plain_string_message(): void
    {
        $this->fakeReply();
        $delivery = $this->delivery([['key' => 'Q1', 'label' => 'Question', 'type' => 'text', 'required' => true]], 'Hello', 'Q: {{ $q1 }}');

        app(EmailReadingGenerationService::class)->generate($delivery->load('product'));

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $user = collect($parameters['messages'])->firstWhere('role', 'user')['content'];

            return is_string($user) && $user === 'Q: Hello';
        });
    }

    public function test_an_unresolvable_image_is_not_sent(): void
    {
        $this->fakeReply();
        $delivery = $this->delivery(
            [['key' => 'Q1', 'label' => 'Selfie', 'type' => 'image', 'required' => false]],
            'https://example.test/admin/reading-uploads/'.str_repeat('z', 40)
        );

        app(EmailReadingGenerationService::class)->generate($delivery->load('product'));

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            return is_string(collect($parameters['messages'])->firstWhere('role', 'user')['content']);
        });
    }

    public function test_resolve_matches_a_token_url_and_a_shopify_cdn_url(): void
    {
        $service = app(ReadingImageUploadService::class);
        $upload = $this->upload();
        $upload->forceFill(['shopify_file_url' => 'https://cdn.shopify.com/s/files/1/0/selfie.jpg'])->save();

        $this->assertTrue($service->resolve(route('admin.reading_uploads.show', ['token' => $upload->token]))->is($upload));
        $this->assertTrue($service->resolve('https://cdn.shopify.com/s/files/1/0/selfie.jpg?v=123')->is($upload));
        $this->assertNull($service->resolve('https://cdn.shopify.com/s/files/1/0/other.jpg'));
        $this->assertNull($service->resolve('just some text'));
        $this->assertTrue($service->looksLikeReference('https://cdn.shopify.com/s/files/1/0/other.jpg'));
        $this->assertFalse($service->looksLikeReference('I hope for love'));
    }

    public function test_a_required_image_slot_is_missing_when_its_upload_is_gone(): void
    {
        $delivery = $this->delivery(
            [['key' => 'Q1', 'label' => 'Selfie', 'type' => 'image', 'required' => true]],
            'https://example.test/admin/reading-uploads/'.str_repeat('z', 40)
        );

        $method = new ReflectionMethod(ProcessEmailReadingOrderJob::class, 'missingRequiredQuestions');
        $job = new ProcessEmailReadingOrderJob($delivery->id);

        $this->assertSame(['Selfie'], $method->invoke($job, $delivery->load('product')));

        $upload = $this->upload();
        $delivery->update(['questions' => ['q1' => $upload->reference()]]);

        $this->assertSame([], $method->invoke($job, $delivery->fresh()->load('product')));
    }

    public function test_purge_removes_old_unclaimed_uploads_and_keeps_fresh_or_claimed_ones(): void
    {
        $old = $this->upload();
        $fresh = $this->upload();
        $claimed = $this->upload();
        $old->forceFill(['created_at' => now()->subDays(60)])->save();
        $claimed->forceFill(['created_at' => now()->subDays(60), 'claimed_at' => now()->subDay()])->save();

        $this->artisan('reading-images:purge')->assertSuccessful();

        $this->assertNull(ReadingImageUpload::find($old->id));
        Storage::disk('local')->assertMissing($old->path);
        $this->assertNotNull(ReadingImageUpload::find($fresh->id));
        $this->assertNotNull(ReadingImageUpload::find($claimed->id));
    }
}
