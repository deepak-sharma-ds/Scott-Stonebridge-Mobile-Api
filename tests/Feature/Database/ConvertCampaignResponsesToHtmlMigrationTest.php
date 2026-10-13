<?php

namespace Tests\Feature\Database;

use App\Models\CampaignProduct;
use App\Models\CampaignProductResponse;
use App\Models\MarketingCampaign;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConvertCampaignResponsesToHtmlMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_12_160000_convert_campaign_responses_to_html.php');
    }

    private function insert(string $body): int
    {
        static $product = 500;

        $campaign = MarketingCampaign::firstOrCreate(['campaign_key' => 'spring'], ['name' => 'Spring', 'status' => MarketingCampaign::STATUS_ACTIVE]);
        $campaignProduct = CampaignProduct::create(['marketing_campaign_id' => $campaign->id, 'shopify_product_id' => $product++]);

        return DB::table('campaign_product_responses')->insertGetId([
            'campaign_product_id' => $campaignProduct->id,
            'source' => CampaignProductResponse::SOURCE_MANUAL,
            'body' => $body,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_plain_text_responses_become_paragraph_html(): void
    {
        $id = $this->insert("First & one.\n\nSecond\nline.");

        $this->migration()->up();

        $this->assertSame('<p>First &amp; one.</p><p>Second<br />'."\n".'line.</p>', DB::table('campaign_product_responses')->find($id)->body);
    }

    public function test_responses_already_in_html_are_left_alone_and_a_second_run_changes_nothing(): void
    {
        $html = $this->insert('<h2>Card</h2><p>Body</p>');
        $plain = $this->insert("One.\n\nTwo.");

        $this->migration()->up();
        $first = DB::table('campaign_product_responses')->find($plain)->body;
        $this->migration()->up();

        $this->assertSame('<h2>Card</h2><p>Body</p>', DB::table('campaign_product_responses')->find($html)->body);
        $this->assertSame($first, DB::table('campaign_product_responses')->find($plain)->body);
    }
}
