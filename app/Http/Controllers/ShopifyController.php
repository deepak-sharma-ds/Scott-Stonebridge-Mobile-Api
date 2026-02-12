<?php

namespace App\Http\Controllers;

use App\Services\BookingService;
use App\Mail\BookingConfirmationMail;
use App\Services\ShopifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use App\Models\Package;
use App\Models\CustomerEntitlement;

class ShopifyController extends Controller
{
    protected $shopify;

    public function __construct(ShopifyService $shopify)
    {
        $this->shopify = $shopify;
    }

    public function createPage(Request $request)
    {
        $request->validate([
            'title' => 'required|string',
            'html' => 'required|string',
        ]);

        $response = $this->shopify->sendHtmlToShopifyPage(
            $request->title,
            $request->html
        );

        return response()->json($response);
    }

    public function handleAppointmentBookingWebhook(Request $request, BookingService $bookingService)
    {
        $log = Log::channel('shopify_webhooks');
        $log->info('================== START: handleAppointmentBookingWebhook ==================');
        try {
            $hmacHeader = $request->header('X-Shopify-Hmac-Sha256');
            $webhookSecret = config('shopify.api_secret');
            $payload = $request->getContent();

            // Verify HMAC signature
            // $calculatedHmac = base64_encode(hash_hmac('sha256', $payload, $webhookSecret, true));
            // if (!hash_equals($hmacHeader, $calculatedHmac)) {
            //     $log->warning('Unauthorized webhook received.');
            //     return response()->json([
            //         'status' => 401,
            //         'message' => 'Unauthorized'
            //     ], 401);
            // }

            $data = json_decode($payload, true); // Shopify sends JSON payload

            $log->info('Shopify Webhook Received:', $data);

            // Map or validate data according to your booking structure
            $bookingData = [
                'order_id' => $data['id'] ?? null,
                'name' => $data['name'] ?? $data['customer']['first_name'] . ' ' . $data['customer']['last_name'] ?? null,
                'email' => $data['email'] ?? $data['customer']['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'availability_date' => $data['availability_date'] ?? now()->toDateString(),
                'time_slot_id' => $data['time_slot_id'] ?? null,
            ];
            if (empty($bookingData['time_slot_id'])) {
                return response()->json([
                    'status' => 200,
                    'message' => 'Ignored product ID'
                ], 200);
            }

            // Optional: Validate required fields
            $validator = Validator::make($bookingData, [
                'order_id' => 'required',
                'name' => 'required|string|max:255',
                'email' => 'required|email',
                'phone' => 'nullable|max:20',
                'availability_date' => 'required|date',
                'time_slot_id' => 'required|exists:time_slots,id',
            ]);

            if ($validator->fails()) {
                $log->error('Validation failed for webhook booking:', $validator->errors()->toArray());
                return response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'error' => $validator->errors()
                ], 422);
            }

            // Book the meeting
            $result = $bookingService->bookMeeting($bookingData);

            if (!empty($result['success'])) {
                $booking = $result['booking']; // ScheduledMeeting model

                // Optional: send email confirmation
                // Mail::to($booking->email)->send(new BookingConfirmationMail($booking));

                $log->info('Booking successfully created:', ['booking_id' => $booking->id]);
                $log->info('================== END: handleAppointmentBookingWebhook ==================');

                return response()->json([
                    'status' => 200,
                    'message' => 'Booking successful',
                    'data' => [
                        'booking_data' => $booking
                    ]
                ], 200);
            }

            $log->error('Booking failed:', $result);
            return response()->json([
                'status' => 500,
                'message' => $result['message'] ?? null,
                'error' => $result['error'] ?? 'Booking failed',
            ], 500);
        } catch (\Throwable $th) {
            $log->error('Exception in handleAppointmentBookingWebhook:', ['error' => $th->getMessage()]);
            return response()->json([
                'status' => 500,
                'message' => 'Exception occurred',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function orderPaid(Request $request)
    {
        $log = Log::channel('shopify_webhooks');
        $log->info('================== START: orderPaid webhook ==================');

        try {
            // Verify Shopify HMAC (recommended for production)

            // $hmacHeader = $request->header('X-Shopify-Hmac-Sha256');
            // $data = $request->getContent();
            // $calculated = base64_encode(
            //     hash_hmac('sha256', $data, config('services.shopify.secret'), true)
            // );

            // if (!hash_equals($calculated, $hmacHeader)) {
            //     $log->warning('Invalid Shopify webhook HMAC');
            //     return response('Invalid signature', 401);
            // }


            $payload = $request->all();

            // -------------------------------
            // 1. Validate customer
            // -------------------------------
            $customer = data_get($payload, 'customer');
            if (!$customer) {
                $log->warning('No customer object in order payload.');
                return response()->json([
                    'status' => 400,
                    'message' => 'Customer data missing',
                ], 400);
            }

            $customerId = data_get($customer, 'id');
            $email      = data_get($customer, 'email');

            if (!$customerId || !$email) {
                $log->warning('Customer ID or email missing.', [
                    'customer' => $customer
                ]);
                return response()->json([
                    'status' => 400,
                    'message' => 'Invalid customer data',
                ], 400);
            }

            // -------------------------------
            // 2. Process line items and validation
            // -------------------------------
            $lineItems = data_get($payload, 'line_items', []);
            if (empty($lineItems)) {
                $log->warning('No line items found in order.', [
                    'order_id' => data_get($payload, 'id')
                ]);
                return response()->json([
                    'status' => 400,
                    'message' => 'No line items',
                ], 400);
            }
            $log->info('Processing line items.', [
                'order_id' => data_get($payload, 'id'),
                'line_items_count' => count($lineItems),
            ]);

            foreach ($lineItems as $item) {
                $properties = data_get($item, 'properties', []);

                if (empty($properties)) {
                    $log->info('No line item properties found.', [
                        'line_item_id' => data_get($item, 'id')
                    ]);
                    continue;
                }

                foreach ($properties as $property) {
                    $name  = data_get($property, 'name');
                    $value = data_get($property, 'value');

                    $log->info('Processing line item property.', [
                        'name'  => $name,
                        'value' => $value,
                    ]);

                    // -------------------------------
                    // 3. Identify meditation product
                    // -------------------------------
                    if ($name === 'meditation-audio' && !empty($value)) {

                        // Example: value = communication-meditation
                        $package = Package::where('shopify_tag', $value)->first();

                        if (!$package) {
                            $log->warning('No package found for meditation audio.', [
                                'value' => $value,
                                'order_id' => data_get($payload, 'id')
                            ]);
                            continue;
                        }

                        // -------------------------------
                        // 4. Grant entitlement
                        // -------------------------------
                        CustomerEntitlement::updateOrCreate(
                            [
                                'shopify_customer_id' => $customerId,
                                'package_tag'         => $package->shopify_tag,
                            ],
                            [
                                'email'       => $email,
                                // 'order_id'    => data_get($payload, 'id'),
                                // 'line_item_id' => data_get($item, 'id'),
                                // 'created_at'  => now(),
                            ]
                        );

                        $log->info('Meditation access granted.', [
                            'customer_id' => $customerId,
                            'email'       => $email,
                            'package'     => $package->shopify_tag,
                            'order_id'    => data_get($payload, 'id'),
                        ]);
                    }
                }
            }

            return response()->json([
                'status'  => 200,
                'message' => 'Meditation access processed successfully',
            ], 200);
        } catch (\Throwable $th) {
            $log->error('Exception in orderPaid webhook', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);

            return response()->json([
                'status'  => 500,
                'message' => 'Exception occurred',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }
}
