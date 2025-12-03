@extends('admin.layouts.app')
@section('content')
    <div class="container">
        <h2>{{ $package->title }}</h2>
        <p>{{ $package->description }}</p>

        @foreach ($audios as $a)
            @php
                $payload = [
                    'audio' => $a,
                    'customer_id' => $customerId ?? null,
                    'package_tag' => $package->shopify_tag,
                ];
            @endphp

            <div class="mb-4">
                <h5>{{ $a['title'] }}</h5>

                <video id="player-{{ $a['id'] }}" controls controlsList="nodownload" style="width:100%;max-width:640px;">
                </video>
                @if ($a['download_url'])
                    <a href="{{ $a['download_url'] }}" class="btn btn-success" download>
                        Download Audio
                    </a>
                @endif

                {{-- Queue initialization (executed later when initPlayer exists) --}}
                <script>
                    window.__hls_pending = window.__hls_pending || [];
                    window.__hls_pending.push({
                        payload: @json($payload),
                        elementId: "player-{{ $a['id'] }}"
                    });
                </script>
            </div>
        @endforeach
    </div>
@endsection


@section('custom_js_scripts')
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>

    <script>
        /* ============================================================
                   PLAYBACK ENGINE (initPlayer)
                   ============================================================ */
        function initPlayer(payload, videoElementId) {
            const audioItem = payload.audio;
            const customerId = payload.customer_id;
            const packageTag = payload.package_tag;

            const video = document.getElementById(videoElementId);
            if (!video) {
                console.error("Video element not found:", videoElementId);
                return;
            }

            // HLS Support
            if (Hls.isSupported()) {

                const hls = new Hls();
                hls.loadSource(audioItem.playlist_url);
                hls.attachMedia(video);

                hls.on(Hls.Events.MANIFEST_PARSED, function() {
                    if (audioItem.last_position_seconds > 2) {
                        video.currentTime = audioItem.last_position_seconds;
                    }
                });

                // Save progress periodically
                let saveInterval = null;

                const saveProgress = () => {
                    if (!customerId || !audioItem.id) return;
                    fetch('/api/shopify/audio-save-progress', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({
                            customer_id: customerId,
                            audio_id: audioItem.id,
                            package_tag: packageTag,
                            position: video.currentTime || 0
                        })
                    }).catch(() => {});
                };

                video.addEventListener('play', () => {
                    if (!saveInterval) saveInterval = setInterval(saveProgress, 5000);
                });

                video.addEventListener('pause', () => {
                    saveProgress();
                    if (saveInterval) {
                        clearInterval(saveInterval);
                        saveInterval = null;
                    }
                });

                window.addEventListener('beforeunload', saveProgress);

            } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                console.log('dsfgdfsgs');

                // Safari native HLS
                video.src = audioItem.playlist_url;
                if (audioItem.last_position_seconds > 2) {
                    video.currentTime = audioItem.last_position_seconds;
                }
            }
        }

        /* ============================================================
           PROCESS QUEUED PLAYERS (Fix: initPlayer must exist first)
           ============================================================ */
        window.__hls_pending = window.__hls_pending || [];
        window.__hls_pending.forEach(item => {
            try {
                initPlayer(item.payload, item.elementId);
            } catch (e) {
                console.error("Failed to init queued player", item.elementId, e);
            }
        });
        window.__hls_pending = [];
    </script>
@endsection
