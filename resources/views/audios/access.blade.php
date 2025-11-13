@extends('admin.layouts.app')
@section('content')
    <div class="container">
        <h2>{{ $package->title }}</h2>
        <p>{{ $package->description }}</p>

        @foreach ($audios as $a)
            <div class="mb-4">
                <h5>{{ $a['title'] }}</h5>
                <video id="player-{{ $a['id'] }}" controls controlsList="nodownload" crossorigin="use-credentials"
                    style="width:100%;max-width:640px;"></video>
                <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
                <script>
                    (function() {
                        const src = "{{ $a['playlist_url'] }}";
                        const video = document.getElementById('player-{{ $a['id'] }}');
                        if (Hls.isSupported()) {
                            const hls = new Hls();
                            hls.loadSource(src);
                            hls.attachMedia(video);
                        } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                            video.src = src;
                        }
                    })
                    ();
                </script>
            </div>
        @endforeach
    </div>
@endsection
