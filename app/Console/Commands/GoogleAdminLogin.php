<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Google_Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GoogleAdminLogin extends Command
{
    protected $signature = 'google:admin-login';
    protected $description = 'Get Google OAuth token for admin calendar';

    public function handle()
    {
        $client = new Google_Client();
        $client->setAuthConfig(storage_path('app/credentials.json'));
        $client->addScope(\Google_Service_Calendar::CALENDAR);
        $client->setRedirectUri('urn:ietf:wg:oauth:2.0:oob'); // manual flow
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        $authUrl = $client->createAuthUrl();

        $this->info("1️⃣ Open this URL in browser:\n");
        $this->line($authUrl);

        $code = $this->ask("\n2️⃣ Paste the authorization code here");

        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            $this->error('Error fetching token: ' . $token['error_description']);
            return 1;
        }

        $client->setAccessToken($token);

        Storage::disk('local')->put('admin_google_token.json', json_encode($token));

        $this->info('✅ Admin token saved to: ' . Storage::disk('local')->path('admin_google_token.json'));

        return 0;
    }

}
