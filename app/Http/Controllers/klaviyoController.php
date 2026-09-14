<?php

namespace App\Http\Controllers;

use App\Services\KlaviyoService;
use Illuminate\Http\Request;
class klaviyoController extends Controller
{
     public function test(KlaviyoService $klaviyo)
    {
        $customer = [
            'email' => 'test@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'phone_number' => '+919999999999',
        ];

        $response = $klaviyo->createProfile($customer);

        return response()->json([
            'status' => $response->status(),
            'response' => $response->json(),
        ]);
    }
}

