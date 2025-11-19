<?php

namespace App\Console\Commands;

use App\Models\GoogleToken;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Google_Client;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GoogleAdminLogin extends Command
{
    protected $signature = 'google:admin-login';
    protected $description = 'Get Google OAuth token for admin calendar';

    public function handle()
    {
        $client = new Google_Client();
        $client->setClientId(config('google.client_id') ?: env('GOOGLE_CLIENT_ID'));
        $client->setClientSecret(config('google.client_secret') ?: env('GOOGLE_CLIENT_SECRET'));
        $client->setRedirectUri(config('google.redirect_uri'));
        $client->addScope(\Google_Service_Calendar::CALENDAR);
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        // Step 1 — Generate URL
        $authUrl = $client->createAuthUrl();

        $this->info("1️⃣ Open this URL in browser:\n");
        $this->line($authUrl);

        // Step 2 — Get code
        $code = $this->ask("\n2️⃣ Paste the authorization code here");

        // Step 3 — Exchange code for token
        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            $this->error('Error fetching token: ' . $token['error_description']);
            return 1;
        }

        // REQUIRED: Set token on client before using it
        $client->setAccessToken($token);

        /**
         * Step 4 — Compute expires_at
         */
        $created   = $token['created'] ?? time();
        $expiresIn = $token['expires_in'] ?? 3600;
        $expiresAt = Carbon::createFromTimestamp($created + $expiresIn);

        /**
         * Step 5 — Store token securely in DB
         */
        GoogleToken::updateOrCreate(
            ['id' => 1],
            [
                'access_token'          => Crypt::encryptString($token['access_token']),
                'refresh_token'         => isset($token['refresh_token'])
                    ? Crypt::encryptString($token['refresh_token'])
                    : null,
                'expires_at'            => $expiresAt,
                'token_type'            => $token['token_type'] ?? null,
                'scope'                 => $token['scope'] ?? null,
                'created_at_timestamp'  => $created,
            ]
        );

        $this->info("✅ Admin Google token stored securely in database.");
        return 0;
    }
}
