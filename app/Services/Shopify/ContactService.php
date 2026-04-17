<?php

namespace App\Services\Shopify;

use App\Contracts\Services\ContactServiceInterface;
use App\Contracts\Shopify\AdminApiClientInterface;
use App\DTOs\Contact\ContactDTO;
use App\Exceptions\ShopifyApiException;
use App\Mail\ContactFormMail;
use App\Services\Base\BaseService;
use Illuminate\Support\Facades\Mail;

/**
 * Contact Service
 * 
 * Handles contact form submission operations including email notifications
 * and optional storage in customer metafields using the Shopify Admin API.
 * 
 * Features:
 * - Send email notifications to admin for contact form submissions
 * - Optionally store submissions in customer metafields
 * - Comprehensive logging with correlation ID tracking
 * - Performance monitoring for all operations
 * 
 * Requirements: 11.9, 11.11, 11.12
 */
class ContactService extends BaseService implements ContactServiceInterface
{
    /**
     * Constructor
     * 
     * @param AdminApiClientInterface $adminClient Admin API client for metafield operations
     */
    public function __construct(
        private readonly AdminApiClientInterface $adminClient
    ) {
        parent::__construct();
    }

    /**
     * Submit contact form
     * 
     * Processes a contact form submission by sending an email notification
     * to the admin email address. Logs the submission with correlation ID
     * for tracking and debugging purposes.
     * 
     * @param ContactDTO $contact Contact form data
     * @return void
     * @throws \Exception If email sending fails
     */
    public function submitContactForm(ContactDTO $contact): void
    {
        try {
            $this->logPerformanceStart('submitContactForm');

            $adminEmail = config('mail.admin_email');

            if (empty($adminEmail)) {
                $this->logError('Admin email not configured', [
                    'contact_email' => $contact->email,
                ]);
                throw new \RuntimeException('Admin email not configured');
            }

            // Send email notification
            Mail::to($adminEmail)->send(new ContactFormMail($contact));

            $this->logInfo('Contact form submitted successfully', [
                'contact_email' => $contact->email,
                'contact_name' => $contact->name,
                'has_subject' => !empty($contact->subject),
                'has_phone' => !empty($contact->phone),
            ]);

            $this->logPerformanceEnd('submitContactForm', [
                'contact_email' => $contact->email,
                'admin_email' => $adminEmail,
            ]);
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to submit contact form', $e, [
                'contact_email' => $contact->email,
                'contact_name' => $contact->name,
            ]);
            throw $e;
        }
    }

    /**
     * Store contact submission in customer metafields
     * 
     * Optionally stores the contact form submission in the customer's metafields
     * for record-keeping purposes. This allows tracking of customer inquiries
     * within Shopify's customer data.
     * 
     * Uses the Admin API metafield_set mutation to store submission data as JSON
     * in the 'custom.contact_submissions' namespace/key.
     * 
     * @param ContactDTO $contact Contact form data
     * @param string $customerId Customer ID (Shopify GID)
     * @return void
     * @throws ShopifyApiException If metafield storage fails
     */
    private function storeContactSubmission(ContactDTO $contact, string $customerId): void
    {
        try {
            $this->logPerformanceStart('storeContactSubmission');

            $submissionData = [
                'email' => $contact->email,
                'name' => $contact->name,
                'subject' => $contact->subject,
                'message' => $contact->message,
                'phone' => $contact->phone,
                'submitted_at' => now()->toIso8601String(),
            ];

            $variables = [
                'metafields' => [
                    [
                        'ownerId' => $customerId,
                        'namespace' => 'custom',
                        'key' => 'contact_submissions',
                        'type' => 'json',
                        'value' => json_encode($submissionData),
                    ],
                ],
            ];

            $response = $this->adminClient->query('admin/metafield/metafield_set', $variables);

            // Check for user errors in the response
            if (!empty($response['data']['metafieldsSet']['userErrors'])) {
                $errors = $response['data']['metafieldsSet']['userErrors'];
                $errorMessage = 'Failed to store contact submission: ' . json_encode($errors);
                $this->logError($errorMessage, ['errors' => $errors]);
                throw new ShopifyApiException($errorMessage);
            }

            $this->logInfo('Contact submission stored in metafields', [
                'customer_id' => $customerId,
                'contact_email' => $contact->email,
            ]);

            $this->logPerformanceEnd('storeContactSubmission', [
                'customer_id' => $customerId,
            ]);
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to store contact submission', $e, [
                'customer_id' => $customerId,
                'contact_email' => $contact->email,
            ]);
            throw $e;
        }
    }
}
