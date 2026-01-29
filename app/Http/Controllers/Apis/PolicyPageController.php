<?php

namespace App\Http\Controllers\Apis;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Facades\Shopify;
use App\Traits\ShopifyResponseFormatter;
use Illuminate\Support\Facades\Validator;

class PolicyPageController extends Controller
{
    use ShopifyResponseFormatter;

    /**
     * Get page details by handle (example: about-me)
     */
    public function getPolicyDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'handle' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->fail('Validation error.', $validator->errors());
        }

        // 🔑 Map slug → Shopify policy field
        $policyMap = [
            'privacy-policy'   => 'privacyPolicy',
            'terms-of-service' => 'termsOfService',
            'refund-policy'    => 'refundPolicy',
            'shipping-policy'  => 'shippingPolicy',
        ];

        $handle = $request->input('handle');

        if (!isset($policyMap[$handle])) {
            return $this->fail('Invalid policy handle');
        }

        try {
            $response = Shopify::query(
                'storefront',
                'policies/get_all_policies',
                []
            );

            $shop = data_get($response, 'data.shop');

            if (!$shop) {
                return $this->fail('Policies not found');
            }

            $policyKey = $policyMap[$handle];
            $policy = $shop[$policyKey] ?? null;

            if (!$policy) {
                return $this->fail('Requested policy not found');
            }

            return $this->success(
                'Policy details fetched successfully',
                [
                    'id'         => $policy['id'],
                    'handle'      => $handle,
                    'title'       => $policy['title'],
                    'url'         => $policy['url'],
                    'body'        => $policy['body'],
                ]
            );
        } catch (\Throwable $e) {
            return $this->fail('Failed to fetch policy details', $e->getMessage());
        }
    }
}
