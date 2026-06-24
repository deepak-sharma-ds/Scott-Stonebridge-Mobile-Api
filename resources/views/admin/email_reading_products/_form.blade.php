@php
    $p = $product ?? null;
    $initialSchema = old(
        'questions_schema',
        $p?->questions_schema ?? [['key' => '', 'label' => '', 'required' => true]],
    );
@endphp

<div x-data="productForm(@js(array_values($initialSchema)), @js(route('admin.email-reading-products.test')))">
    @csrf
    @if ($p)
        @method('PUT')
    @endif

    <div class="row" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
        <div class="mb-3">
            <label for="name" class="form-label">Name</label>
            <input type="text" name="name" id="name" class="form-control"
                value="{{ old('name', $p->name ?? '') }}" required>
        </div>
        <div class="mb-3">
            <label for="slug" class="form-label">Slug <small style="color:var(--text-muted);">(auto from name if
                    blank)</small></label>
            <input type="text" name="slug" id="slug" class="form-control"
                value="{{ old('slug', $p->slug ?? '') }}">
        </div>
    </div>

    <div class="row" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;">
        <div class="mb-3">
            <label for="shopify_product_id" class="form-label">Shopify Product ID</label>
            <input type="number" name="shopify_product_id" id="shopify_product_id" class="form-control"
                value="{{ old('shopify_product_id', $p->shopify_product_id ?? '') }}" required>
        </div>
        <div class="mb-3">
            <label for="model" class="form-label">OpenAI Model <small style="color:var(--text-muted);">(blank =
                    default)</small></label>
            <input type="text" name="model" id="model" class="form-control" x-model="model"
                value="{{ old('model', $p->model ?? '') }}" placeholder="{{ config('email_reading.openai_model') }}">
        </div>
        <div class="mb-3">
            <label for="max_tokens" class="form-label">Max Tokens</label>
            <input type="number" name="max_tokens" id="max_tokens" class="form-control" x-model.number="maxTokens"
                value="{{ old('max_tokens', $p->max_tokens ?? 1500) }}" min="1" max="8000">
        </div>
    </div>

    <div class="row" style="display:grid;grid-template-columns:2fr 1fr;gap:1rem;">
        <div class="mb-3">
            <label for="email_subject" class="form-label">Email Subject</label>
            <input type="text" name="email_subject" id="email_subject" class="form-control"
                value="{{ old('email_subject', $p->email_subject ?? '') }}" required>
        </div>
        <div class="mb-3">
            <label for="email_view" class="form-label">Email View <small style="color:var(--text-muted);">(blank =
                    default)</small></label>
            <input type="text" name="email_view" id="email_view" class="form-control"
                value="{{ old('email_view', $p->email_view ?? '') }}"
                placeholder="{{ config('email_reading.default_view') }}">
        </div>
    </div>

    <div class="mb-3">
        <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $p->is_active ?? true))>
            <span class="form-label" style="margin:0;">Active</span>
        </label>
    </div>

    {{-- Questions schema repeater --}}
    <div class="card p-4" style="border:1px solid var(--card-border);margin-bottom:1rem;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;">
            <h3 style="margin:0;font-size:1rem;">Question Slots</h3>
            <button type="button" class="btn btn-sm btn-primary" @click="addRow()">+ Add Slot</button>
        </div>
        <template x-for="(slot, i) in schema" :key="i">
            <div
                style="display:grid;grid-template-columns:1fr 2fr auto auto;gap:0.5rem;align-items:center;margin-bottom:0.5rem;">
                <input type="text" class="form-control" placeholder="key (e.g. future_q1)"
                    :name="'questions_schema[' + i + '][key]'" x-model="slot.key">
                <input type="text" class="form-control" placeholder="Customer-facing label"
                    :name="'questions_schema[' + i + '][label]'" x-model="slot.label">
                <label style="display:flex;align-items:center;gap:0.375rem;font-size:0.8125rem;white-space:nowrap;">
                    <input type="checkbox" value="1" :name="'questions_schema[' + i + '][required]'"
                        x-model="slot.required">
                    Required
                </label>
                <button type="button" class="btn btn-sm btn-danger" @click="removeRow(i)">&times;</button>
            </div>
        </template>
    </div>

    <div class="mb-3">
        <label for="prompt_template" class="form-label">Prompt Template</label>
        <textarea name="prompt_template" id="prompt_template" class="form-control" rows="10" x-model="promptTemplate"
            style="font-family:monospace;font-size:0.8125rem;" required>{{ old('prompt_template', $p->prompt_template ?? '') }}</textarea>
        {{-- <small style="color:var(--text-muted);display:block;margin-top:0.375rem;">
            Blade template. Available variables: <code>{{ '{{ $customer_name }}' }}</code>, each slot key
            (e.g. <code>{{ '{{ $future_q1 }}' }}</code>), and positional <code>{{ '{{ $q1 }}' }}</code>, <code>{{ '{{ $q2 }}' }}</code>…
        </small> --}}
        <small style="color:var(--text-muted);display:block;margin-top:0.375rem;">
            Blade template. Available variables:
            <code>@{{ $customer_name }}</code>,
            each slot key (e.g. <code>@{{ $future_q1 }}</code>),
            and positional <code>@{{ $q1 }}</code>,
            <code>@{{ $q2 }}</code>
        </small>
    </div>

    {{-- Live prompt test --}}
    <div class="card p-4"
        style="background:var(--color-primary-muted);border:1px solid var(--card-border);margin-bottom:1rem;">
        <h3 style="margin:0 0 0.75rem;font-size:1rem;">Test Prompt</h3>
        <div class="row" style="display:grid;grid-template-columns:1fr;gap:0.5rem;">
            <div>
                <label class="form-label" style="font-size:0.8125rem;">Sample customer name</label>
                <input type="text" class="form-control" x-model="testName" placeholder="Dear Friend">
            </div>
            <template x-for="(slot, i) in schema" :key="'t' + i">
                <div x-show="slot.key">
                    <label class="form-label" style="font-size:0.8125rem;" x-text="slot.label || slot.key"></label>
                    <input type="text" class="form-control" x-model="testAnswers[slot.key]">
                </div>
            </template>
        </div>
        <div style="margin:0.75rem 0;">
            <button type="button" class="btn btn-primary" @click="runTest()" :disabled="testLoading">
                <span x-show="!testLoading">▶ Run Test</span>
                <span x-show="testLoading">Generating…</span>
            </button>
            <span x-show="testInfo" x-text="testInfo"
                style="font-size:0.75rem;color:var(--text-muted);margin-left:0.5rem;"></span>
        </div>
        <div x-show="testOutput"
            style="white-space:pre-wrap;line-height:1.7;background:#fff;border:1px solid var(--card-border);border-radius:10px;padding:1rem;font-size:0.9375rem;"
            x-text="testOutput"></div>
    </div>

    <div class="mt-2" style="display:flex;gap:0.5rem;">
        <button type="submit" class="btn btn-success">Save Product</button>
        <a href="{{ route('admin.email-reading-products.index') }}" class="btn btn-secondary">Cancel</a>
    </div>
