<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

class GraphQLLoader extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'graphql.loader';
    }
}
