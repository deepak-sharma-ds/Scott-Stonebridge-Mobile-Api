<?php

namespace App\Contracts\Services;

use App\DTOs\Contact\ContactDTO;

/**
 * Contact Service Interface
 * 
 * Defines the contract for contact form submission operations.
 */
interface ContactServiceInterface
{
    /**
     * Submit contact form
     * 
     * @param ContactDTO $contact Contact form data
     * @return void
     */
    public function submitContactForm(ContactDTO $contact): void;
}
