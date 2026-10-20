<?php

use App\Providers\AppServiceProvider;
use App\Providers\BundleServiceProvider;
use App\Providers\ShopifyServiceProvider;

return [
    AppServiceProvider::class,
    ShopifyServiceProvider::class,
    BundleServiceProvider::class,
];
