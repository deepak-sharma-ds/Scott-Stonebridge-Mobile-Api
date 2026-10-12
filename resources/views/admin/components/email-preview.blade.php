@php
    $editable = $editable ?? false;
    $isReading = $isReading ?? false;
    $title = $title ?? 'Customer Email Preview';
@endphp

<div x-data="emailPreviewBox({
    previewUrl: @js($previewUrl),
    editable: @js((bool) $editable),
    isReading: @js((bool) $isReading),
})" @email-preview-refresh.window="refreshPreview()" class="card p-4 email-preview-card">
    <div class="email-preview-toolbar" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:0.5rem;">
        <div style="display:flex;align-items:center;gap:0.5rem;">
            <h3 style="margin:0;font-size:1rem;">{{ $title }}</h3>
            <span style="font-size:0.75rem;color:var(--text-muted);">(Real Mailable render)</span>
        </div>

        <div style="display:flex;gap:0.5rem;align-items:center;">
            @if($editable)
                <button type="button" class="btn btn-sm btn-secondary" @click="refreshPreview()" :disabled="loading">
                    <span x-show="!loading">↻ Refresh Preview</span>
                    <span x-show="loading" x-cloak>Refreshing…</span>
                </button>
            @endif

            <div class="btn-group btn-group-sm" role="group" aria-label="Device view toggle">
                <button type="button" class="btn btn-sm"
                    :class="mode === 'desktop' ? 'btn-primary' : 'btn-outline-primary'"
                    @click="mode = 'desktop'">
                    Desktop (640px)
                </button>
                <button type="button" class="btn btn-sm"
                    :class="mode === 'mobile' ? 'btn-primary' : 'btn-outline-primary'"
                    @click="mode = 'mobile'">
                    Mobile (375px)
                </button>
            </div>
        </div>
    </div>

    <div class="email-preview-stage" style="display:flex;justify-content:center;background:#f8fafc;padding:1.25rem;border-radius:8px;border:1px solid var(--card-border);overflow:auto;">
        <div :style="mode === 'desktop' ? 'width:100%;max-width:680px;display:flex;justify-content:center;' : 'width:375px;max-width:375px;display:flex;justify-content:center;box-shadow:0 0 0 8px #1e293b, var(--shadow-lg);border-radius:20px;overflow:hidden;'">
            <iframe
                x-ref="iframe"
                src="{{ $previewUrl }}"
                sandbox="allow-same-origin"
                :style="mode === 'desktop'
                    ? 'width:100%;height:720px;border:1px solid var(--card-border);border-radius:8px;background:#ffffff;box-shadow:var(--shadow-sm);'
                    : 'width:375px;height:667px;border:none;background:#ffffff;'"
                title="Email Preview">
            </iframe>
        </div>
    </div>
</div>

<script>
    if (typeof window.emailPreviewBox === 'undefined') {
        window.emailPreviewBox = function(config) {
            return {
                previewUrl: config.previewUrl,
                editable: Boolean(config.editable),
                isReading: Boolean(config.isReading),
                mode: 'desktop',
                loading: false,

                init() {
                    if (this.editable) {
                        window.addEventListener('email-preview-refresh', () => {
                            this.refreshPreview();
                        });
                        window.addEventListener('reading-preview-refresh', () => {
                            this.refreshPreview();
                        });
                    }
                },

                async refreshPreview() {
                    if (!this.editable) return;
                    this.loading = true;

                    try {
                        const payload = {};
                        const nameEl = document.getElementById('customer_name');
                        if (nameEl) payload.customer_name = nameEl.value;

                        const emailEl = document.getElementById('customer_email');
                        if (emailEl) payload.customer_email = emailEl.value;

                        if (this.isReading) {
                            const aiEl = document.getElementById('ai_response');
                            if (aiEl) {
                                payload.ai_response = aiEl.ckEditorInstance ? aiEl.ckEditorInstance.getData() : aiEl.value;
                            }
                        } else {
                            const prodEl = document.getElementById('campaign_product_id');
                            if (prodEl) payload.campaign_product_id = prodEl.value;
                        }

                        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
                        const res = await fetch(this.previewUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'text/html',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            body: JSON.stringify(payload),
                        });

                        if (!res.ok) {
                            throw new Error('Preview failed to load.');
                        }

                        const html = await res.text();
                        if (this.$refs.iframe) {
                            this.$refs.iframe.srcdoc = html;
                        }
                    } catch (err) {
                        if (window.adminNotify) {
                            window.adminNotify(err.message || 'Failed to refresh preview.', 'error');
                        }
                    } finally {
                        this.loading = false;
                    }
                }
            };
        };
    }
</script>
