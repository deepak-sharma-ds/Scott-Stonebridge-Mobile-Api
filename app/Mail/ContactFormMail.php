<?php

namespace App\Mail;

use App\DTOs\Contact\ContactDTO;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Contact Form Mail
 * 
 * Mailable class for sending contact form submission notifications
 * to the admin email address. Contains all contact form data including
 * name, email, subject, message, and optional phone number.
 * 
 * Requirements: 11.9, 11.11, 11.12
 */
class ContactFormMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Contact form data
     *
     * @var ContactDTO
     */
    public ContactDTO $contact;

    /**
     * Create a new message instance.
     *
     * @param ContactDTO $contact Contact form data
     */
    public function __construct(ContactDTO $contact)
    {
        $this->contact = $contact;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('New Contact Form Submission')
            ->view('mail.contact-us')
            ->with([
                'patient_name' => $this->contact->name,
                'email' => $this->contact->email,
                'phone' => $this->contact->phone ?? 'Not provided',
                'custom_text' => $this->contact->message,
            ]);
    }
}
