<?php

namespace Tests\Feature\Api\V1;

use App\Mail\FreeReadingFormMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FreeReadingEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone_country_code' => '+91',
            'phone' => '9876543210',
        ], $overrides);
    }

    public function test_free_reading_form_stores_submission_and_notifies_admin(): void
    {
        Mail::fake();
        config()->set('mail.admin_email', 'admin@example.com');

        $response = $this->postJson('/api/v1/free-reading', $this->validPayload());

        $response->assertStatus(201);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('free_reading_submissions', [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone_country_code' => '+91',
            'phone' => '9876543210',
        ]);

        Mail::assertSent(FreeReadingFormMail::class, function ($mail) {
            return $mail->hasTo('admin@example.com');
        });
    }

    public function test_free_reading_form_persists_lead_even_when_admin_email_missing(): void
    {
        Mail::fake();
        config()->set('mail.admin_email', null);

        $response = $this->postJson('/api/v1/free-reading', $this->validPayload());

        $response->assertStatus(201);
        $this->assertDatabaseCount('free_reading_submissions', 1);
        Mail::assertNothingSent();
    }

    public function test_free_reading_form_validates_required_fields(): void
    {
        $response = $this->postJson('/api/v1/free-reading', []);

        $response->assertStatus(422);
        $response->assertJsonPath('meta.error_code', 'VALIDATION_ERROR');
        $this->assertDatabaseCount('free_reading_submissions', 0);
    }

    public function test_free_reading_form_rejects_invalid_email(): void
    {
        $response = $this->postJson('/api/v1/free-reading', $this->validPayload([
            'email' => 'not-an-email',
        ]));

        $response->assertStatus(422);
        $this->assertDatabaseCount('free_reading_submissions', 0);
    }
}
