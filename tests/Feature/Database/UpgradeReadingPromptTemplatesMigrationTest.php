<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\EmailReadingProduct;
use App\Support\ReadingPromptTemplates;
use Database\Seeders\EmailReadingProductSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpgradeReadingPromptTemplatesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const SILVER_ID = 7936008421550;

    private const GOLD_ID = 7936010223790;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_13_150000_upgrade_reading_prompt_templates.php');
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function product(int $shopifyId, string $slug, array $attributes = []): EmailReadingProduct
    {
        return EmailReadingProduct::create(array_merge([
            'shopify_product_id' => $shopifyId,
            'name' => 'Existing '.$slug,
            'slug' => $slug,
            'questions_schema' => [['key' => 'Q1', 'label' => 'Q', 'required' => true]],
            'prompt_template' => "Customer: {{ \$customer_name }}.\n\nSilver-tier single question reading.",
            'email_subject' => 'Custom subject',
            'max_tokens' => 1200,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @return array{shopify_product_id:int,slug:string,max_tokens:int,prompt_template:string}
     */
    private function definition(int $shopifyId): array
    {
        return collect(ReadingPromptTemplates::all())->firstWhere('shopify_product_id', $shopifyId);
    }

    public function test_a_bare_bones_template_is_upgraded_and_other_fields_are_untouched(): void
    {
        $product = $this->product(self::SILVER_ID, 'catalog-sync-slug', ['formatting_contract' => 'KEEP ME', 'is_automation_enabled' => true]);

        $this->migration()->up();

        $product->refresh();
        $definition = $this->definition(self::SILVER_ID);
        $this->assertSame($definition['prompt_template'], $product->prompt_template);
        $this->assertSame($definition['max_tokens'], $product->max_tokens);
        $this->assertSame('Existing catalog-sync-slug', $product->name);
        $this->assertSame('catalog-sync-slug', $product->slug);
        $this->assertSame('Custom subject', $product->email_subject);
        $this->assertSame('KEEP ME', $product->formatting_contract);
        $this->assertTrue($product->is_active);
        $this->assertTrue($product->is_automation_enabled);
    }

    public function test_a_larger_token_budget_set_by_an_admin_is_never_lowered(): void
    {
        $product = $this->product(self::SILVER_ID, 'silver', ['max_tokens' => 6000]);

        $this->migration()->up();

        $this->assertSame(6000, $product->fresh()->max_tokens);
    }

    public function test_an_unset_token_budget_is_filled_in(): void
    {
        $product = $this->product(self::SILVER_ID, 'silver', ['max_tokens' => 0]);

        $this->migration()->up();

        $this->assertSame($this->definition(self::SILVER_ID)['max_tokens'], $product->fresh()->max_tokens);
    }

    public function test_an_admin_customised_long_template_is_preserved(): void
    {
        $custom = str_repeat('Scott wrote this detailed prompt himself. ', 30);
        $product = $this->product(self::SILVER_ID, 'silver', ['prompt_template' => $custom, 'max_tokens' => 1200]);

        $this->migration()->up();

        $product->refresh();
        $this->assertSame($custom, $product->prompt_template);
        $this->assertSame(1200, $product->max_tokens);
    }

    public function test_unknown_products_are_untouched_and_missing_products_are_not_created(): void
    {
        $other = $this->product(999999, 'something-else');

        $this->migration()->up();

        $this->assertSame("Customer: {{ \$customer_name }}.\n\nSilver-tier single question reading.", $other->fresh()->prompt_template);
        $this->assertSame(1, EmailReadingProduct::count());
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $product = $this->product(self::GOLD_ID, 'gold');

        $this->migration()->up();
        $afterFirst = $product->fresh()->only(['prompt_template', 'max_tokens']);
        $this->migration()->up();

        $this->assertSame($afterFirst, $product->fresh()->only(['prompt_template', 'max_tokens']));
    }

    public function test_the_seeder_gives_fresh_installs_the_same_upgraded_prompts(): void
    {
        $this->seed(EmailReadingProductSeeder::class);

        foreach (ReadingPromptTemplates::all() as $definition) {
            $product = EmailReadingProduct::where('shopify_product_id', $definition['shopify_product_id'])->first();

            $this->assertNotNull($product, $definition['slug']);
            $this->assertSame($definition['prompt_template'], $product->prompt_template);
            $this->assertSame($definition['max_tokens'], $product->max_tokens);
        }
        $this->assertNotNull(EmailReadingProduct::where('slug', 'test-product')->first());
    }
}
