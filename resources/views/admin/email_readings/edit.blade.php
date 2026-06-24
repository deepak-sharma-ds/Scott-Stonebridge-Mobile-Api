@extends('admin.layouts.app')

@section('page-title', 'Edit Reading')

@section('content')
<div class="container-fluid">

    @include('admin.components.page-header', [
        'title'    => 'Edit Reading #' . $delivery->id,
        'subtitle' => $delivery->product?->name ?? 'Reading delivery',
    ])

    @if($errors->any())
        <div class="alert alert-danger">
            <ul style="margin:0;padding-left:1.25rem;">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('admin.email_readings.update', $delivery) }}" method="POST" class="card p-4">
        @include('admin.email_readings._form', ['delivery' => $delivery, 'statuses' => $statuses])
    </form>

</div>
@endsection

@section('custom_js_scripts')
<script>
    function readingRegenerator(url, initial) {
        return {
            url: url,
            instruction: '',
            response: initial || '',
            loading: false,
            info: '',
            async regenerate() {
                this.loading = true;
                this.info = '';
                try {
                    const res = await fetch(this.url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({ instruction: this.instruction }),
                    });
                    const data = await res.json();
                    if (!res.ok || !data.success) {
                        throw new Error(data.message || 'Regeneration failed.');
                    }
                    this.response = data.content;
                    this.info = 'Preview generated · ' + (data.model || '') + ' · ' + (data.completion_tokens || 0) + ' tokens';
                    if (window.adminNotify) window.adminNotify('Response regenerated — review and Save to persist.', 'success');
                } catch (e) {
                    this.info = e.message;
                    if (window.adminNotify) window.adminNotify(e.message, 'error');
                } finally {
                    this.loading = false;
                }
            },
        };
    }
</script>
@endsection
