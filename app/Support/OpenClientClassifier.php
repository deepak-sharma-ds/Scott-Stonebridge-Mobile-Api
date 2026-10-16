<?php

namespace App\Support;

/**
 * Tells a Proxy Open from a Direct Open. Privacy proxies fetch the tracking
 * pixel on the recipient's behalf: Gmail and Yahoo announce themselves in the
 * User-Agent, while Apple Mail Privacy Protection sends a generic WebKit
 * User-Agent and is only recognisable by Apple's 17.0.0.0/8 relay addresses.
 * This is a best-effort heuristic: a proxy it cannot recognise reads as direct.
 */
class OpenClientClassifier
{
    public const DIRECT = 'direct';

    public const PROXY = 'proxy';

    private const PROXY_USER_AGENT_TOKENS = ['googleimageproxy', 'yahoomailproxy'];

    private const APPLE_RELAY_FIRST_OCTET = '17';

    public static function classify(?string $userAgent, ?string $ip): string
    {
        $agent = strtolower((string) $userAgent);
        $ip = trim((string) $ip);

        foreach (self::PROXY_USER_AGENT_TOKENS as $token) {
            if (str_contains($agent, $token)) {
                return self::PROXY;
            }
        }

        if (str_contains($ip, '.') && explode('.', $ip)[0] === self::APPLE_RELAY_FIRST_OCTET) {
            return self::PROXY;
        }

        return self::DIRECT;
    }
}
