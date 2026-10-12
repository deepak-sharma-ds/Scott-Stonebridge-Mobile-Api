<?php

namespace Tests\Feature\Admin;

use App\Models\EmailReadingProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

class EmailReadingProductControllerTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'shopify_product_id' => 111,
            'name' => 'Love Reading',
            'slug' => 'love-reading',
            'email_subject' => 'Your Reading',
            'prompt_template' => 'Customer: {{ $customer_name }}.',
            'questions_schema' => [
                ['key' => 'q1', 'label' => 'What do you want to know?', 'required' => true],
            ],
        ], $overrides);
    }

    public function test_admin_can_create_a_reading_product_with_a_header_image_and_template_fields(): void
    {
        Storage::fake('public');

        $response = $this->actingAs(User::factory()->create())
            ->post(route('admin.email-reading-products.store'), $this->payload([
                'header_image' => UploadedFile::fake()->image('banner.jpg'),
                'email_content' => 'Custom intro for {{ $productTitle }}.',
                'email_footer' => 'Custom footer.',
            ]));

        $product = EmailReadingProduct::where('slug', 'love-reading')->firstOrFail();
        $response->assertRedirect(route('admin.email-reading-products.index'));

        $this->assertSame('Custom intro for {{ $productTitle }}.', $product->email_content);
        $this->assertSame('Custom footer.', $product->email_footer);
        $this->assertNotNull($product->header_image);
        Storage::disk('public')->assertExists('reading-header-images/'.$product->header_image);
    }

    public function test_updating_without_a_new_upload_preserves_the_existing_header_image(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('reading-header-images/existing.jpg', 'fake-image-content');

        $product = EmailReadingProduct::create(array_merge($this->payload(), [
            'header_image' => 'existing.jpg',
        ]));

        $this->actingAs(User::factory()->create())
            ->put(route('admin.email-reading-products.update', $product), $this->payload([
                'email_content' => 'Updated intro.',
            ]));

        $this->assertSame('existing.jpg', $product->fresh()->header_image);
        $this->assertSame('Updated intro.', $product->fresh()->email_content);
    }

    public function test_admin_can_replace_the_header_image_on_update(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('reading-header-images/old.jpg', 'old-image-content');

        $product = EmailReadingProduct::create(array_merge($this->payload(), [
            'header_image' => 'old.jpg',
        ]));

        $this->actingAs(User::factory()->create())
            ->put(route('admin.email-reading-products.update', $product), $this->payload([
                'header_image' => UploadedFile::fake()->image('new.jpg'),
            ]));

        $fresh = $product->fresh();
        $this->assertNotSame('old.jpg', $fresh->header_image);
        Storage::disk('public')->assertExists('reading-header-images/'.$fresh->header_image);
    }

    public function test_admin_can_set_and_update_formatting_contract_override(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->post(route('admin.email-reading-products.store'), $this->payload([
                'formatting_contract' => '## CUSTOM CONTRACT',
            ]));

        $response->assertRedirect(route('admin.email-reading-products.index'));
        $product = EmailReadingProduct::where('slug', 'love-reading')->firstOrFail();
        $this->assertSame('## CUSTOM CONTRACT', $product->formatting_contract);

        $this->actingAs(User::factory()->create())
            ->put(route('admin.email-reading-products.update', $product), $this->payload([
                'formatting_contract' => '## UPDATED CONTRACT',
            ]));

        $this->assertSame('## UPDATED CONTRACT', $product->fresh()->formatting_contract);
    }

    public function test_admin_can_run_prompt_test_with_contract_and_receive_rendered_html(): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => "## Test Heading\n\n**Bold takeaway**"]],
                ],
            ]),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('admin.email-reading-products.test'), [
                'prompt_template' => 'Customer: {{ $customer_name }}.',
                'formatting_contract' => '## TEST SPECIFIC CONTRACT',
                'customer_name' => 'Alice',
                'answers' => ['q1' => 'My question'],
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $content = $response->json('content');
        $this->assertStringContainsString('<h2>Test Heading</h2>', $content);
        $this->assertStringContainsString('<strong>Bold takeaway</strong>', $content);

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $systemMessage = collect($parameters['messages'])->firstWhere('role', 'system')['content'] ?? '';

            return str_contains($systemMessage, 'TEST SPECIFIC CONTRACT');
        });
    }
}
