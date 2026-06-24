<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailReadingDeliveryStoreRequest;
use App\Http\Requests\EmailReadingDeliveryUpdateRequest;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Services\EmailReading\EmailReadingAdminService;
use App\Services\EmailReading\EmailReadingGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function update(EmailReadingDeliveryUpdateRequest $request, EmailReadingDelivery $delivery): RedirectResponse
    {
        try {
            $this->service->updateDelivery($delivery, $request->validated());

            return redirect()
                ->route('admin.email_readings.show', $delivery)
                ->with('success', 'Reading updated successfully.');
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
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Failed to send reading: '.$e->getMessage());
        }
    }

    public function cancel(EmailReadingDelivery $delivery): RedirectResponse
    {
        $this->service->cancel($delivery);

        return back()->with('success', 'Reading cancelled. No email will be sent.');
    }

    public function destroy(EmailReadingDelivery $delivery): RedirectResponse
    {
        $delivery->delete();

        return redirect()
            ->route('admin.email_readings.index')
            ->with('success', 'Reading deleted.');
    }

    public function export(Request $request): StreamedResponse
    {
        return $this->service->exportCsv($request->only(['status', 'search', 'from', 'to']));
    }
}
