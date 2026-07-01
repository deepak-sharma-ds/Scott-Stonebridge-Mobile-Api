<?php

namespace App\Mail;

use App\DTOs\FreeReading\FreeReadingFormDTO;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Free Reading Form Mail
 *
 * Admin notification for a new "Free Email Reading" lead-capture submission.
 */
class FreeReadingFormMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @param  FreeReadingFormDTO  $form  Free reading form data
     */
    public function __construct(
        public FreeReadingFormDTO $form
    ) {}

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $phone = trim(($this->form->phoneCountryCode ?? '').' '.$this->form->phone);

        return $this->subject('New Free Email Reading Submission')
            ->view('mail.free-reading-form')
            ->with([
                'first_name' => $this->form->firstName,
                'last_name' => $this->form->lastName,
                'email' => $this->form->email,
                'phone' => $phone !== '' ? $phone : 'Not provided',
            ]);
    }
}
