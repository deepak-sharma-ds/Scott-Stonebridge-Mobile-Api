<?php

namespace App\Mail;

use App\Models\ContactUsMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ContactUsMail extends Mailable
{
    use Queueable, SerializesModels;

    public ContactUsMessage $contact;

    public function __construct(ContactUsMessage $contact)
    {
        $this->contact = $contact;
    }

    public function build()
    {
        return $this->subject('New Contact Us Enquiry')
            ->view('mail.contact-us')
            ->with([
                'patient_name' => $this->contact->name,
                'email'        => $this->contact->email,
                'custom_text'  => $this->contact->message,
                'phone' => $this->contact->phone,
            ]);
    }
}
