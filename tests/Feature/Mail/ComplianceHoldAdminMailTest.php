<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Jobs\EmailReading\NotifyComplianceHoldJob;
use App\Mail\ComplianceHoldAdminMail;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ComplianceHoldAdminMailTest extends TestCase
{
    use RefreshDatabase;

    private function delivery(string $status = EmailReadingDelivery::STATUS_COMPLIANCE_HOLD): EmailReadingDelivery
    {
        $product = EmailReadingProduct::create([
            'shopify_product_id' => 111,
            'name' => 'Silver One Question',
            'slug' => 'silver-one',
            'questions_schema' => [],
            'prompt_template' => 'x',
            'email_subject' => 'Your reading',
        ]);

        return EmailReadingDelivery::create([
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => 1,
            'email_reading_product_id' => $product->id,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Jane Doe',
            'questions' => ['q1' => 'Do I have cancer?'],
            'status' => $status,
            'compliance_flagged_reason' => 'Medical diagnosis or prognosis: "do i have cancer"',
        ]);
    }

    public function test_the_alert_explains_the_hold_and_links_to_the_review_page(): void
    {
        $delivery = $this->delivery();

        $mail = new ComplianceHoldAdminMail($delivery);

        $this->assertStringContainsString('Compliance Hold', $mail->envelope()->subject);
        $this->assertStringContainsString('#9001', $mail->envelope()->subject);
        $html = $mail->render();
        $this->assertStringContainsString('do i have cancer', $html);
        $this->assertStringContainsString(route('admin.email_readings.show', $delivery), $html);
        $this->assertStringNotContainsString('Failed', $html);
    }

    public function test_the_job_emails_the_configured_admin_for_a_held_delivery(): void
    {
        Mail::fake();
        config(['email_reading.admin_notify_email' => 'ops@example.com']);
        $delivery = $this->delivery();

        (new NotifyComplianceHoldJob($delivery->id))->handle();

        Mail::assertSent(ComplianceHoldAdminMail::class, fn (ComplianceHoldAdminMail $mail): bool => $mail->hasTo('ops@example.com'));
    }

    public function test_the_job_stays_silent_once_the_hold_is_resolved(): void
    {
        Mail::fake();
        config(['email_reading.admin_notify_email' => 'ops@example.com']);
        $delivery = $this->delivery(EmailReadingDelivery::STATUS_CANCELLED);

        (new NotifyComplianceHoldJob($delivery->id))->handle();

        Mail::assertNothingSent();
    }
}
