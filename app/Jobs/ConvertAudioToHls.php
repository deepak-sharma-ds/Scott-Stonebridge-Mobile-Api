<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\Audio;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Exception;
use Illuminate\Support\Facades\File;

class ConvertAudioToHls implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $audioId;
    public string $sourceDisk;
    public string $sourcePath;

    public function __construct(int $audioId, string $sourceDisk = 'private', string $sourcePath)
    {
        $this->audioId = $audioId;
        $this->sourceDisk = $sourceDisk;
        $this->sourcePath = $sourcePath;
    }

    public function handle(): void
    {
        $audio = Audio::find($this->audioId);
        if (!$audio) {
            logger()->warning("Audio ID {$this->audioId} not found.");
            return;
        }

        // -------------------------------------------------------
        // 0️⃣ Validate source file exists
        // -------------------------------------------------------
        if (!Storage::disk($this->sourceDisk)->exists($this->sourcePath)) {
            logger()->warning("Source file missing for audio {$this->audioId}: {$this->sourcePath}");
            return;
        }

        // Get absolute source path
        $sourceAbs = Storage::disk($this->sourceDisk)->path($this->sourcePath);
        $audioId   = $audio->id;

        $outputRelative = "hls/{$audioId}";
        $outputDir = storage_path("app/private/{$outputRelative}");

        // if (!is_dir($outputDir)) {
        //     mkdir($outputDir, 0775, true);
        // }

        // -------------------------------------------------------
        // Ensure a clean output directory for this audio
        // -------------------------------------------------------
        if (is_dir($outputDir)) {
            // Remove any old files and subfolders (recursive cleanup)
            collect(scandir($outputDir))
                ->reject(fn($f) => in_array($f, ['.', '..']))
                ->each(function ($f) use ($outputDir) {
                    $path = $outputDir . DIRECTORY_SEPARATOR . $f;
                    if (is_dir($path)) {
                        File::deleteDirectory($path);
                    } else {
                        @unlink($path);
                    }
                });
        } else {
            mkdir($outputDir, 0775, true);
        }

        // Ensure writable permissions (for Linux)
        @chmod($outputDir, 0777);


        // Ensure writable on Linux
        @chmod($outputDir, 0777);

        // -------------------------------------------------------
        // 1️⃣ Generate AES encryption key
        // -------------------------------------------------------
        $keyPath = "{$outputDir}/enc.key";

        try {
            $keyBytes = function_exists('openssl_random_pseudo_bytes')
                ? openssl_random_pseudo_bytes(16)
                : random_bytes(16);

            file_put_contents($keyPath, $keyBytes);
        } catch (Exception $e) {
            logger()->error('Failed to generate AES key', [
                'audio' => $audioId,
                'err' => $e->getMessage(),
            ]);
            return;
        }

        // -------------------------------------------------------
        // 2️⃣ Create keyinfo.txt file for FFmpeg
        // -------------------------------------------------------
        $keyInfoPath = "{$outputDir}/keyinfo.txt";
        $keyUriPlaceholder = 'enc.keyuri';
        $iv = '00000000000000000000000000000001';

        file_put_contents($keyInfoPath, "{$keyUriPlaceholder}\n{$keyPath}\n{$iv}");

        // -------------------------------------------------------
        // 3️⃣ Prepare FFmpeg command dynamically (cross-platform)
        // -------------------------------------------------------
        // Load from .env or default to global ffmpeg
        $ffmpeg = config('env.FFMPEG_PATH');

        // Normalize all paths for FFmpeg
        $sourceAbsFfmpeg    = str_replace('\\', '/', $sourceAbs);
        $keyInfoPathFfmpeg  = str_replace('\\', '/', $keyInfoPath);
        $outputDirForFfmpeg = str_replace('\\', '/', $outputDir);

        $segmentPattern = "{$outputDirForFfmpeg}/segment_%05d.ts";
        $playlistPath   = "{$outputDirForFfmpeg}/playlist.m3u8";

        // -------------------------------------------------------
        // 4️⃣ Build the FFmpeg command
        // -------------------------------------------------------
        $cmd = "\"{$ffmpeg}\" -y -i \"{$sourceAbsFfmpeg}\" -vn -acodec aac -b:a 128k "
            . "-hls_time 6 -hls_playlist_type vod "
            . "-hls_key_info_file \"{$keyInfoPathFfmpeg}\" "
            . "-hls_segment_filename \"{$segmentPattern}\" \"{$playlistPath}\"";

        logger()->info("Starting FFmpeg conversion for audio #{$audioId}", ['cmd' => $cmd]);

        exec($cmd . ' 2>&1', $output, $returnVar);

        // -------------------------------------------------------
        // 5️⃣ Handle FFmpeg result
        // -------------------------------------------------------
        if ($returnVar !== 0 || !file_exists($playlistPath)) {
            logger()->error('FFMPEG conversion failed', [
                'audio' => $audioId,
                'cmd' => $cmd,
                'output' => $output,
            ]);

            $audio->update(['is_hls_ready' => false]);

            // Cleanup partial output
            if (is_dir($outputDir)) {
                collect(scandir($outputDir))
                    ->reject(fn($f) => in_array($f, ['.', '..']))
                    ->each(fn($f) => @unlink($outputDir . DIRECTORY_SEPARATOR . $f));
                @rmdir($outputDir);
            }

            return;
        }

        // -------------------------------------------------------
        // 6️⃣ Update DB to mark HLS ready
        // -------------------------------------------------------
        $audio->update([
            'hls_path' => $outputRelative,
            'is_hls_ready' => true,
        ]);

        logger()->info("FFmpeg conversion successful for audio #{$audioId}", [
            'output_dir' => $outputDir,
        ]);

        // -------------------------------------------------------
        // 7️⃣ Replace placeholder key URI inside playlist
        // -------------------------------------------------------
        if (file_exists($playlistPath)) {
            try {
                $playlist = file_get_contents($playlistPath);
                // $keyUrl = url("/admin/media/hls/{$audioId}/enc.key");
                $keyUrl = route('audio.stream', ['audio' => $audioId, 'file' => 'enc.key']);
                $playlist = str_replace('enc.keyuri', $keyUrl, $playlist);
                file_put_contents($playlistPath, $playlist);

                logger()->info("Replaced key URI placeholder for audio #{$audioId}", [
                    'key_url' => $keyUrl,
                ]);
            } catch (Exception $e) {
                logger()->warning('Failed to update playlist key URI', [
                    'audio' => $audioId,
                    'err' => $e->getMessage(),
                ]);
            }
        }

        // -------------------------------------------------------
        // 8️⃣ Optional — delete original file
        // -------------------------------------------------------
        try {
            // Uncomment if you want to delete the original source
            // Storage::disk($this->sourceDisk)->delete($this->sourcePath);
        } catch (Exception $e) {
            logger()->warning('Could not delete source MP3', [
                'audio' => $audioId,
                'err' => $e->getMessage(),
            ]);
        }
    }
}
