<?php

namespace App\Contracts\Services;

use App\DTOs\FreeReading\FreeReadingFormDTO;
use App\Models\FreeReadingSubmission;

/**
 * Free Reading Service Interface
 *
 * Defines the contract for "Free Email Reading" form submission operations.
 */
interface FreeReadingServiceInterface
{
    /**
     * Store a free reading form submission and notify the admin.
     *
     * @param  FreeReadingFormDTO  $form  Free reading form data
     * @return FreeReadingSubmission The persisted submission
     */
    public function submitFreeReadingForm(FreeReadingFormDTO $form): FreeReadingSubmission;
}
