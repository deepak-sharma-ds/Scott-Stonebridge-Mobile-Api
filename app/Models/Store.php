<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
	protected $fillable = [
		'name',		
		'domain',		
		'app_client_id',
		'app_secret_key',
		'app_admin_access_token',
		'webhook_secret',
		'status',
	];

	protected $casts = [
        'app_client_id' => 'encrypted',
        'app_secret_key' => 'encrypted',
        'app_admin_access_token' => 'encrypted',
        'webhook_secret' => 'encrypted',
        'status' => 'boolean',
    ];

	public function getStoreIdByStoreUrl($store_url)
	{
		$store = Store::where('domain', $store_url)->first();
		return !empty($store->id) ? $store->id : 0;
	}
}
