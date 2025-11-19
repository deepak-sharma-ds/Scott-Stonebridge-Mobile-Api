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

        // Load credentials from config
        $client->setClientId(config('google.client_id') ?: env('GOOGLE_CLIENT_ID'));
        $client->setClientSecret(config('google.client_secret') ?: env('GOOGLE_CLIENT_SECRET'));
        $client->setRedirectUri(config('google.redirect_uri'));
        // least privilege — use events scope if possible

        // Add required scopes
        $client->addScope(config('google.scopes') ?: env('GOOGLE_SCOPES'));

        // Offline access to get refresh token
        $client->setAccessType('offline');

        // Prompt consent to always get refresh token
        // $client->setPrompt('consent');

        return $client;
    }

    // API route to get Google Auth URL
    public function getAuthUrl()
    {
        $client = $this->getClient();

        // 🔒 Generate random nonce
        $nonce = bin2hex(random_bytes(16));
        cache()->put("google_oauth_nonce_{$nonce}", true, 300); // 5 minutes

        // Get optional formData passed from frontend — this is your original flow
        $payload = request()->query('state') ?? null;

        // Wrap payload + nonce together
        $state = json_encode([
            'nonce'   => $nonce,
            'payload' => $payload,
        ]);

        $client->setState($state);

        return response()->json([
            'auth_url' => $client->createAuthUrl(),
        ]);
    }



    public function handleCallback(Request $request, BookingService $bookingService)
    {
        Log::info('handleCallback started', ['query' => $request->query()]);

        // 🔒 1. Validate OAuth state (CSRF protection)
        $stateRaw = $request->query('state');

        if (!$stateRaw) {
            Log::error('Missing OAuth state');
            return redirect(config('app.frontend_url') . '?error=invalid_state');
        }

        $decodedState = json_decode($stateRaw, true);

        // Backwards compatibility with old flow (payload-only state)
        if (!isset($decodedState['nonce'])) {
            Log::warning("State missing nonce — backward compatibility mode");
            $stateRaw = $stateRaw; // use original payload
        } else {
            $nonce = $decodedState['nonce'];

            if (!cache()->has("google_oauth_nonce_{$nonce}")) {
                Log::error("State nonce mismatch / expired", ['nonce' => $nonce]);
                return redirect(config('app.frontend_url') . '?error=state_mismatch');
            }

            // Remove nonce immediately to prevent reuse
            cache()->forget("google_oauth_nonce_{$nonce}");

            // Extract original payload (your form JSON)
            $stateRaw = $decodedState['payload'];
        }

        // 🔄 2. Handle authorization code
        $code = $request->query('code');

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
            Log::error('Token error', ['error' => $token['error_description'] ?? 'Unknown']);
            return response()->json(['error' => $token['error_description'] ?? 'Unknown error'], 400);
        }

        // (Optional) Save temporarily for debugging — otherwise not used
        session(['google_access_token' => $token]);

        // 🔄 3. Extract your original formData (slot + user info)
        $formData = json_decode($stateRaw, true);

        if (!is_array($formData)) {
            Log::error('Invalid state payload JSON', ['payload' => $stateRaw]);
            return redirect(config('app.frontend_url'));
        }

        // Pass the token forward to BookingService (if you need it)
        $formData['google_token'] = $token;

        Log::info('Calling BookingService->bookMeeting', ['formData' => $formData]);

        // 4. Create booking + Google event
        try {
            $result = $bookingService->bookMeeting($formData);
        } catch (\Exception $e) {
            Log::error('Error in booking flow', ['exception' => $e->getMessage()]);
            return redirect(config('app.frontend_url') . '?error=' . urlencode('Internal server error during booking'));
        }

        // 5. Handle result
        if (!empty($result['success'])) {
            Log::info('Booking successful', ['meeting_link' => $result['meeting_link']]);
            return redirect(config('app.frontend_url') . '?meeting_link=' . urlencode($result['meeting_link']));
        }

        Log::error('Booking failed', ['message' => $result['message'] ?? 'Unknown']);
        return redirect(config('app.frontend_url') . '?error=' . urlencode($result['message'] ?? 'Booking failed'));
    }
}
