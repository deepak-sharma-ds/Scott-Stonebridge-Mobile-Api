<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AudioDownloadLog;
use App\Repositories\Analytics\LocalAnalyticsRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportingController extends Controller
{
    protected $localRepo;

    public function __construct(LocalAnalyticsRepository $localRepo)
    {
        // $this->middleware('auth:admin');
        $this->localRepo = $localRepo;
    }

    // example: export searches
    public function exportSearches(Request $request): StreamedResponse
    {
        $from = $request->query('from') ? Carbon::parse($request->query('from')) : null;
        $rows = $this->localRepo->topSearches($from, 10000);

        $filename = 'searches_export_' . now()->format('Ymd_His') . '.csv';
        $response = new StreamedResponse(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['query', 'total']);
            foreach ($rows as $r) {
                fputcsv($handle, [(string)$r->query, $r->total]);
            }
            fclose($handle);
        }, 200, [
            "Content-Type" => "text/csv",
            "Content-Disposition" => "attachment; filename={$filename}",
        ]);

        return $response;
    }

    // export downloads
    public function exportDownloads(Request $request): StreamedResponse
    {
        $from = $request->query('from') ? Carbon::parse($request->query('from')) : null;
        $rows = AudioDownloadLog::when($from, fn($q) => $q->where('downloaded_at', '>=', $from))->get();

        $filename = 'downloads_export_' . now()->format('Ymd_His') . '.csv';
        return new StreamedResponse(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['id', 'audio_id', 'customer_id', 'downloaded_at', 'source']);
            foreach ($rows as $r) {
                fputcsv($handle, [(string)$r->id, $r->audio_id, $r->customer_id, $r->downloaded_at, $r->source]);
            }
            fclose($handle);
        }, 200, [
            "Content-Type" => "text/csv",
            "Content-Disposition" => "attachment; filename={$filename}",
        ]);
    }

    // generic report: combine shopify + local orders (if needed)
    public function salesReport(Request $request)
    {
        // Optionally call $this->shopify->getOrdersBetween(...) and merge with local audio purchases/bookings
        // For now, return placeholders / delegate to AnalyticsService if needed.
        return response()->json(['message' => 'Implement per business rules.']);
    }
}
