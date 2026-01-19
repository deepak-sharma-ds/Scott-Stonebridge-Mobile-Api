<?php

namespace App\Http\Controllers\Apis;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContactUsRequest;
use App\Models\ContactUsMessage;
use App\Mail\ContactUsMail;
use Illuminate\Support\Facades\Mail;

class ContactUsController extends Controller
{
    public function store(ContactUsRequest $request)
    {
        // Save to DB
        $contact = ContactUsMessage::create($request->validated());
        // Send email
        Mail::to(env('CONTACT_ADMIN_EMAIL'))
            ->send(new ContactUsMail($contact));

        return response()->json([
            'success' => true,
            'message' => 'Thank you for contacting us. We will get back to you soon.'
        ]);
    }
}