</div>

<script>
    function productForm(initialSchema, testUrl) {
        return {
            testUrl: testUrl,
            schema: (initialSchema && initialSchema.length) ?
                initialSchema.map(s => ({
                    key: s.key || '',
                    label: s.label || '',
                    required: !!s.required
                })) :
                [{
                    key: '',
                    label: '',
                    required: true
                }],
            promptTemplate: @js(old('prompt_template', $p->prompt_template ?? '')),
            model: @js(old('model', $p->model ?? '')),
            maxTokens: {{ (int) old('max_tokens', $p->max_tokens ?? 1500) }},
            testName: '',
            testAnswers: {},
            testOutput: '',
            testInfo: '',
            testLoading: false,
            addRow() {
                this.schema.push({
                    key: '',
                    label: '',
                    required: false
                });
            },
            removeRow(i) {
                this.schema.splice(i, 1);
            },
            async runTest() {
                this.testLoading = true;
                this.testInfo = '';
                try {
                    const res = await fetch(this.testUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({
                            prompt_template: this.promptTemplate,
                            model: this.model || null,
                            max_tokens: this.maxTokens || null,
                            customer_name: this.testName || null,
                            answers: this.testAnswers,
                        }),
                    });
                    const data = await res.json();
                    if (!res.ok || !data.success) {
                        throw new Error(data.message || 'Test failed.');
                    }
                    this.testOutput = data.content;
                    this.testInfo = (data.model || '') + ' · ' + (data.completion_tokens || 0) + ' tokens';
                } catch (e) {
                    this.testInfo = e.message;
                    if (window.adminNotify) window.adminNotify(e.message, 'error');
                } finally {
                    this.testLoading = false;
                }
            },
        };
    }
</script>
