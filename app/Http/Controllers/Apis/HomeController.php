<?php

namespace App\Http\Controllers\Apis;

use App\Http\Controllers\Controller;
use App\Services\ContentService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http; // For subscribe, or move to Service
// Assuming Subscription could be in ContentService or CustomerService. 
// Legacy had it here. Let's keep it here but refactor later.

class HomeController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ContentService $contentService
    ) {}

    public function home()
    {
        // 1. Get Home Page Sections
        $sectionsData = $this->contentService->getHomePageSections();
        
        // 2. Get Menus
        $mainMenu = $this->contentService->getMenu('main-menu');
        $footerMenu = $this->contentService->getMenu('footer-menu');
        $accountMenu = $this->contentService->getMenu('customer-account-menu');

        $sections = $sectionsData['sections'] ?? [];
        $this->replaceShopifyImagePaths($sections);

        // 3. Get Header Group (for Logo & Announcement)
        $headerData = $this->contentService->getHeaderGroup();
        $headerSections = $headerData['sections'] ?? [];

        // 4. Extract announcement text
        $announcementText = null;
        if (isset($headerSections['announcement-bar']['blocks']['announcement-bar-0']['settings']['text'])) {
            $announcementText = $headerSections['announcement-bar']['blocks']['announcement-bar-0']['settings']['text'];
        }

        // 5. Extract logo
        $logoUrl = null;
        foreach ($headerSections as $section) {
            if ($section['type'] === 'header-navigation-hamburger' && isset($section['blocks'])) {
                foreach ($section['blocks'] as $block) {
                    if (($block['type'] ?? '') === 'logo') {
                        $logoPath = $block['settings']['logo'] ?? null;
                        if ($logoPath && str_starts_with($logoPath, 'shopify://shop_images/')) {
                            $imageFile = str_replace('shopify://shop_images/', '', $logoPath);
                            $logoUrl = "https://" . config('shopify.store_domain') . "/cdn/shop/files/{$imageFile}";
                            break 2;
                        }
                    }
                }
            }
        }

        // 6. Extract banners
        $banners = [];
        foreach ($sections as $section) {
            if (($section['type'] ?? '') === 'slide-show') {
                $banners[] = $section;
            }
        }

        return $this->success('Home content fetched', [
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

    // Helper to recursively fix image paths (Legacy logic retained)
    private function replaceShopifyImagePaths(array &$array)
    {
        foreach ($array as $key => &$value) {
            if (is_array($value)) {
                $this->replaceShopifyImagePaths($value);
            } elseif (is_string($value) && str_starts_with($value, 'shopify://shop_images/')) {
                // Mocking the helper function provided in legacy, or implementing it here
                // Legacy called global function `convertShopifyImagePathToFullUrl`
                // Let's implement inline or use the global if it exists. 
                // Assuming we need to replicate logic:
                 $imageFile = str_replace('shopify://shop_images/', '', $value);
                 $value = "https://" . config('shopify.store_domain') . "/cdn/shop/files/{$imageFile}";
            }
            
            // Link fix
            if (is_string($value) && str_starts_with($value, 'shopify://collections/')) {
                 $value = str_replace('shopify://collections/', '/collections/', $value);
            }
        }
    }

    public function subscribe(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        try {
            // Ideally move this to ShopifyCustomerAuthService or NewsletterService
            // For now, raw call is okay as it's a simple post.
            // But strict adherence suggests moving it.
            // Let's keep it minimal here.
            
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
                return $this->success('Subscribed successfully!', ['shopify_response' => $response->json()]);
            }
            
            return $this->error('Subscription failed', $response->json(), $response->status());

        } catch (\Throwable $th) {
            return $this->error('Failed to join our mailing list', $th->getMessage(), 400);
        }
    }
}