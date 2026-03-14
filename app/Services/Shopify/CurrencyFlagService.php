<?php

namespace App\Services\Shopify;

use App\Contracts\Services\CurrencyFlagServiceInterface;
use App\Services\Base\BaseService;

class CurrencyFlagService extends BaseService implements CurrencyFlagServiceInterface
{
    protected const SHOPIFY_CDN = 'https://cdn.shopify.com/shopifycloud/web/assets/v1/';
    protected const FLAG_CDN = 'https://flagcdn.com/w40/';

    protected static array $flags = [
        'AED' => '62a4257f72ece1437bc8eb7f0535327b.svg',
        'AFN' => '280ddfeb0fbeea88df7700ca4e9aa83b.svg',
        'AMD' => '506654e384fb737c5ba4b163c8e9ccc8.svg',
        'AUD' => 'd4e9cde3edb3e1732ec50170e158d750.svg',
        'AZN' => '53777b986de0cc3fb6e297309d19add8.svg',
        'BDT' => 'f9381453ad0176558990c513e7ec68a7.svg',
        'BBD' => 'e65b8f27c6a92b4bd87dbefeeaa58528.svg',
        'BOB' => '296613d32804850e80e3ad0fb7a34a00.svg',
        'BND' => 'f5ab6fb24e5ab6e6dcdc64e088403b25.svg',
        'CAD' => '422898ab4299eb270f856e6c1b8d2250.svg',
        'CNY' => '281afcbf105b6242112be9f146a87a6b.svg',
        'EUR' => '9886b4168efb1ebf48006093aa9807c5.svg',
        'GBP' => 'f9bbc4885a348eff84e4ef4155121fae.svg',
        'INR' => 'd17921a22d353f6f811236f13752cb4a.svg',
        'JPY' => 'a8e1297612e97e291633b038e0f94ec9.svg',
        'SAR' => '67184cf8efa28372413ddf6d07294752.svg',
        'SGD' => 'e6485856d9a242f47cbc1f396429aa7c.svg',
        'USD' => '7f0109d94c888a663452af48e2d324d7.svg',
        'ZAR' => '898f31f51a4effc0fabe5a779a6420c6.svg',
    ];

    /**
     * Get flag URL
     */
    public static function getFlagUrl(string $currencyCode, string $countryCode): string
    {
        $currencyCode = strtoupper($currencyCode);
        $countryCode = strtolower($countryCode);

        // Primary: Shopify CDN
        if (isset(self::$flags[$currencyCode])) {
            return self::SHOPIFY_CDN . self::$flags[$currencyCode];
        }

        // Fallback: Country flag CDN
        return self::FLAG_CDN . $countryCode . '.png';
    }
}
