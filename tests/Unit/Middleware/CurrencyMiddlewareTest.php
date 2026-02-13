<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\CurrencyMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

class CurrencyMiddlewareTest extends TestCase
{
    protected CurrencyMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new CurrencyMiddleware();
    }

    public function test_uses_default_currency_when_not_provided(): void
    {
        config(['shopify.currency' => 'GBP']);
        
        $request = Request::create('/test', 'GET');
        
        $this->middleware->handle($request, function ($req) {
            $this->assertEquals('GBP', $req->attributes->get('currency'));
            $this->assertEquals('GBP', $req->input('currency'));
            $this->assertEquals('GBP', config('app.currency'));
            return new Response('OK');
        });
    }

    public function test_extracts_currency_from_header(): void
    {
        $request = Request::create('/test', 'GET');
        $request->headers->set('X-Currency', 'USD');
        
        $this->middleware->handle($request, function ($req) {
            $this->assertEquals('USD', $req->attributes->get('currency'));
            $this->assertEquals('USD', $req->input('currency'));
            return new Response('OK');
        });
    }

    public function test_extracts_currency_from_query_param(): void
    {
        $request = Request::create('/test?currency=EUR', 'GET');
        
        $this->middleware->handle($request, function ($req) {
            $this->assertEquals('EUR', $req->attributes->get('currency'));
            $this->assertEquals('EUR', $req->input('currency'));
            return new Response('OK');
        });
    }

    public function test_header_takes_priority_over_query_param(): void
    {
        $request = Request::create('/test?currency=EUR', 'GET');
        $request->headers->set('X-Currency', 'USD');
        
        $this->middleware->handle($request, function ($req) {
            $this->assertEquals('USD', $req->attributes->get('currency'));
            return new Response('OK');
        });
    }

    public function test_normalizes_currency_to_uppercase(): void
    {
        $request = Request::create('/test', 'GET');
        $request->headers->set('X-Currency', 'usd');
        
        $this->middleware->handle($request, function ($req) {
            $this->assertEquals('USD', $req->attributes->get('currency'));
            return new Response('OK');
        });
    }

    public function test_validates_currency_against_supported_list(): void
    {
        config(['shopify.currency' => 'GBP']);
        
        $request = Request::create('/test', 'GET');
        $request->headers->set('X-Currency', 'INVALID');
        
        $this->middleware->handle($request, function ($req) {
            // Should fall back to default when invalid
            $this->assertEquals('GBP', $req->attributes->get('currency'));
            return new Response('OK');
        });
    }

    public function test_accepts_all_supported_currencies(): void
    {
        $supportedCurrencies = ['GBP', 'USD', 'EUR', 'CAD', 'AUD', 'JPY', 'CHF', 'NZD', 'SEK', 'DKK', 'NOK'];
        
        foreach ($supportedCurrencies as $currency) {
            $request = Request::create('/test', 'GET');
            $request->headers->set('X-Currency', $currency);
            
            $this->middleware->handle($request, function ($req) use ($currency) {
                $this->assertEquals($currency, $req->attributes->get('currency'));
                return new Response('OK');
            });
        }
    }
}
