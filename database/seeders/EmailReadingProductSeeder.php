<?php

namespace Database\Seeders;

use App\Models\EmailReadingProduct;
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
 * Each row's `prompt_template` is a baseline draft. The user/admin should
 * replace it with the final prompt before go-live (no code change needed —
 * edit the row).
 */
class EmailReadingProductSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->products() as $row) {
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
                'shopify_product_id' => 90000000000001,
                'slug' => 'future-two-question',
                'name' => 'Future Two Question Email Reading',
                'questions_schema' => [
                    ['key' => 'future_q1', 'label' => 'Question 1', 'required' => true],
                    ['key' => 'future_q2', 'label' => 'Question 2', 'required' => true],
                ],
                'email_subject' => 'Your Future Two Question Reading from Scott Stonebridge',
                'prompt_template' => "Customer name: {{ \$customer_name }}.\n\nProvide a Future-focused email reading addressing these two questions:\n\nQuestion 1: {{ \$future_q1 ?? \$q1 ?? '' }}\nQuestion 2: {{ \$future_q2 ?? \$q2 ?? '' }}",
                'max_tokens' => 1500,
                'is_active' => true,
            ],
            [
                'shopify_product_id' => 90000000000002,
                'slug' => 'gold-three-question',
                'name' => 'Gold Three Question Email Reading',
                'questions_schema' => [
                    ['key' => 'gold_q1', 'label' => 'Question 1', 'required' => true],
                    ['key' => 'gold_q2', 'label' => 'Question 2', 'required' => true],
                    ['key' => 'gold_q3', 'label' => 'Question 3', 'required' => true],
                ],
                'email_subject' => 'Your Gold Three Question Reading from Scott Stonebridge',
                'prompt_template' => "Customer: {{ \$customer_name }}.\n\nGold-tier three question reading.\n\nQ1: {{ \$gold_q1 ?? \$q1 ?? '' }}\nQ2: {{ \$gold_q2 ?? \$q2 ?? '' }}\nQ3: {{ \$gold_q3 ?? \$q3 ?? '' }}",
                'max_tokens' => 1800,
                'is_active' => true,
            ],
            [
                'shopify_product_id' => 90000000000003,
                'slug' => 'love-relationships-two-question',
                'name' => 'Love & Relationships Two Question Email Reading',
                'questions_schema' => [
                    ['key' => 'love_focus', 'label' => 'Person details (Name, DOB, connection)', 'required' => true],
                    ['key' => 'love_q1', 'label' => 'Question 1', 'required' => true],
                    ['key' => 'love_q2', 'label' => 'Question 2', 'required' => true],
                ],
                'email_subject' => 'Your Love & Relationships Reading from Scott Stonebridge',
                'prompt_template' => "Customer: {{ \$customer_name }}.\n\nLove & relationships reading focused on: {{ \$love_focus ?? \$q1 ?? '' }}\n\nQuestion 1: {{ \$love_q1 ?? \$q2 ?? '' }}\nQuestion 2: {{ \$love_q2 ?? \$q3 ?? '' }}",
                'max_tokens' => 1600,
                'is_active' => true,
            ],
            [
                'shopify_product_id' => 90000000000004,
                'slug' => 'messages-from-heaven-two-question',
                'name' => 'Messages From Heaven Two Question Email Reading',
                'questions_schema' => [
                    ['key' => 'spirit_focus', 'label' => 'Person in spirit (Name & connection, or General)', 'required' => true],
                    ['key' => 'spirit_q1', 'label' => 'Question 1', 'required' => true],
                    ['key' => 'spirit_q2', 'label' => 'Question 2', 'required' => true],
                ],
                'email_subject' => 'Your Messages From Heaven Reading from Scott Stonebridge',
                'prompt_template' => "Customer: {{ \$customer_name }}.\n\nMessages from heaven (mediumship) reading.\nFocus: {{ \$spirit_focus ?? \$q1 ?? '' }}\n\nQuestion 1: {{ \$spirit_q1 ?? \$q2 ?? '' }}\nQuestion 2: {{ \$spirit_q2 ?? \$q3 ?? '' }}",
                'max_tokens' => 1600,
                'is_active' => true,
            ],
            [
                'shopify_product_id' => 90000000000005,
                'slug' => 'platinum-five-question',
                'name' => 'Platinum Five Question Email Reading',
                'questions_schema' => [
                    ['key' => 'platinum_q1', 'label' => 'Question 1', 'required' => true],
                    ['key' => 'platinum_q2', 'label' => 'Question 2', 'required' => true],
                    ['key' => 'platinum_q3', 'label' => 'Question 3', 'required' => true],
                    ['key' => 'platinum_q4', 'label' => 'Question 4', 'required' => true],
                    ['key' => 'platinum_q5', 'label' => 'Question 5', 'required' => true],
                ],
                'email_subject' => 'Your Platinum Five Question Reading from Scott Stonebridge',
                'prompt_template' => "Customer: {{ \$customer_name }}.\n\nPlatinum-tier five question reading.\n\nQ1: {{ \$platinum_q1 ?? \$q1 ?? '' }}\nQ2: {{ \$platinum_q2 ?? \$q2 ?? '' }}\nQ3: {{ \$platinum_q3 ?? \$q3 ?? '' }}\nQ4: {{ \$platinum_q4 ?? \$q4 ?? '' }}\nQ5: {{ \$platinum_q5 ?? \$q5 ?? '' }}",
                'max_tokens' => 2200,
                'is_active' => true,
            ],
            [
                'shopify_product_id' => 90000000000006,
                'slug' => 'silver-one-question',
                'name' => 'Silver One Question Email Reading',
                'questions_schema' => [
                    ['key' => 'silver_q1', 'label' => 'Question 1', 'required' => true],
                ],
                'email_subject' => 'Your Silver One Question Reading from Scott Stonebridge',
                'prompt_template' => "Customer: {{ \$customer_name }}.\n\nSilver-tier single question reading.\n\nQuestion: {{ \$silver_q1 ?? \$q1 ?? '' }}",
                'max_tokens' => 1200,
                'is_active' => true,
            ],
            [
                'shopify_product_id' => 8274431672494,
                'slug' => 'you-me-love-reading',
                'name' => 'You & Me Love Reading',
                'questions_schema' => [
                    ['key' => 'partner_details', 'label' => 'Partner name & DOB', 'required' => true],
                    ['key' => 'connection', 'label' => 'Connection (Love Interest / Partner / Ex / etc.)', 'required' => true],
                    ['key' => 'love_question', 'label' => 'Question', 'required' => true],
                ],
                'email_subject' => 'Your You & Me Love Reading from Scott Stonebridge',
                'prompt_template' => "Customer: {{ \$customer_name }}.\n\nYou & Me love reading.\nPartner details: {{ \$partner_details ?? \$q1 ?? '' }}\nConnection: {{ \$connection ?? \$q2 ?? '' }}\nQuestion: {{ \$love_question ?? \$q3 ?? '' }}",
                'max_tokens' => 1600,
                'is_active' => true,
            ],
            [
                'shopify_product_id' => 90000000000008,
                'slug' => 'in-depth-12-month-astrology',
                'name' => 'In-Depth 12 Month Year Ahead Astrology Outlook',
                'questions_schema' => [
                    ['key' => 'dob', 'label' => 'Date of birth', 'required' => true],
                    ['key' => 'birth_city', 'label' => 'City / Place of birth', 'required' => true],
                    ['key' => 'birth_time', 'label' => 'Time of birth (or n/a)', 'required' => true],
                ],
                'email_subject' => 'Your 12 Month Year Ahead Astrology Outlook from Scott Stonebridge',
                'prompt_template' => "Customer: {{ \$customer_name }}.\n\nProvide an in-depth 12-month year-ahead astrology outlook.\nDOB: {{ \$dob ?? \$q1 ?? '' }}\nPlace of birth: {{ \$birth_city ?? \$q2 ?? '' }}\nTime of birth: {{ \$birth_time ?? \$q3 ?? 'n/a' }}\n\nOrganise the outlook month by month over the next 12 months.",
                'max_tokens' => 2400,
                'is_active' => true,
            ],
        ];
    }
}
