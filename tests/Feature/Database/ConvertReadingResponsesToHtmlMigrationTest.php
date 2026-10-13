<?php

namespace Tests\Feature\Database;

use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConvertReadingResponsesToHtmlMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_12_150000_convert_reading_responses_to_html.php');
    }

    private function insert(?string $response): int
    {
        static $line = 7000;

        return DB::table('email_reading_deliveries')->insertGetId([
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => $line++,
            'email_reading_product_id' => EmailReadingProduct::firstOrCreate(['shopify_product_id' => 111], ['name' => 'Reading', 'slug' => 'reading', 'questions_schema' => [], 'prompt_template' => 'x', 'email_subject' => 'x'])->id,
            'customer_email' => 'buyer@example.com',
            'questions' => '[]',
            'status' => EmailReadingDelivery::STATUS_GENERATED,
            'ai_response' => $response,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_plain_text_responses_become_paragraph_html(): void
    {
        $id = $this->insert("First & one.\n\nSecond\nline.");

        $this->migration()->up();

        $this->assertSame('<p>First &amp; one.</p><p>Second<br />'."\n".'line.</p>', DB::table('email_reading_deliveries')->find($id)->ai_response);
    }

    public function test_responses_already_in_html_and_empty_ones_are_left_alone(): void
    {
        $html = $this->insert('<h2>Card</h2><p>Body</p>');
        $empty = $this->insert(null);

        $this->migration()->up();

        $this->assertSame('<h2>Card</h2><p>Body</p>', DB::table('email_reading_deliveries')->find($html)->ai_response);
        $this->assertNull(DB::table('email_reading_deliveries')->find($empty)->ai_response);
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $id = $this->insert("One.\n\nTwo.");

        $this->migration()->up();
        $first = DB::table('email_reading_deliveries')->find($id)->ai_response;
        $this->migration()->up();

        $this->assertSame($first, DB::table('email_reading_deliveries')->find($id)->ai_response);
    }
}
