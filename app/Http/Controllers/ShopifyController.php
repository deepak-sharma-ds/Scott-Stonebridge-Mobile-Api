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
                'phone' => 'required|string',
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
        $log->info('================== START: handleAppointmentBookingWebhook ==================');
        try {
            // $hmacHeader = $request->header('X-Shopify-Hmac-Sha256');
            // $data = $request->getContent();
            // $calculated = base64_encode(hash_hmac('sha256', $data, config('services.shopify.secret'), true));

            // if (!hash_equals($calculated, $hmacHeader)) {
            //     logger()->warning('Invalid Shopify webhook HMAC');
            //     return response('Invalid signature', 401);
            // }

            $payload = $request->all();

            $customer = data_get($payload, 'customer', []);
            if (empty($customer)) {
                $log->warning('No customer data in order paid webhook.');
                return response()->json([
                    'status' => 400,
                    'message' => 'No customer data'
                ], 400);
            }
            $customerId = data_get($customer, 'id');
            $email = data_get($customer, 'email');
            $tagsCsv = data_get($customer, 'tags', '');

            $tags = array_filter(array_map('trim', explode(',', $tagsCsv)));
            foreach ($tags as $tag) {
                if (Package::where('shopify_tag', $tag)->exists()) {
                    CustomerEntitlement::updateOrCreate(
                        ['shopify_customer_id' => $customerId, 'package_tag' => $tag],
                        ['email' => $email]
                    );
                }
            }

            return response()->json([
                'status' => 200,
                'message' => 'Audio access granted',
            ], 200);
        } catch (\Throwable $th) {
            $log->error('Exception in handleAppointmentBookingWebhook:', ['error' => $th->getMessage()]);
            return response()->json([
                'status' => 500,
                'message' => 'Exception occurred',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}
