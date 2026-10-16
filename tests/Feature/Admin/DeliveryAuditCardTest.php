<?php

namespace Tests\Feature\Admin;

use App\Models\CampaignDelivery;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeliveryAuditCardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string,array{0:string}>
     */
    public static function deliveryKinds(): array
    {
        return ['reading' => ['reading'], 'campaign' => ['campaign']];
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function delivery(string $kind, array $overrides = []): Model
    {
        $base = [
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => 501,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Jane Doe',
            'status' => 'sent',
        ];

        if ($kind === 'campaign') {
            return CampaignDelivery::create(array_merge($base, $overrides));
        }

        $product = EmailReadingProduct::first() ?? EmailReadingProduct::create([
            'shopify_product_id' => 111,
            'name' => 'Reading',
            'slug' => 'reading',
            'questions_schema' => [],
            'prompt_template' => 'x',
        ]);

        return EmailReadingDelivery::create(array_merge($base, ['email_reading_product_id' => $product->id, 'questions' => []], $overrides));
    }

    private function showUrl(string $kind, Model $delivery): string
    {
        return $kind === 'campaign'
            ? route('admin.campaign_deliveries.show', $delivery)
            : route('admin.email_readings.show', $delivery);
    }

    private function show(string $kind, Model $delivery)
    {
        return $this->actingAs(User::factory()->create())->get($this->showUrl($kind, $delivery));
    }

    #[DataProvider('deliveryKinds')]
    public function test_it_shows_google_smtp_proof_and_the_message_id(string $kind): void
    {
        $delivery = $this->delivery($kind, [
            'message_id' => 'abc123@scottstonebridge.com',
            'smtp_response' => '250 2.0.0 OK  1760000000 x.1 - gsmtp',
            'dispatched_at' => '2026-10-14 12:30:45',
        ]);

        $this->show($kind, $delivery)->assertOk()
            ->assertSee('Delivery &amp; Legal Audit Trail', false)
            ->assertSee('Delivered (250 OK)')
            ->assertSee('abc123@scottstonebridge.com')
            ->assertSee('250 2.0.0 OK  1760000000 x.1 - gsmtp')
            ->assertSee('14 Oct 2026 12:30:45');
    }

    #[DataProvider('deliveryKinds')]
    public function test_a_delivery_without_smtp_acceptance_reads_as_pending_or_sent(string $kind): void
    {
        $delivery = $this->delivery($kind, ['status' => 'pending']);

        $this->show($kind, $delivery)->assertOk()
            ->assertSee('Pending / Sent')
            ->assertDontSee('Delivered (250 OK)');

        $local = $this->delivery($kind, ['shopify_line_item_id' => 502, 'message_id' => 'local@x', 'smtp_response' => 'transport:array']);

        $this->show($kind, $local)->assertOk()
            ->assertSee('Pending / Sent')
            ->assertSee('transport:array');
    }

    #[DataProvider('deliveryKinds')]
    public function test_an_unopened_delivery_is_shown_as_unopened(string $kind): void
    {
        $delivery = $this->delivery($kind);

        $this->show($kind, $delivery)->assertOk()
            ->assertSee('Unopened')
            ->assertSee('Not opened yet');
    }

    #[DataProvider('deliveryKinds')]
    public function test_a_direct_open_shows_first_last_and_count(string $kind): void
    {
        $delivery = $this->delivery($kind, [
            'opened_at' => '2026-10-15 09:00:00',
            'last_opened_at' => '2026-10-16 18:45:00',
            'open_count' => 4,
            'opened_client_type' => 'direct',
            'opened_ip' => '81.2.69.142',
        ]);

        $this->show($kind, $delivery)->assertOk()
            ->assertSee('Opened (Direct)')
            ->assertSee('15 Oct 2026 09:00:00')
            ->assertSee('16 Oct 2026 18:45:00')
            ->assertSee('81.2.69.142')
            ->assertSeeInOrder(['Total opens', '4'])
            ->assertDontSee('Opened (Proxy)');
    }

    #[DataProvider('deliveryKinds')]
    public function test_a_proxy_open_explains_the_privacy_proxy(string $kind): void
    {
        $delivery = $this->delivery($kind, [
            'opened_at' => '2026-10-15 09:00:00',
            'last_opened_at' => '2026-10-15 09:00:00',
            'open_count' => 1,
            'opened_client_type' => 'proxy',
        ]);

        $this->show($kind, $delivery)->assertOk()
            ->assertSee('Opened (Proxy)')
            ->assertSee('Apple Mail Privacy Protection')
            ->assertSee('Google Image Proxy')
            ->assertDontSee('Opened (Direct)');
    }

    #[DataProvider('deliveryKinds')]
    public function test_send_history_is_listed_oldest_first_when_the_email_was_resent(string $kind): void
    {
        $delivery = $this->delivery($kind, [
            'message_id' => 'third@scottstonebridge.com',
            'smtp_response' => '250 OK third',
            'send_history' => [
                ['dispatched_at' => '2026-10-01 09:30:00', 'message_id' => 'first@scottstonebridge.com', 'smtp_response' => '250 2.0.0 OK first'],
                ['dispatched_at' => '2026-10-08 10:00:00', 'message_id' => 'second@scottstonebridge.com', 'smtp_response' => '250 2.0.0 OK second'],
            ],
        ]);

        $this->show($kind, $delivery)->assertOk()
            ->assertSee('Send History')
            ->assertSeeInOrder(['first@scottstonebridge.com', '250 2.0.0 OK first', 'second@scottstonebridge.com', '250 2.0.0 OK second'])
            ->assertSee('01 Oct 2026 09:30:00');
    }

    #[DataProvider('deliveryKinds')]
    public function test_the_send_history_section_is_hidden_when_never_resent(string $kind): void
    {
        $delivery = $this->delivery($kind, ['message_id' => 'only@x', 'smtp_response' => '250 OK']);

        $this->show($kind, $delivery)->assertOk()->assertDontSee('Send History');
    }

    #[DataProvider('deliveryKinds')]
    public function test_untrusted_smtp_text_is_escaped(string $kind): void
    {
        $delivery = $this->delivery($kind, ['message_id' => '<script>x()</script>', 'smtp_response' => '250 <b>bold</b>']);

        $this->show($kind, $delivery)->assertOk()
            ->assertDontSee('<script>x()</script>', false)
            ->assertDontSee('<b>bold</b>', false);
    }

    #[DataProvider('deliveryKinds')]
    public function test_only_a_real_250_reply_counts_as_delivered(string $kind): void
    {
        $failed = $this->delivery($kind, ['message_id' => 'f@x', 'smtp_response' => '550 5.1.1 User unknown; reference 250918']);
        $this->show($kind, $failed)->assertOk()->assertDontSee('Delivered (250 OK)')->assertSee('Pending / Sent');

        $accepted = $this->delivery($kind, ['shopify_line_item_id' => 502, 'message_id' => 'a@x', 'smtp_response' => '250 2.0.0 OK  1760000000 abc.512 - gsmtp']);
        $this->show($kind, $accepted)->assertOk()->assertSee('Delivered (250 OK)');
    }

    #[DataProvider('deliveryKinds')]
    public function test_a_sent_delivery_without_proof_explains_why(string $kind): void
    {
        $delivery = $this->delivery($kind);

        $this->show($kind, $delivery)->assertOk()->assertSee('No delivery proof was recorded for this send');

        $pending = $this->delivery($kind, ['shopify_line_item_id' => 502, 'status' => 'pending']);
        $this->show($kind, $pending)->assertOk()->assertDontSee('No delivery proof was recorded');
    }
}
