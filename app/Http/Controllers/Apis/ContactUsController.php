<?php

namespace App\Http\Controllers\Apis;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContactUsRequest;
use App\Models\ContactUsMessage;
use App\Mail\ContactUsMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Throwable;

class ContactUsController extends Controller
{
    public function store(ContactUsRequest $request)
    {
        try {
            // Save to DB
            $contact = ContactUsMessage::create($request->validated());

            // Send email
            Mail::to(config('mail.admin_email'))
                ->send(new ContactUsMail($contact));

            return response()->json([
                'success' => true,
                'message' => 'Thank you for contacting us. We will get back to you soon.',
            ]);
        } catch (Throwable $e) {

            // Log for debugging
            Log::error('Contact Us submission failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong. Please try again later.',
            ], 500);
        }
    }
}
