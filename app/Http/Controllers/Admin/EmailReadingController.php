<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailReadingDeliveryStoreRequest;
use App\Http\Requests\EmailReadingDeliveryUpdateRequest;
use App\Mail\EmailReadingMail;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Services\EmailReading\EmailReadingAdminService;
use App\Services\EmailReading\EmailReadingGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class EmailReadingController extends Controller
{
    public function __construct(
        private readonly EmailReadingAdminService $service,
        private readonly EmailReadingGenerationService $generation
    ) {}

    public function index(Request $request)
    {
        $filters = $request->only(['status', 'search', 'from', 'to']);
        $deliveries = $this->service->paginateDeliveries($filters);

        return view('admin.email_readings.index', [
            'deliveries' => $deliveries,
            'statuses' => EmailReadingDelivery::statusLabels(),
            'filters' => $filters,
        ]);
    }

    public function create()
    {
        return view('admin.email_readings.create', [
            'products' => EmailReadingProduct::orderBy('name')->get(),
        ]);
    }

    public function store(EmailReadingDeliveryStoreRequest $request): RedirectResponse
    {
        try {
            $delivery = $this->service->createManual($request->validated());

            return redirect()
                ->route('admin.email_readings.show', $delivery)
                ->with('success', 'Reading created. Generation has been queued.');
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Failed to create reading: '.$e->getMessage());
        }
    }

    public function show(EmailReadingDelivery $delivery)
    {
        $delivery->load('product');

        return view('admin.email_readings.show', compact('delivery'));
    }

    public function edit(EmailReadingDelivery $delivery)
    {
        $delivery->load('product');

        return view('admin.email_readings.edit', [
            'delivery' => $delivery,
            'statuses' => EmailReadingDelivery::statusLabels(),
        ]);
    }

    /**
     * Renders the real EmailReadingMail Mailable for this delivery.
     * Optionally applies unsaved overrides without persisting, so
     * the edit screen can preview unsaved changes.
     */
    public function preview(Request $request, EmailReadingDelivery $delivery): Response
    {
        $delivery->load('product');

        $previewDelivery = clone $delivery;
        if ($request->has('ai_response')) {
            $previewDelivery->ai_response = $request->input('ai_response');
        }
        if ($request->has('customer_name')) {
            $previewDelivery->customer_name = $request->input('customer_name');
        }
        if ($request->has('customer_email')) {
            $previewDelivery->customer_email = $request->input('customer_email');
        }

        $mailable = new EmailReadingMail($previewDelivery);
        $html = $mailable->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function update(EmailReadingDeliveryUpdateRequest $request, EmailReadingDelivery $delivery): RedirectResponse
    {
        try {
            $this->service->updateDelivery($delivery, $request->validated());

            return redirect()
                ->route('admin.email_readings.show', $delivery)
                ->with('success', 'Reading updated successfully.');
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Failed to update reading: '.$e->getMessage());
        }
    }

    /**
     * AJAX: regenerate the AI response from an optional admin instruction.
     * Returns the proposed text WITHOUT saving — the admin reviews/edits then
     * saves via the normal update.
     */
    public function regenerate(Request $request, EmailReadingDelivery $delivery): JsonResponse
    {
        $validated = $request->validate([
            'instruction' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $result = $this->generation->previewForDelivery($delivery, $validated['instruction'] ?? null);

            return response()->json([
                'success' => true,
                'content' => $result['content'],
                'model' => $result['model'],
                'completion_tokens' => $result['completion_tokens'],
                'problems' => $result['problems'],
                'is_valid' => $result['is_valid'],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Regeneration failed: '.$e->getMessage(),
            ], 422);
        }
    }

    public function sendNow(EmailReadingDelivery $delivery): RedirectResponse
    {
        $resend = $delivery->status === EmailReadingDelivery::STATUS_SENT;

        try {
            $this->service->sendNow($delivery, $resend);

            return back()->with('success', $resend ? 'Reading re-sent.' : 'Reading queued to send now.');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Failed to send reading: '.$e->getMessage());
        }
    }

    public function cancel(EmailReadingDelivery $delivery): RedirectResponse
    {
        try {
            if (! $this->service->cancel($delivery)) {
                return back()->with('error', 'Only pending, generated or failed readings can be cancelled.');
            }

            return back()->with('success', 'Reading cancelled. No email will be sent.');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reactivate(EmailReadingDelivery $delivery): RedirectResponse
    {
        try {
            $status = $this->service->reactivate($delivery);

            if ($status === null) {
                return back()->with('error', 'Only cancelled or failed readings can be reactivated.');
            }

            return back()->with('success', $status === EmailReadingDelivery::STATUS_PENDING
                ? 'Reading reactivated. Generation has been queued.'
                : 'Reading reactivated. It will send at its scheduled time.');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function approveCompliance(EmailReadingDelivery $delivery): RedirectResponse
    {
        try {
            if (! $this->service->approveComplianceHold($delivery)) {
                return back()->with('error', 'Only readings on compliance hold can be approved.');
            }

            return back()->with('success', 'Reading approved. Generation has been queued.');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function markSent(Request $request, EmailReadingDelivery $delivery): RedirectResponse
    {
        try {
            $this->service->markSentManually($delivery, ! $request->boolean('skip_fulfilment'));

            return back()->with('success', 'Reading marked as sent manually.');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function retryFulfillment(EmailReadingDelivery $delivery): RedirectResponse
    {
        try {
            $this->service->retryFulfillment($delivery);

            return back()->with('success', 'Fulfilment re-queued.');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(EmailReadingDelivery $delivery): RedirectResponse
    {
        if (! $this->service->delete($delivery)) {
            return back()->with('error', 'Cannot delete a reading that is being sent right now.');
        }

        return redirect()
            ->route('admin.email_readings.index')
            ->with('success', 'Reading deleted.');
    }

    public function export(Request $request): StreamedResponse
    {
        return $this->service->exportCsv($request->only(['status', 'search', 'from', 'to']));
    }
}
