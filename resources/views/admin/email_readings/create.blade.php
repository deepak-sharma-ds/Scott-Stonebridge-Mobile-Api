@extends('admin.layouts.app')

@section('page-title', 'New Reading')

@php
    $productMap = $products->mapWithKeys(fn ($p) => [
        $p->id => [
            'name' => $p->name,
            'schema' => (array) $p->questions_schema,
        ],
    ]);
@endphp

@section('content')
<div class="container-fluid">

    @include('admin.components.page-header', [
        'title'    => 'New Reading (Manual)',
        'subtitle' => 'Create a reading delivery without a Shopify order. Generation runs after save.',
        'action'   => '<a href="' . route('admin.email_readings.index') . '" class="btn btn-secondary">← Back</a>',
    ])

    @if($errors->any())
        <div class="alert alert-danger">
            <ul style="margin:0;padding-left:1.25rem;">
                @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('admin.email_readings.store') }}" method="POST" class="card p-4"
          x-data="manualReading(@js($productMap), @js((int) old('email_reading_product_id')))">
        @csrf

        <div class="row" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div class="mb-3">
                <label for="email_reading_product_id" class="form-label">Reading Product</label>
                <select name="email_reading_product_id" id="email_reading_product_id" class="form-control"
                        x-model.number="productId" @change="syncSchema()" required>
                    <option value="">— Select a product —</option>
                    @foreach($products as $p)
                        <option value="{{ $p->id }}" @selected((int) old('email_reading_product_id') === $p->id)>
                            {{ $p->name }} @unless($p->is_active) (inactive) @endunless
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="mb-3">
                <label for="scheduled_at" class="form-label">Scheduled At (optional — blank sends asap)</label>
                <input type="datetime-local" name="scheduled_at" id="scheduled_at" class="form-control"
                       value="{{ old('scheduled_at') }}">
            </div>
        </div>

        <div class="row" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div class="mb-3">
                <label for="customer_email" class="form-label">Customer Email</label>
                <input type="email" name="customer_email" id="customer_email" class="form-control"
                       value="{{ old('customer_email') }}" required>
            </div>
            <div class="mb-3">
                <label for="customer_name" class="form-label">Customer Name</label>
                <input type="text" name="customer_name" id="customer_name" class="form-control"
                       value="{{ old('customer_name') }}">
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Questions / Answers</label>
            <template x-if="schema.length === 0">
                <p style="color:var(--text-muted);margin:0;">Select a product to load its question fields.</p>
            </template>
            <div style="display:flex;flex-direction:column;gap:0.75rem;">
                <template x-for="(slot, i) in schema" :key="i">
                    <div>
                        <label class="form-label" style="font-size:0.8125rem;" x-text="slot.label || slot.key"></label>
                        <textarea class="form-control" rows="2"
                                  :name="'answers[' + slot.key + ']'"></textarea>
                    </div>
                </template>
            </div>
        </div>

        <div class="mt-2" style="display:flex;gap:0.5rem;">
            <button type="submit" class="btn btn-success">Create &amp; Generate</button>
            <a href="{{ route('admin.email_readings.index') }}" class="btn btn-secondary">Cancel</a>
        </div>
    </form>

</div>
@endsection

@section('custom_js_scripts')
<script>
    function manualReading(productMap, initialId) {
        return {
            productMap: productMap || {},
            productId: initialId || '',
            schema: [],
            init() { this.syncSchema(); },
            syncSchema() {
                const p = this.productMap[this.productId];
                this.schema = p ? (p.schema || []) : [];
            },
        };
    }
</script>
@endsection
