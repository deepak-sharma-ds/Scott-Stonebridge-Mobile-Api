<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudioDownloadLog extends Model
{
    public $timestamps = false;
    protected $table = 'audio_download_logs';
    protected $fillable = ['audio_id', 'customer_id', 'source', 'downloaded_at', 'ip', 'meta'];
    protected $casts = ['meta' => 'array', 'downloaded_at' => 'datetime'];
}
