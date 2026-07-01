<?php

namespace App\Services;

use App\Contracts\Services\FreeReadingServiceInterface;
use App\DTOs\FreeReading\FreeReadingFormDTO;
use App\Mail\FreeReadingFormMail;
use App\Models\FreeReadingSubmission;
use App\Services\Base\BaseService;
use Illuminate\Support\Facades\Mail;

/**
 * Free Reading Service
 *
 * Handles "Free Email Reading" form submissions from the mobile app:
 * persists the lead and notifies the admin by email.
 */
class FreeReadingService extends BaseService implements FreeReadingServiceInterface
{
    /**
     * Store a free reading form submission and notify the admin.
     *
     * The submission is persisted first so the lead is never lost; the admin
     * notification is best-effort and a mail failure will not fail the request.
     *
     * @param  FreeReadingFormDTO  $form  Free reading form data
     * @return FreeReadingSubmission The persisted submission
     */
    public function submitFreeReadingForm(FreeReadingFormDTO $form): FreeReadingSubmission
    {
        $this->logPerformanceStart('submitFreeReadingForm');

        $submission = FreeReadingSubmission::create([
            'first_name' => $form->firstName,
            'last_name' => $form->lastName,
            'email' => $form->email,
            'phone_country_code' => $form->phoneCountryCode,
            'phone' => $form->phone,
        ]);

        $this->notifyAdmin($form, $submission);

        $this->logInfo('Free reading form submitted successfully', [
            'submission_id' => $submission->id,
            'email' => $form->email,
        ]);

        $this->logPerformanceEnd('submitFreeReadingForm', [
            'submission_id' => $submission->id,
        ]);

        return $submission;
    }

    /**
     * Send the admin notification email for a submission.
     *
     * Best-effort: logs and swallows failures so a mail outage does not lose the lead.
     */
    private function notifyAdmin(FreeReadingFormDTO $form, FreeReadingSubmission $submission): void
    {
        $adminEmail = config('mail.admin_email');

        if (empty($adminEmail)) {
            $this->logError('Admin email not configured; skipping free reading notification', [
                'submission_id' => $submission->id,
            ]);

            return;
        }

        try {
            Mail::to($adminEmail)->send(new FreeReadingFormMail($form));
        } catch (\Throwable $e) {
            $this->logErrorWithException('Failed to send free reading admin notification', $e, [
                'submission_id' => $submission->id,
                'admin_email' => $adminEmail,
            ]);
        }
    }
}
