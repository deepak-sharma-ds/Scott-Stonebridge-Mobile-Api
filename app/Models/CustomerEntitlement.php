<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerEntitlement extends Model
{
    use SoftDeletes;

    protected $fillable = ['shopify_customer_id', 'email', 'package_tag'];
}
