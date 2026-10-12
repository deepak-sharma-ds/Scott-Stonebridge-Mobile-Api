<?php

namespace Tests\Feature\Admin;

use App\Jobs\EmailReading\MarkReadingOrderFulfilledJob;
use App\Models\CampaignDelivery;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Models\ShopifyWebhookEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

class EmailReadingControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create();
    }

    private function delivery(array $overrides = []): EmailReadingDelivery
    {
        static $lineItem = 3000;

        $product = EmailReadingProduct::firstOrCreate(['shopify_product_id' => 111], [
            'name' => 'Love Reading',
            'slug' => 'love-reading',
            'questions_schema' => [],
            'prompt_template' => 'Reading',
            'email_subject' => 'Your reading',
            'is_active' => true,
            'is_automation_enabled' => true,
        ]);

        return EmailReadingDelivery::create(array_merge([
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => $lineItem++,
            'email_reading_product_id' => $product->id,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Jane Doe',
            'questions' => [],
            'ai_response' => 'Reading text.',
            'status' => EmailReadingDelivery::STATUS_GENERATED,
            'scheduled_at' => now()->addDays(4),
        ], $overrides));
    }

    private function updatePayload(EmailReadingDelivery $delivery, array $overrides = []): array
    {
        return array_merge([
            'customer_email' => $delivery->customer_email,
            'customer_name' => $delivery->customer_name,
            'ai_response' => $delivery->ai_response,
            'scheduled_at' => $delivery->scheduled_at?->format('Y-m-d\TH:i'),
        ], $overrides);
    }

    public function test_pages_render_for_every_state_including_sending_and_unfulfilled(): void
    {
        $admin = $this->admin();

        foreach ([
            EmailReadingDelivery::STATUS_PENDING,
            EmailReadingDelivery::STATUS_GENERATED,
            EmailReadingDelivery::STATUS_SENDING,
            EmailReadingDelivery::STATUS_FAILED,
            EmailReadingDelivery::STATUS_CANCELLED,
            EmailReadingDelivery::STATUS_SENT,
        ] as $status) {
            $delivery = $this->delivery(['status' => $status]);

            $this->actingAs($admin)->get(route('admin.email_readings.show', $delivery))->assertOk();
            $this->actingAs($admin)->get(route('admin.email_readings.edit', $delivery))->assertOk();
        }

        $this->actingAs($admin)->get(route('admin.email_readings.index'))->assertOk()->assertSee('Sending');
    }

    public function test_show_offers_the_right_actions_per_state(): void
    {
        $admin = $this->admin();
        $generated = $this->delivery();
        $cancelled = $this->delivery(['status' => EmailReadingDelivery::STATUS_CANCELLED]);
        $sending = $this->delivery(['status' => EmailReadingDelivery::STATUS_SENDING, 'claim_token' => 't', 'claimed_at' => now()]);
        $unfulfilled = $this->delivery(['status' => EmailReadingDelivery::STATUS_SENT, 'sent_at' => now(), 'fulfillment_error' => 'Shopify said no']);

        $this->actingAs($admin)->get(route('admin.email_readings.show', $generated))
            ->assertSee('Send Now')->assertSee('Cancel')->assertSee('Mark as sent manually')->assertDontSee('Reactivate');
        $this->actingAs($admin)->get(route('admin.email_readings.show', $cancelled))
            ->assertSee('Reactivate')->assertDontSee('Send Now');
        $this->actingAs($admin)->get(route('admin.email_readings.show', $sending))
            ->assertSee('being sent right now')->assertDontSee('Send Now')->assertDontSee('Mark as sent manually');
        $this->actingAs($admin)->get(route('admin.email_readings.show', $unfulfilled))
            ->assertSee('Retry Fulfilment')->assertSee('Shopify said no');
    }

    public function test_cancel_and_reactivate_actions(): void
    {
        $admin = $this->admin();
        $delivery = $this->delivery();

        $this->actingAs($admin)->post(route('admin.email_readings.cancel', $delivery))->assertSessionHas('success');
        $this->assertSame(EmailReadingDelivery::STATUS_CANCELLED, $delivery->fresh()->status);

        $this->actingAs($admin)->post(route('admin.email_readings.reactivate', $delivery))->assertSessionHas('success');
        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $delivery->fresh()->status);
    }

    public function test_actions_on_a_sending_delivery_are_rejected_with_a_clear_message(): void
    {
        $admin = $this->admin();
        $delivery = $this->delivery(['status' => EmailReadingDelivery::STATUS_SENDING, 'claim_token' => 't', 'claimed_at' => now()]);

        foreach (['cancel', 'send', 'reactivate', 'mark_sent'] as $action) {
            $this->actingAs($admin)->post(route("admin.email_readings.{$action}", $delivery))
                ->assertSessionHas('error', 'This reading is being sent right now. Try again in a minute.');
        }

        $this->actingAs($admin)->put(route('admin.email_readings.update', $delivery), $this->updatePayload($delivery, ['customer_name' => 'Changed']))
            ->assertSessionHas('error');
        $this->assertSame('Jane Doe', $delivery->fresh()->customer_name);

        $this->actingAs($admin)->delete(route('admin.email_readings.destroy', $delivery))->assertSessionHas('error');
        $this->assertNotNull(EmailReadingDelivery::find($delivery->id));
    }

    public function test_mark_sent_manually_fulfils_unless_skipped(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $first = $this->delivery();
        $second = $this->delivery();

        $this->actingAs($admin)->post(route('admin.email_readings.mark_sent', $first))->assertSessionHas('success');
        $this->actingAs($admin)->post(route('admin.email_readings.mark_sent', $second), ['skip_fulfilment' => '1'])->assertSessionHas('success');

        $this->assertTrue($first->fresh()->sent_manually);
        $this->assertSame(EmailReadingDelivery::STATUS_SENT, $second->fresh()->status);
        Queue::assertPushed(MarkReadingOrderFulfilledJob::class, 1);
    }

    public function test_retry_fulfilment_action(): void
    {
        Queue::fake();
        $delivery = $this->delivery(['status' => EmailReadingDelivery::STATUS_SENT, 'sent_at' => now(), 'fulfillment_error' => 'x']);

        $this->actingAs($this->admin())->post(route('admin.email_readings.retry_fulfillment', $delivery))->assertSessionHas('success');

        Queue::assertPushed(MarkReadingOrderFulfilledJob::class, 1);
    }

    public function test_editing_the_schedule_changes_when_it_sends_and_ignores_a_posted_status(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $delivery = $this->delivery(['scheduled_at' => now()->addDays(4)]);
        $newTime = now()->subHour()->startOfMinute();

        $this->actingAs($admin)->put(route('admin.email_readings.update', $delivery), $this->updatePayload($delivery, [
            'scheduled_at' => $newTime->format('Y-m-d\TH:i'),
            'status' => 'sent',
        ]))->assertSessionHas('success');

        $delivery->refresh();
        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $delivery->status);
        $this->assertTrue($delivery->scheduled_at->equalTo($newTime));
    }

    public function test_content_edits_are_saved_and_rescheduling_a_sent_reading_is_rejected(): void
    {
        $admin = $this->admin();
        $generated = $this->delivery();

        $this->actingAs($admin)->put(route('admin.email_readings.update', $generated), $this->updatePayload($generated, [
            'ai_response' => 'Edited reading body.',
            'customer_email' => 'new@example.com',
        ]))->assertSessionHas('success');
        $this->assertSame('Edited reading body.', $generated->fresh()->ai_response);
        $this->assertSame('new@example.com', $generated->fresh()->customer_email);

        $sent = $this->delivery(['status' => EmailReadingDelivery::STATUS_SENT, 'sent_at' => now()]);
        $this->actingAs($admin)->put(route('admin.email_readings.update', $sent), $this->updatePayload($sent, [
            'scheduled_at' => now()->addDays(9)->format('Y-m-d\TH:i'),
        ]))->assertSessionHas('error');
    }

    public function test_saving_the_response_sanitizes_the_html(): void
    {
        $delivery = $this->delivery();

        $this->actingAs($this->admin())->put(route('admin.email_readings.update', $delivery), $this->updatePayload($delivery, [
            'ai_response' => '<h2>Card One</h2><p onclick="x()">Body</p><script>steal()</script><a href="javascript:alert(1)">bad</a>',
        ]))->assertSessionHas('success');

        $saved = $delivery->fresh()->ai_response;
        $this->assertStringContainsString('<h2>Card One</h2>', $saved);
        $this->assertStringContainsString('<p>Body</p>', $saved);
        $this->assertStringNotContainsString('script', $saved);
        $this->assertStringNotContainsString('onclick', $saved);
        $this->assertStringNotContainsString('javascript:', $saved);
    }

    public function test_clearing_the_editor_removes_the_response_instead_of_keeping_a_blank_paragraph(): void
    {
        $delivery = $this->delivery();

        $this->actingAs($this->admin())->put(route('admin.email_readings.update', $delivery), $this->updatePayload($delivery, [
            'ai_response' => '<p>&nbsp;</p>',
        ]))->assertSessionHas('success');

        $this->assertNull($delivery->fresh()->ai_response);
    }

    public function test_a_response_edit_on_a_sending_delivery_is_rejected(): void
    {
        $delivery = $this->delivery(['status' => EmailReadingDelivery::STATUS_SENDING, 'claim_token' => 't', 'claimed_at' => now()]);

        $this->actingAs($this->admin())->put(route('admin.email_readings.update', $delivery), $this->updatePayload($delivery, [
            'ai_response' => '<p>Changed mid-send.</p>',
        ]))->assertSessionHas('error');

        $this->assertSame('Reading text.', $delivery->fresh()->ai_response);
    }

    public function test_the_show_page_renders_the_formatted_body(): void
    {
        $delivery = $this->delivery(['ai_response' => '<h2>Card One</h2><p>Spirit says yes.</p><script>steal()</script>']);

        $this->actingAs($this->admin())->get(route('admin.email_readings.show', $delivery))
            ->assertOk()
            ->assertSee('<h2>Card One</h2>', false)
            ->assertDontSee('steal()', false);
    }

    public function test_the_edit_page_uses_the_rich_text_editor_for_the_response(): void
    {
        $delivery = $this->delivery(['ai_response' => '<p>Editable body.</p>']);

        $this->actingAs($this->admin())->get(route('admin.email_readings.edit', $delivery))
            ->assertOk()
            ->assertSee('name="ai_response"', false)
            ->assertSee('data-rich-text', false)
            ->assertSee('&lt;p&gt;Editable body.&lt;/p&gt;', false);
    }

    public function test_regenerate_returns_html_for_the_editor(): void
    {
        OpenAI::fake([CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => "One.\n\nTwo."]]]])]);
        $delivery = $this->delivery();

        $this->actingAs($this->admin())->postJson(route('admin.email_readings.regenerate', $delivery))
            ->assertOk()
            ->assertJsonPath('content', "<p>One.</p>\n<p>Two.</p>");
    }

    public function test_saving_without_touching_the_schedule_never_counts_as_a_reschedule(): void
    {
        $admin = $this->admin();
        // Stored with seconds; the datetime-local form only carries minutes.
        $sent = $this->delivery([
            'status' => EmailReadingDelivery::STATUS_SENT,
            'sent_at' => now(),
            'scheduled_at' => now()->setTime(14, 3, 27),
        ]);
        $generated = $this->delivery(['scheduled_at' => now()->addDays(2)->setTime(9, 15, 42)]);
        $originalSchedule = $generated->scheduled_at->copy();

        $this->actingAs($admin)->put(route('admin.email_readings.update', $sent), $this->updatePayload($sent, ['customer_name' => 'Renamed']))
            ->assertSessionHas('success');
        $this->assertSame('Renamed', $sent->fresh()->customer_name);

        $this->actingAs($admin)->put(route('admin.email_readings.update', $generated), $this->updatePayload($generated, ['customer_name' => 'Renamed']))
            ->assertSessionHas('success');
        $this->assertTrue($generated->fresh()->scheduled_at->equalTo($originalSchedule), 'seconds must not be silently dropped');
    }

    public function test_campaign_pages_render_for_the_sending_state_and_webhook_events_page_renders(): void
    {
        $admin = $this->admin();
        $delivery = CampaignDelivery::create([
            'shopify_order_id' => 9001, 'shopify_line_item_id' => 4001, 'customer_email' => 'b@example.com',
            'status' => CampaignDelivery::STATUS_SENDING, 'claim_token' => 't', 'claimed_at' => now(),
        ]);
        ShopifyWebhookEvent::create([
            'endpoint' => 'campaign.paid', 'topic' => 'orders/paid', 'shopify_order_id' => 9001, 'payload' => [],
            'status' => ShopifyWebhookEvent::STATUS_FAILED, 'error' => 'boom', 'received_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('admin.campaign_deliveries.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.campaign_deliveries.show', $delivery))->assertOk()->assertSee('being sent right now');
        $this->actingAs($admin)->get(route('admin.campaign_deliveries.edit', $delivery))->assertOk();
        $this->actingAs($admin)->get(route('admin.webhook_events.index'))->assertOk()->assertSee('boom')->assertSee('Replay');
    }
}
