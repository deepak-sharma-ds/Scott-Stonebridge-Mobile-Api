<?php

namespace App\Http\Controllers;
use App\Services\ShopifyService;
use Illuminate\Http\Request;

class ShopifyController extends Controller
{
    protected $shopify;

    public function __construct(ShopifyService $shopify)
    {
        $this->shopify = $shopify;
    }

    public function createPage(Request $request)
    {
        $request->validate([
            'title' => 'required|string',
            'html' => 'required|string',
        ]);

        $response = $this->shopify->sendHtmlToShopifyPage(
            $request->title,
            $request->html
        );

        return response()->json($response);
    }
}
