<?php

namespace App\Repositories\Analytics;

use App\Models\ApiLog;
use App\Models\AudioDownloadLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LocalAnalyticsRepository
{
    public function countDownloads(\DateTimeInterface $from = null)
    {
        $q = AudioDownloadLog::query();
        if ($from) {
            $q->where('downloaded_at', '>=', $from);
        }
        return $q->count();
    }

    public function downloadsByAudioId(\DateTimeInterface $from = null)
    {
        $q = AudioDownloadLog::query();
        if ($from) $q->where('downloaded_at', '>=', $from);
        return $q->select('audio_id', DB::raw('count(*) as total'))
            ->groupBy('audio_id')->orderByDesc('total')->get();
    }

    public function activeUsersCount(\DateTimeInterface $from = null)
    {
        $q = ApiLog::query();
        if ($from) $q->where('created_at', '>=', $from);
        return $q->distinct('actor_id')->count('actor_id');
    }

    public function topSearches(\DateTimeInterface $from = null, $limit = 10)
    {
        $q = ApiLog::where('action', 'search');
        if ($from) $q->where('created_at', '>=', $from);
        return $q->selectRaw("json_unquote(json_extract(meta, '$.query')) as query, count(*) as total")
            ->groupBy('query')->orderByDesc('total')->limit($limit)->get();
    }

    public function wishlistTrends(\DateTimeInterface $from = null, $limit = 10)
    {
        $q = ApiLog::where('action', 'wishlist_add');
        if ($from) $q->where('created_at', '>=', $from);
        return $q->selectRaw("json_unquote(json_extract(meta, '$.product_id')) as product_id, count(*) as total")
            ->groupBy('product_id')->orderByDesc('total')->limit($limit)->get();
    }

    // bookings and audio subscription purchases aggregates are application-specific
    public function bookingsCount(\DateTimeInterface $from = null)
    {
        $q = DB::table('scheduled_meetings');
        if ($from) $q->where('created_at', '>=', $from);
        return $q->count();
    }

    public function audioSubscriptionPurchasesCount(\DateTimeInterface $from = null)
    {
        if (!Schema::hasTable('customer_entitlements')) {
            return 0;
        }
        $q = DB::table('customer_entitlements');
        if ($from) $q->where('created_at', '>=', $from);
        return $q->count();
    }
}
