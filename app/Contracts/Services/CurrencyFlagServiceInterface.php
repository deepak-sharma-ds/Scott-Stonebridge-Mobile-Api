<?php

namespace App\Contracts\Services;

interface CurrencyFlagServiceInterface
{
    /**
     * Query with currency context
     *
     * @param string $currencyCode Currency code (ISO 4217)
     * @param string $countryCode Country code (ISO 3166-1 alpha-2)
     * @return string Response data
     */
    public static function getFlagUrl(string $currencyCode, string $countryCode): string;
}
