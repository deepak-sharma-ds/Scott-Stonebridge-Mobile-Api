<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncShopifyMarketingConsent extends Command
{
    protected $signature = 'shopify:sync-marketing-consent';

    protected $description = 'Sync existing Shopify marketing consent to Klaviyo';

    public function handle(KlaviyoService $klaviyo)
    {
        $shop = config('services.shopify.shop_domain');
        $token = config('services.shopify.admin_token');

        $url = "https://{$shop}/admin/api/2025-01/customers.json?limit=250";

        do {

            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $token,
            ])->get($url);

            if ($response->failed()) {
                $this->error('Failed fetching customers');
                return Command::FAILURE;
            }

            $customers = $response->json('customers', []);

            foreach ($customers as $customer) {

                /*
                 * EMAIL CONSENT
                 */
                $email = $customer['email'] ?? null;

                $emailState =
                    $customer['email_marketing_consent']['state']
                    ?? null;

                if ($email && $emailState) {

                    if ($emailState === 'subscribed') {
                        $klaviyo->subscribe($email, 'email');
                    }

                    if ($emailState === 'unsubscribed') {
                        $klaviyo->unsubscribe($email, 'email');
                    }

                    $this->info(
                        "Email {$email} => {$emailState}"
                    );
                }

                /*
                 * SMS CONSENT
                 */
                $phone =
                    $customer['phone']
                    ?? $customer['default_address']['phone']
                    ?? null;

                $smsState =
                    $customer['sms_marketing_consent']['state']
                    ?? null;

                if ($phone && $smsState) {

                    if ($smsState === 'subscribed') {
                        $klaviyo->subscribe($phone, 'sms');
                    }

                    if ($smsState === 'unsubscribed') {
                        $klaviyo->unsubscribe($phone, 'sms');
                    }

                    $this->info(
                        "SMS {$phone} => {$smsState}"
                    );
                }
            }

            $link = $response->header('Link');

            $nextPage = null;

            if (
                $link &&
                preg_match('/<([^>]+)>; rel="next"/', $link, $matches)
            ) {
                $nextPage = $matches[1];
            }

            $url = $nextPage;

        } while ($url);

        $this->info('Marketing consent sync completed.');

        return Command::SUCCESS;
    }
}
