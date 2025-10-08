<?php

namespace App\Http\Controllers\Apis;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use App\Services\APIShopifyService;
use Illuminate\Support\Facades\Validator;

class HomeController extends Controller
{
    protected $shopify;

    public function __construct(APIShopifyService $shopify)
    {
        $this->shopify = $shopify;
    }

    // public function home()
    // {
    //     $data = $this->shopify->getHomePageSections();

    //     if (isset($data['errors'])) {
    //         return response()->json(['error' => $data['errors']], 500);
    //     }

    //     $sections = $data['sections'] ?? [];

    //     // Replace all shopify image paths with full URLs recursively
    //     $this->replaceShopifyImagePaths($sections);

    //     $banners = [];
    //     foreach ($sections as $section) {
    //         if (($section['type'] ?? '') === 'slide-show') {
    //             $banners[] = $section;
    //         }
    //     }

    //     return response()->json([
    //         'sections' => $sections,
    //         'banners' => $banners,
    //     ]);
    // }


    public function home()
    {
        // 1. Get Home Page Sections
        $data = $this->shopify->getHomePageSections();
        $mainMenu = $this->shopify->getMenuByHandle('main-menu');
        $footerMenu = $this->shopify->getMenuByHandle('footer-menu');
        $accountMenu = $this->shopify->getMenuByHandle('customer-account-menu');

        if (isset($data['errors'])) {
            return response()->json(['error' => $data['errors']], 500);
        }

        $sections = $data['sections'] ?? [];
        $this->replaceShopifyImagePaths($sections);

        // 2. Set Theme ID
        $themeId = '179834880383';

        // 3. Fetch sections/header-group.json from theme assets
        $headerResponse = Http::withHeaders([
            'X-Shopify-Access-Token' => config('shopify.access_token'),
        ])->get("https://" . config('shopify.store_domain') . "/admin/api/2024-07/themes/{$themeId}/assets.json", [
            'asset[key]' => 'sections/header-group.json',
        ]);

        $headerRaw = $headerResponse->json()['asset']['value'] ?? null;
        if (!$headerRaw) {
            return response()->json(['error' => 'Header group file not found.'], 500);
        }

        // 4. Decode header JSON
        $headerData = json_decode($headerRaw, true);
        $sectionsData = $headerData['sections'] ?? [];

        // 5. Extract announcement text
        $announcementText = null;
        if (isset($sectionsData['announcement-bar']['blocks']['announcement-bar-0']['settings']['text'])) {
            $announcementText = $sectionsData['announcement-bar']['blocks']['announcement-bar-0']['settings']['text'];
        }

        // 6. Extract logo (from header-navigation-hamburger block with type "logo")
        $logoUrl = null;
        foreach ($sectionsData as $section) {
            if ($section['type'] === 'header-navigation-hamburger' && isset($section['blocks'])) {
                foreach ($section['blocks'] as $block) {
                    if (($block['type'] ?? '') === 'logo') {
                        $logoPath = $block['settings']['logo'] ?? null;

                        if ($logoPath && str_starts_with($logoPath, 'shopify://shop_images/')) {
                            $imageFile = str_replace('shopify://shop_images/', '', $logoPath);
                            $logoUrl = "https://" . config('shopify.store_domain') . "/cdn/shop/files/{$imageFile}";
                            break 2; // exit both loops
                        }
                    }
                }
            }
        }

        // 7. Extract banners
        $banners = [];
        foreach ($sections as $section) {
            if (($section['type'] ?? '') === 'slide-show') {
                $banners[] = $section;
            }
        }

        // 8. Return final response
        return response()->json([
            'sections' => $sections,
            'banners' => $banners,
            'header' => [
                'logo' => $logoUrl,
                'announcement_text' => $announcementText,
                'menus' => [
                    'main' => $mainMenu,
                    'footer' => $footerMenu,
                    'account' => $accountMenu,
                ]
            ],
        ]);
    }


    function replaceShopifyImagePaths(array &$array)
    {
        foreach ($array as $key => &$value) {
            if (is_array($value)) {
                $this->replaceShopifyImagePaths($value);  // <-- add $this->
            } elseif (is_string($value) && strpos($value, 'shopify://shop_images/') === 0) {
                $value = convertShopifyImagePathToFullUrl($value);
            }

            if (is_string($value) && strpos($value, 'shopify://collections/') === 0) {
                $value = convertShopifyLinkToFullUrl($value);
            }
        }
    }

    public function subscribe(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            
            $email = $request->input('email');
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => config('shopify.access_token'),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post("https://" . config('shopify.store_domain') . "/admin/api/2025-01/customers.json", [
                'customer' => [
                    'email' => $email,
                    'accepts_marketing' => true,
                ]
            ]);
    
            if ($response->successful()) {
                return response()->json([
                    'message' => 'Subscribed successfully!',
                    'shopify_response' => $response->json(),
                ]);
            }
            return response()->json([
                'error' => 'Subscription failed',
                'details' => $response->json(),
            ], $response->status());
        } catch (\Throwable $th) {
            return response()->json([
                'error' => 'Failed to join our mailing list',
                'message' => $th->getMessage()
            ], 400);
        }

        
    }
}