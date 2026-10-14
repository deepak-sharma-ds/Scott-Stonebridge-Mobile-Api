<?php

namespace Tests\Unit\Support;

use App\Support\OpenClientClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OpenClientClassifierTest extends TestCase
{
    /**
     * @return array<string,array{0:?string,1:?string,2:string}>
     */
    public static function clients(): array
    {
        return [
            'gmail image proxy' => ['Mozilla/5.0 (Windows NT 5.1; rv:11.0) Gecko Firefox/11.0 (via ggpht.com GoogleImageProxy)', '66.249.84.1', 'proxy'],
            'yahoo mail proxy' => ['YahooMailProxy; https://help.yahoo.com/kb/yahoo-mail-proxy-SLN28749.html', '203.0.113.5', 'proxy'],
            'apple privacy protection relay' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko)', '17.58.12.9', 'proxy'],
            'apple webkit from a normal address' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148', '81.2.69.142', 'direct'],
            'outlook desktop' => ['Microsoft Office/16.0 (Windows NT 10.0; Microsoft Outlook 16.0.14326; Pro)', '81.2.69.142', 'direct'],
            'chrome webmail' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/118.0 Safari/537.36', '81.2.69.142', 'direct'],
            'missing user agent' => [null, null, 'direct'],
            'proxy token is case insensitive' => ['googleimageproxy', null, 'proxy'],
        ];
    }

    #[DataProvider('clients')]
    public function test_it_classifies_the_client_that_fetched_the_pixel(?string $userAgent, ?string $ip, string $expected): void
    {
        $this->assertSame($expected, OpenClientClassifier::classify($userAgent, $ip));
    }
}
