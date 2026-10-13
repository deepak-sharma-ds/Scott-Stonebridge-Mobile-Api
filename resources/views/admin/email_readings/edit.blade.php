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

    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(460px, 1fr));gap:1.5rem;align-items:start;">
        <form action="{{ route('admin.email_readings.update', $delivery) }}" method="POST" class="card p-4">
            @include('admin.email_readings._form', ['delivery' => $delivery, 'statuses' => $statuses])
        </form>

        @include('admin.components.email-preview', [
            'previewUrl' => route('admin.email_readings.preview', $delivery),
            'editable' => true,
            'isReading' => true,
            'title' => 'Live Email Preview',
        ])
    </div>

</div>
@endsection

@section('custom_js_scripts')
@include('admin.components.rich-text-editor-scripts')
<script>
    function readingRegenerator(url) {
        return {
            url: url,
            instruction: '',
            loading: false,
            info: '',
            problems: [],
            async regenerate() {
                this.loading = true;
                this.info = '';
                this.problems = [];
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
                    const field = document.getElementById('ai_response');
                    if (field.ckEditorInstance) {
                        field.ckEditorInstance.setData(data.content);
                    } else {
                        field.value = data.content;
                    }
                    this.problems = Array.isArray(data.problems) ? data.problems : [];
                    this.info = 'Preview generated · ' + (data.model || '') + ' · ' + (data.completion_tokens || 0) + ' tokens';
                    window.dispatchEvent(new CustomEvent('email-preview-refresh'));
                    if (this.problems.length > 0) {
                        if (window.adminNotify) window.adminNotify('Response regenerated with validation issues — please review before saving.', 'warning');
                    } else {
                        if (window.adminNotify) window.adminNotify('Response regenerated — review and Save to persist.', 'success');
                    }
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
