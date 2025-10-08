<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google_Client;
use App\Services\BookingService;
use Illuminate\Support\Facades\Log;

class GoogleOAuthController extends Controller
{
    private function getClient()
    {
        $client = new Google_Client();

        // Load credentials from storage_path('app/credentials.json')
        $client->setAuthConfig(storage_path('app/credentials.json'));

        // Add required scopes
        $client->addScope('https://www.googleapis.com/auth/calendar');

        // Offline access to get refresh token
        $client->setAccessType('offline');

        // Prompt consent to always get refresh token
        $client->setPrompt('consent');

        // Set redirect URI from environment config
        $client->setRedirectUri(config('google.redirect_uri'));

        return $client;
    }

    // API route to get Google Auth URL
    public function getAuthUrl(Request $request)
    {
        $client = $this->getClient();

        $state = $request->query('state');
        if ($state) {
            $client->setState($state);
        }

        $authUrl = $client->createAuthUrl();
        \Log::info('Generated Google OAuth URL', ['authUrl' => $authUrl]);

        return response()->json(['auth_url' => $authUrl]);
    }

    public function handleCallback(Request $request, BookingService $bookingService)
    {
        Log::info('handleCallback started', ['query' => $request->query()]);

        $code = $request->query('code');
        $state = $request->query('state'); // form data JSON string

        if (!$code) {
            Log::error('Authorization code missing');
            return response()->json(['error' => 'Authorization code missing'], 400);
        }

        $client = $this->getClient();

        try {
            $token = $client->fetchAccessTokenWithAuthCode($code);
        } catch (\Exception $e) {
            Log::error('Failed to fetch access token', ['exception' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to fetch access token'], 500);
        }

        if (isset($token['error'])) {
            Log::error('Error in token response', ['error' => $token['error_description'] ?? 'Unknown error']);
            return response()->json(['error' => $token['error_description'] ?? 'Unknown error'], 400);
        }

        session(['google_access_token' => $token]);
        Log::info('Access token fetched and saved in session');

        if (!$state) {
            Log::warning('State parameter missing, redirecting to frontend');
            return redirect(config('app.frontend_url'));
        }

        $formData = json_decode($state, true);

        if (!is_array($formData)) {
            Log::error('Failed to decode state JSON or invalid format', ['state' => $state]);
            return redirect(config('app.frontend_url'));
        }

        $formData['google_token'] = $token;

        Log::info('Calling BookingService->bookMeeting', ['formData' => $formData]);

        try {
            $result = $bookingService->bookMeeting($formData);
        } catch (\Exception $e) {
            Log::error('Exception thrown in bookMeeting', ['exception' => $e->getMessage()]);
            return redirect(config('app.frontend_url') . '?error=' . urlencode('Internal server error during booking'));
        }

        if (!empty($result['success'])) {
            Log::info('Booking successful', ['meeting_link' => $result['meeting_link']]);
            return redirect(config('app.frontend_url') . '?meeting_link=' . urlencode($result['meeting_link']));
        } else {
            Log::error('Booking failed', ['message' => $result['message'] ?? 'Unknown error']);
            return redirect(config('app.frontend_url') . '?error=' . urlencode($result['message'] ?? 'Booking failed'));
        }
    }
}
