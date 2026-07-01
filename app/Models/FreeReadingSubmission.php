<?php

namespace App\Models;

use Database\Factories\FreeReadingSubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Free Reading Submission
 *
 * Stores "Free Email Reading" lead-capture form submissions from the mobile app.
 */
class FreeReadingSubmission extends Model
{
    /** @use HasFactory<FreeReadingSubmissionFactory> */
    use HasFactory;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone_country_code',
        'phone',
    ];
}
