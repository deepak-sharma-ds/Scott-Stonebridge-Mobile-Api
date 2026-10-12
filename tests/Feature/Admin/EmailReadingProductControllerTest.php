<?php

namespace Tests\Feature\Admin;

use App\Mail\EmailReadingMail;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
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

    public function test_preview_requires_authentication(): void
    {
        $product = EmailReadingProduct::create($this->payload());

        $response = $this->get(route('admin.email-reading-products.preview', $product));

        $response->assertRedirect(route('login'));
    }

    public function test_preview_renders_email_reading_mail_with_sample_data(): void
    {
        $product = EmailReadingProduct::create($this->payload());

        $response = $this->actingAs(User::factory()->create())
            ->get(route('admin.email-reading-products.preview', $product));

        $response->assertOk();
        $this->assertStringContainsString('Love Reading', $response->getContent());
        $this->assertStringContainsString('Your Current Path', $response->getContent());
    }

    public function test_preview_accepts_unsaved_overrides_without_persisting(): void
    {
        $product = EmailReadingProduct::create($this->payload());

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('admin.email-reading-products.preview', $product), [
                'customer_name' => 'Custom Customer Name',
                'name' => 'Overridden Product Name',
                'email_content' => '<p>Dear {{ $customerName }}, intro for {{ $productTitle }}</p>',
                'body' => '<h2>Live Unsaved Reading Body</h2><p>Special insight</p>',
            ]);

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString('Custom Customer Name', $content);
        $this->assertStringContainsString('intro for Overridden Product Name', $content);
        $this->assertStringContainsString('Live Unsaved Reading Body', $content);

        $this->assertSame(0, EmailReadingDelivery::count());
        $this->assertSame('Love Reading', $product->fresh()->name);
    }

    public function test_send_test_requires_authentication(): void
    {
        $product = EmailReadingProduct::create($this->payload());

        $response = $this->postJson(route('admin.email-reading-products.send-test', $product), [
            'body' => '<p>Test body</p>',
        ]);

        $response->assertUnauthorized();
    }

    public function test_send_test_returns_unprocessable_entity_if_user_has_no_email(): void
    {
        $admin = User::factory()->create(['email' => '']);
        $product = EmailReadingProduct::create($this->payload());

        $response = $this->actingAs($admin)
            ->postJson(route('admin.email-reading-products.send-test', $product), [
                'body' => '<p>Test body</p>',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Logged-in user has no email address configured.');
    }

    public function test_send_test_sends_email_reading_mail_to_authenticated_admin_only_and_creates_no_delivery(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['email' => 'admin-tester@example.com']);
        $product = EmailReadingProduct::create($this->payload());

        $response = $this->actingAs($admin)
            ->postJson(route('admin.email-reading-products.send-test', $product), [
                'customer_name' => 'Admin Preview Target',
                'body' => '<h2>Tested In Transit Body</h2><p>Sent directly to admin</p>',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Test email sent to admin-tester@example.com.');

        Mail::assertSent(EmailReadingMail::class, function (EmailReadingMail $mail) use ($admin): bool {
            $this->assertTrue($mail->hasTo($admin->email));
            $this->assertSame('Admin Preview Target', $mail->delivery->customer_name);
            $this->assertStringContainsString('Tested In Transit Body', $mail->delivery->ai_response);

            return true;
        });

        $this->assertSame(0, EmailReadingDelivery::count());
    }

    public function test_prompt_test_output_renders_within_the_real_email_preview_template(): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => "## Tarot Spread\n\nYour reading suggests peaceful expansion."]],
                ],
            ]),
        ]);

        $admin = User::factory()->create();
        $product = EmailReadingProduct::create($this->payload());

        $testResponse = $this->actingAs($admin)
            ->postJson(route('admin.email-reading-products.test'), [
                'prompt_template' => 'Customer: {{ $customer_name }}.',
                'customer_name' => 'Samantha',
                'answers' => ['q1' => 'My future'],
            ]);

        $testResponse->assertOk()->assertJsonPath('success', true);
        $generatedHtml = (string) $testResponse->json('content');

        $previewResponse = $this->actingAs($admin)
            ->postJson(route('admin.email-reading-products.preview', $product), [
                'customer_name' => 'Samantha',
                'body' => $generatedHtml,
            ]);

        $previewResponse->assertOk();
        $previewHtml = $previewResponse->getContent();
        $this->assertStringContainsString('class="ss-reading"', $previewHtml);
        $this->assertStringContainsString('>Tarot Spread</h2>', $previewHtml);
        $this->assertStringContainsString('Your reading suggests peaceful expansion.', $previewHtml);
    }
}
