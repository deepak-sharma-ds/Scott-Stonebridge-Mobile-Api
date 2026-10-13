<?php

namespace Database\Seeders;

use App\Models\EmailReadingProduct;
use App\Support\ReadingPromptTemplates;
use Illuminate\Database\Seeder;

/**
 * Seeds the 8 email reading products from the spec sheet.
 *
 * NOTE: shopify_product_id values are PLACEHOLDERS (90000000000001..8). The
 * Shopify-side products are still in development. Update each row's
 * `shopify_product_id` once real Shopify IDs exist:
 *
 *   EmailReadingProduct::where('slug','future-two-question')
 *       ->update(['shopify_product_id' => <real_id>]);
 *
 * One real ID is already known from the sample webhook payload:
 *   future-two-question = 7939025469614
 *
 * The 8 customer-facing products get their modality-specific `prompt_template`
 * and `max_tokens` from App\Support\ReadingPromptTemplates (shared with the
 * data migration that upgrades existing rows). `test-product` keeps its own
 * baseline. Admins can still edit any row's prompt afterwards.
 */
class EmailReadingProductSeeder extends Seeder
{
    public function run(): void
    {
        $prompts = collect(ReadingPromptTemplates::all())->keyBy('shopify_product_id');

        foreach ($this->products() as $row) {
            if ($definition = $prompts->get($row['shopify_product_id'])) {
                $row['prompt_template'] = $definition['prompt_template'];
                $row['max_tokens'] = $definition['max_tokens'];
            }

            EmailReadingProduct::updateOrCreate(
                ['slug' => $row['slug']],
                $row,
            );
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function products(): array
    {
        return [
            [
                'shopify_product_id' => 7939025469614,
                'slug' => 'future-two-question-email-reading',
                'name' => 'Future Two Question Email Reading',
                'questions_schema' => [
                    ['key' => 'Q1', 'label' => 'What do you want to know? The more information you provide the better.', 'required' => true],
                    ['key' => 'Q2', 'label' => 'What do you want to know? The more information you provide the better.', 'required' => true],
                ],
                'email_subject' => 'Your Future Two Question Reading from Scott Stonebridge',
                'is_active' => true,
            ],
            [
                'shopify_product_id' => 7936010223790,
                'slug' => 'gold-three-question-email-reading',
                'name' => 'Gold Three Question Email Reading',
                'questions_schema' => [
                    ['key' => 'Q1', 'label' => 'What do you want to know? The more information you provide the better.', 'required' => true],
                    ['key' => 'Q2', 'label' => 'What do you want to know? The more information you provide the better.', 'required' => true],
                    ['key' => 'Q3', 'label' => 'What do you want to know? The more information you provide the better.', 'required' => true],
                ],
                'email_subject' => 'Your Gold Three Question Reading from Scott Stonebridge',
                'is_active' => true,
            ],
            [
                'shopify_product_id' => 7936013893806,
                'slug' => 'love-relationships-two-question-email-reading',
                'name' => 'Love & Relationships Two Question Email Reading',
                'questions_schema' => [
                    ['key' => 'Q1', 'label' => 'Please provide NAME & DATE OF BIRTH & connection MUM, UNCLE, FRIEND etc…', 'required' => true],
                    ['key' => 'Q2', 'label' => 'What do you want to know? The more information you provide the better.', 'required' => true],
                    ['key' => 'Q3', 'label' => 'What do you want to know? The more information you provide the better.', 'required' => true],
                ],
                'email_subject' => 'Your Love & Relationships Reading from Scott Stonebridge',
                'is_active' => true,
            ],
            [
                'shopify_product_id' => 7936014680238,
                'slug' => 'messages-from-heaven-two-question-email-reading',
                'name' => 'Messages From Heaven Two Question Email Reading',
                'questions_schema' => [
                    ['key' => 'Q1', 'label' => 'Please provide NAME & connection MUM, UNCLE, FRIEND etc… or put General', 'required' => true],
                    ['key' => 'Q2', 'label' => 'What do you want to know? The more information you provide the better.', 'required' => true],
                    ['key' => 'Q3', 'label' => 'What do you want to know? The more information you provide the better.', 'required' => true],
                ],
                'email_subject' => 'Your Messages From Heaven Reading from Scott Stonebridge',
                'is_active' => true,
            ],
            [
                'shopify_product_id' => 7936011337902,
                'slug' => 'platinum-five-question-email-reading',
                'name' => 'Platinum Five Question Email Reading',
                'questions_schema' => [
                    ['key' => 'Q1', 'label' => 'What do you want to know? The more information you provide the better.', 'required' => true],
                    ['key' => 'Q2', 'label' => 'What do you want to know? The more information you provide the better.', 'required' => true],
                    ['key' => 'Q3', 'label' => 'What do you want to know? The more information you provide the better.', 'required' => true],
                    ['key' => 'Q4', 'label' => 'What do you want to know? The more information you provide the better.', 'required' => true],
                    ['key' => 'Q5', 'label' => 'What do you want to know? The more information you provide the better.', 'required' => true],
                ],
                'email_subject' => 'Your Platinum Five Question Reading from Scott Stonebridge',
                'is_active' => true,
            ],
            [
                'shopify_product_id' => 7936008421550,
                'slug' => 'silver-one-question-email-reading',
                'name' => 'Silver One Question Email Reading',
                'questions_schema' => [
                    ['key' => 'Q1', 'label' => 'What do you want to know? The more information you provide the better.', 'required' => true],
                ],
                'email_subject' => 'Your Silver One Question Reading from Scott Stonebridge',
                'is_active' => true,
            ],
            [
                'shopify_product_id' => 8274431672494,
                'slug' => 'you-me-love-reading',
                'name' => 'You & Me Love Reading',
                'questions_schema' => [
                    ['key' => 'Q1', 'label' => 'Please provide NAME & DATE OF BIRTH of the person to focus on.', 'required' => true],
                    ['key' => 'Q2', 'label' => 'Please tell me the connection this is a LOVE INTEREST/PARTNER/EX Etc?', 'required' => true],
                    ['key' => 'Q3', 'label' => 'What do you want to know? The more information you provide the better.', 'required' => true],
                ],
                'email_subject' => 'Your You & Me Love Reading from Scott Stonebridge',
                'is_active' => true,
            ],
            [
                'shopify_product_id' => 8500884570286,
                'slug' => '12-month-deep-astrology-outlook',
                'name' => 'In-Depth 12 Month Year Ahead Astrology Outlook',
                'questions_schema' => [
                    ['key' => 'Q1', 'label' => 'Please provide your DOB', 'required' => true],
                    ['key' => 'Q2', 'label' => 'Please provide your CITY / PLACE OF BIRTH', 'required' => true],
                    ['key' => 'Q3', 'label' => 'Please provide your TIME OF BIRTH if known? Or put n/a', 'required' => true],
                ],
                'email_subject' => 'Your 12 Month Year Ahead Astrology Outlook from Scott Stonebridge',
                'is_active' => true,
            ],
            [
                'shopify_product_id' => 15644688384383,
                'slug' => 'test-product',
                'name' => 'Test Product',
                'questions_schema' => [
                    ['key' => 'Q1', 'label' => 'Please provide your TIME OF BIRTH if known? Or put n/a', 'required' => true],
                    ['key' => 'Q2', 'label' => 'Please provide your CITY / PLACE OF BIRTH', 'required' => true],
                    ['key' => 'Q3', 'label' => 'Please provide your DOB', 'required' => true],
                    ['key' => 'Q4', 'label' => 'Please provide NAME & DATE OF BIRTH & connection MUM, UNCLE, FRIEND etc….', 'required' => true],
                    ['key' => 'Q5', 'label' => 'What do you want to know? The more information you provide the better.', 'required' => true],
                ],
                'email_subject' => 'Your Test Product Reading from Scott Stonebridge',
                'prompt_template' => "Customer: {{ \$customer_name }}.\n\nTest product reading.\n\nTime of birth: {{ \$q1 ?? 'n/a' }}\nPlace/City of birth: {{ \$q2 ?? 'n/a' }}\nDate of birth: {{ \$q3 ?? 'n/a' }}\nConnection details (Name, DOB, connection): {{ \$q4 ?? 'n/a' }}\nWhat the customer wants to know: {{ \$q5 ?? 'n/a' }}\n\nBased on the information provided, prepare a detailed reading. Organise the outlook month by month over the next 12 months.",
                'max_tokens' => 2400,
                'is_active' => true,
            ],
        ];
    }
}
